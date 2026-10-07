<?php

namespace Disintegrations\EitaaSerializer;

use Illuminate\Support\Facades\Http;
use Disintegrations\EitaaSerializer\TL\TlDeserializer;
use Disintegrations\EitaaSerializer\TL\TlSchema;
use Disintegrations\EitaaSerializer\TL\TlSerializer;
use Disintegrations\EitaaSerializer\Exceptions\EitaaRpcException;
use InvalidArgumentException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use GuzzleHttp\Psr7\Response as PsrResponse;

class EitaaGatewayClient
{
    private ?TlSchema $resolvedSchema = null;

    public function __construct(
        ?TlSchema $schema = null,
        private ?string $endpoint = null,
        private ?int $layer = null,
        private ?string $defaultImei = null,
        private ?int $timeout = null,
        private ?int $envelopeFlags = null,
        private ?string $uploadEndpoint = null,
        private ?int $uploadLayer = null,
        private ?int $uploadEnvelopeFlags = null,
        private ?bool $legacyEnvelope = null,
    ) {
        $this->resolvedSchema = $schema;
    }

    public function send(string $method, array $params = [], ?string $token = null, ?string $imei = null): mixed
    {
        $serializer = new TlSerializer($this->getSchema(), false);
        $resultType = $serializer->storeMethod($method, $params);

        return $this->request($serializer->bytes(), $token, $imei, $resultType);
    }

    /** Use one selected endpoint for parts and operations referencing fresh uploads. */
    public function sendUpload(string $method, array $params = [], ?string $token = null, ?string $imei = null): mixed
    {
        if (! in_array($method, [
            'upload.saveFilePart', 'upload.saveBigFilePart', 'messages.sendMedia',
            'messages.uploadMedia', 'messages.sendMultiMedia', 'messages.editMessage',
            'messages.editChatPhoto', 'channels.editPhoto', 'photos.uploadProfilePhoto',
        ], true)) {
            throw new InvalidArgumentException('Method is not a supported upload operation.');
        }

        $serializer = new TlSerializer($this->getSchema(), false);
        $resultType = $serializer->storeMethod($method, $params);

        return $this->request($serializer->bytes(), $token, $imei, $resultType, true);
    }

    /** Snapshot routing before starting a multipart transaction. No automatic failover. */
    public function forUpload(): self
    {
        $client = clone $this;
        $client->uploadEndpoint ??= config('eitaa.upload_endpoint', 'https://alzheimer.eitaa.com/eitaa/');
        $client->uploadLayer ??= (int) config('eitaa.upload_layer', 135);
        $client->uploadEnvelopeFlags ??= (int) config('eitaa.upload_envelope_flags', 128);

        return $client;
    }

    public function sendFile(
        string $path,
        array $peer,
        string $randomId,
        string $filename,
        string $mimeType,
        string $caption = '',
        bool $photo = false,
        ?string $token = null,
        ?string $imei = null,
    ): mixed
    {
        return (new EitaaMediaUploader($this))->sendFile(
            $path, $peer, $randomId, $filename, $mimeType, $caption, $photo, $token, $imei);
    }

    public function sendPackedData(string $packedData, ?string $token = null, ?string $imei = null, bool $expectVector = false): mixed
    {
        return $this->request($packedData, $token, $imei, $expectVector ? 'Vector<Object>' : '');
    }

    private function request(string $packedData, ?string $token, ?string $imei, string $type, bool $upload = false): mixed
    {
        $body = $upload
            ? $this->serializeEnvelope($packedData, $token, $imei,
                $this->uploadLayer ?? (int) config('eitaa.upload_layer', 135),
                $this->uploadEnvelopeFlags ?? (int) config('eitaa.upload_envelope_flags', 128), false)
            : $this->wrapRequest($packedData, $token, $imei);

        $response = Http::timeout($this->getTimeout())
            ->withBody($body, 'application/octet-stream')
            ->post($upload ? ($this->uploadEndpoint ?? config('eitaa.upload_endpoint', 'https://alzheimer.eitaa.com/eitaa/')) : $this->getEndpoint());

        if ($response->failed()) {
            // Keep Laravel's transport exception type/status without provider body or headers.
            throw new RequestException(new Response(new PsrResponse($response->status())));
        }

        // Scalars and objects are boxed on the wire; only vectors need item typing.
        return $this->parseResponse($response->body(), str_starts_with($type, 'Vector') ? $type : '');
    }

