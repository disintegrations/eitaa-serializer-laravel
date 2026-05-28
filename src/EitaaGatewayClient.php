<?php

namespace Disintegrations\EitaaSerializer;

use Illuminate\Support\Facades\Http;
use Disintegrations\EitaaSerializer\TL\TlDeserializer;
use Disintegrations\EitaaSerializer\TL\TlSchema;
use Disintegrations\EitaaSerializer\TL\TlSerializer;

class EitaaGatewayClient
{
    private ?TlSchema $resolvedSchema = null;

    public function __construct(
        ?TlSchema $schema = null,
        private ?string $endpoint = null,
        private ?int $layer = null,
        private ?string $defaultImei = null,
        private ?int $timeout = null,
    ) {
        $this->resolvedSchema = $schema;
    }

    public function send(string $method, array $params = [], ?string $token = null, ?string $imei = null): mixed
    {
        $serializer = new TlSerializer($this->getSchema(), false);
        $serializer->storeMethod($method, $params);

        return $this->sendPackedData($serializer->bytes(), $token, $imei);
    }

    public function sendPackedData(string $packedData, ?string $token = null, ?string $imei = null, bool $expectVector = false): mixed
    {
        $body = $this->wrapRequest($packedData, $token, $imei);

        $response = Http::timeout($this->getTimeout())
            ->withBody($body, 'application/octet-stream')
            ->post($this->getEndpoint())
            ->throw();

        return $this->parseResponse($response->body(), $expectVector ? 'Vector' : '');
    }

    public function wrapRequest(string $packedData, ?string $token = null, ?string $imei = null): string
    {
        $serializer = new TlSerializer($this->getSchema(), false);
        $serializer->storeMethod('eitaaObject', [
            'token' => $token ?? '',
            'imei' => $imei ?? $this->getDefaultImei(),
            'packed_data' => $packedData,
            'layer' => $this->getLayer(),
        ]);

        return $serializer->bytes();
    }

    public function parseResponse(string $responseBody, string $type = ''): mixed
    {
        $deserializer = new TlDeserializer($this->getSchema(), $responseBody, true);
        $response = $deserializer->fetchObject($type);

        if (is_array($response) && isset($response['packed_data']) && is_string($response['packed_data'])) {
            return (new TlDeserializer($this->getSchema(), $response['packed_data'], true))->fetchObject('');
        }

        return $response;
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