    public function wrapRequest(string $packedData, ?string $token = null, ?string $imei = null): string
    {
        return $this->serializeEnvelope($packedData, $token, $imei, $this->getLayer(),
            $this->envelopeFlags ?? (int) config('eitaa.envelope_flags', 0),
            $this->legacyEnvelope ?? (bool) config('eitaa.legacy_envelope', false));
    }

    private function serializeEnvelope(string $packedData, ?string $token, ?string $imei, int $layer, int $flags, bool $legacy): string
    {
        try {
            $definition = $this->getSchema()->method('API', 'eitaaObject');
        } catch (InvalidArgumentException) {
            $definition = $this->getSchema()->constructorByPredicate('API', 'eitaaObject');
        }

        $base = [
            ['name' => 'token', 'type' => 'string'], ['name' => 'imei', 'type' => 'string'],
            ['name' => 'packed_data', 'type' => 'bytes'], ['name' => 'layer', 'type' => 'int'],
        ];
        if ((int) $definition['id'] !== 2059302893 ||
            ($definition['params'] !== $base && $definition['params'] !== [...$base, ['name' => 'flags', 'type' => 'int']])) {
            throw new InvalidArgumentException('Unsupported eitaaObject envelope layout.');
        }

        // Normalize older published schemas and constructor-only representations locally.
        $definition['method'] = 'eitaaObject';
        $definition['params'] = $legacy ? $base : [...$base, ['name' => 'flags', 'type' => 'int']];
        $serializer = new TlSerializer(new TlSchema(['API' => ['methods' => [$definition]]]), false);
        $serializer->storeMethod('eitaaObject', [
            'token' => $token ?? '',
            'imei' => $imei ?? $this->getDefaultImei(),
            'packed_data' => $packedData,
            'layer' => $layer,
            'flags' => $flags,
        ]);

        return $serializer->bytes();
    }

    public function parseResponse(string $responseBody, string $type = ''): mixed
    {
        for ($depth = 0; $depth < 16; $depth++) {
            $deserializer = new TlDeserializer($this->getSchema(), $responseBody, true);
            $response = $deserializer->fetchObject($type);
            $deserializer->fetchEnd();

            for ($wrapped = 0; is_array($response) && ($response['_'] ?? null) === 'rpc_result'; $wrapped++) {
                if ($wrapped >= 16) {
                    throw new \RuntimeException('Too many nested RPC result envelopes.');
                }
                $response = $response['result'];
            }

            if (is_array($response) && in_array($response['_'] ?? null, ['error', 'rpc_error'], true)) {
                throw new EitaaRpcException((int) ($response['code'] ?? $response['error_code']), $response['_']);
            }
            if (! is_array($response) || ! isset($response['packed_data']) || ! is_string($response['packed_data'])) {
                return $response;
            }
            $responseBody = $response['packed_data'];
        }

        throw new \RuntimeException('Too many nested Eitaa response envelopes.');
    }

    private function getSchema(): TlSchema
    {
        return $this->resolvedSchema ??= new TlSchema();
    }

    private function getEndpoint(): string
    {
        return $this->endpoint ?? config('eitaa.endpoint', 'https://sajad.eitaa.ir/eitaa/');
    }

    private function getLayer(): int
    {
        return $this->layer ?? (int) config('eitaa.layer', 133);
    }

    private function getDefaultImei(): string
    {
        return $this->defaultImei ?? config('eitaa.default_imei', '00__web');
    }

    private function getTimeout(): int
    {
        return $this->timeout ?? (int) config('eitaa.timeout', 30);
    }
}
