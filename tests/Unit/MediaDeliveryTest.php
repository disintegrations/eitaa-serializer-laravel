<?php

use Disintegrations\EitaaSerializer\EitaaGatewayClient;
use Disintegrations\EitaaSerializer\EitaaSentMessage;
use Disintegrations\EitaaSerializer\Exceptions\EitaaRpcException;
use Disintegrations\EitaaSerializer\Exceptions\EitaaUploadException;
use Disintegrations\EitaaSerializer\Facades\Eitaa;
use Disintegrations\EitaaSerializer\TL\TlDeserializer;
use Disintegrations\EitaaSerializer\TL\TlSchema;
use Disintegrations\EitaaSerializer\TL\TlSerializer;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

function mediaTl(array $value, bool $mtproto = false): string
{
    $serializer = new TlSerializer(eitaaSchema(), $mtproto);
    $serializer->storeObject($value, 'Object');

    return $serializer->bytes();
}

function mediaRequest($request): array
{
    $decoder = new TlDeserializer(eitaaSchema(), $request->body());
    $envelope = $decoder->fetchObject('');
    $decoder->fetchEnd();
    $decoder = new TlDeserializer(eitaaSchema(), $envelope['packed_data']);
    $method = $decoder->fetchObject('');
    $decoder->fetchEnd();

    return [$envelope, $method];
}

it('writes exact envelope bytes and upgrades old method or constructor schemas once', function (bool $constructor, bool $hasFlags): void {
    $params = [['name' => 'token', 'type' => 'string'], ['name' => 'imei', 'type' => 'string'],
        ['name' => 'packed_data', 'type' => 'bytes'], ['name' => 'layer', 'type' => 'int']];
    if ($hasFlags) {
        $params[] = ['name' => 'flags', 'type' => 'int'];
    }
    $schema = new TlSchema(['API' => [$constructor ? 'constructors' : 'methods' => [
        ['id' => 2059302893, $constructor ? 'predicate' : 'method' => 'eitaaObject', 'params' => $params, 'type' => 'EitaaObject'],
    ]]]);
    $client = new EitaaGatewayClient(schema: $schema, layer: 135, envelopeFlags: 128);
    $expected = pack('V', 2059302893).tlLengthPrefixedBytes('token').tlLengthPrefixedBytes('imei')
        .tlLengthPrefixedBytes('data').pack('V2', 135, 128);
    expect($client->wrapRequest('data', 'token', 'imei'))->toBe($expected);
    $legacy = new EitaaGatewayClient(schema: $schema, layer: 133, legacyEnvelope: true);
    expect($legacy->wrapRequest('data', 'token', 'imei'))->toBe(substr($expected, 0, -8).pack('V', 133));
})->with([[false, false], [false, true], [true, false], [true, true]]);

it('decodes a modern user nested in synthetic send updates without choosing it for legacy encoding', function (): void {
    $bytes = hex2bin(trim(file_get_contents(__DIR__.'/../Fixtures/send-updates-layer-135.hex')));
    $response = app(EitaaGatewayClient::class)->parseResponse($bytes);
    expect(EitaaSentMessage::id($response, '42'))->toBe('101')
        ->and($response['users'][0]['id'])->toBe('9007199254740993')
        ->and($response['users'][0]['access_hash'])->toBe('-9223372036854775807')
        ->and($response['users'][0]['status']['_'])->toBe('userStatusOnline')
        ->and($response['users'][0]['badge_name'])->toBe('test badge')
        ->and($response['users'][0]['bot_active_users'])->toBe(12)
        ->and($response['users'][0]['pFlags'])->toMatchArray(['self' => true, 'miniApp' => true, 'badge_red_color' => true, 'miniAppGeo' => true])
        ->and(eitaaSchema()->constructorByPredicate('API', 'user')['id'])->toBe(1073147056);
    $legacy = mediaTl(['_' => 'user', 'flags' => 2, 'id' => '123', 'first_name' => 'Legacy']);
    expect(app(EitaaGatewayClient::class)->parseResponse($legacy)['first_name'])->toBe('Legacy');
    $minimal = pack('V4', -321753653, 0, 0, 0).pack('V2', 123, 0);
    expect(app(EitaaGatewayClient::class)->parseResponse($minimal))->toMatchArray(['id' => '123', 'flags2' => 0, 'eFlags' => 0]);
});

it('does not mistake nested users or unknown updates for sent messages', function (): void {
    expect(EitaaSentMessage::id(['_' => 'updates', 'users' => [['_' => 'user', 'id' => '123']]]))->toBeNull()
        ->and(EitaaSentMessage::id(['_' => 'error', 'id' => '123']))->toBeNull()
        ->and(EitaaSentMessage::id(['_' => 'updates', 'updates' => [['_' => 'updateMessageID', 'id' => 3, 'random_id' => '2']]], '1'))->toBeNull();
});

it('throws sanitized native and RPC errors through wrappers gzip and vector expectations', function (string $kind, bool $wrapped, bool $gzip, bool $vector): void {
    $error = $kind === 'error'
        ? ['_' => 'error', 'code' => 500, 'text' => 'INTERNAL_SERVER_ERROR10 filename: /private/token-imei-bytes-hash']
        : ['_' => 'rpc_error', 'error_code' => 429, 'error_message' => '/private/token-imei-bytes-hash'];
    $bytes = mediaTl($error, $kind === 'rpc_error');
    // Raw decoding remains available.
    expect((new TlDeserializer(eitaaSchema(), $bytes, true))->fetchObject('')['_'])->toBe($kind);
    $client = app(EitaaGatewayClient::class);
    if ($wrapped) {
        $bytes = $client->wrapRequest($client->wrapRequest($bytes));
    }
    if ($gzip) {
        $bytes = pack('V', 0x3072cfa1).tlLengthPrefixedBytes(gzencode($bytes));
    }
    try {
        $client->parseResponse($bytes, $vector ? 'Vector<int>' : '');
        $this->fail('Expected an RPC exception.');
    } catch (EitaaRpcException $exception) {
        expect($exception->rpcCode)->toBe($kind === 'error' ? 500 : 429)
            ->and($exception->classification())->toBe($kind === 'error' ? 'transient' : 'rate_limit')
            ->and($exception->getMessage())->not->toContain('private', 'token', 'imei', 'bytes', 'hash');
    }
})->with(['error', 'rpc_error'])->with([false, true])->with([false, true])->with([false, true]);

it('handles vector method errors and typed packed vector results over HTTP', function (): void {
    Http::fakeSequence()->push(mediaTl(['_' => 'error', 'code' => 403, 'text' => 'secret']))
        ->push(pack('V', 0x3072cfa1).tlLengthPrefixedBytes(gzencode(pack('V2', 0x1cb5c415, 0))));
    $client = app(EitaaGatewayClient::class);
    expect(fn () => $client->send('messages.receivedMessages', ['max_id' => 0]))->toThrow(EitaaRpcException::class);
    expect($client->send('messages.receivedMessages', ['max_id' => 0]))->toBe([]);
});

it('keeps HTTP and decoding failures distinct without exposing HTTP response contents', function (): void {
    Http::fakeSequence()->push('token IMEI /private/provider-path', 500)->push(pack('V', 123456));
    $client = app(EitaaGatewayClient::class);
    try {
        $client->send('help.getConfig');
        $this->fail('Expected HTTP failure.');
    } catch (RequestException $exception) {
        expect($exception->response->status())->toBe(500)
            ->and($exception->getMessage())->not->toContain('token', 'IMEI', 'private');
    }
    expect(fn () => $client->send('help.getConfig'))->toThrow(RuntimeException::class, 'Constructor not found');
});

it('streams media with one pinned route and correct metadata while text uses configured normal routing', function (bool $photo, int $size): void {
    config(['eitaa.endpoint' => 'https://normal.test/eitaa/', 'eitaa.upload_endpoint' => 'https://upload.test/eitaa/',
        'eitaa.layer' => 133, 'eitaa.envelope_flags' => 0, 'eitaa.upload_layer' => 135, 'eitaa.upload_envelope_flags' => 128]);
    $requests = [];
    Http::fake(function ($request) use (&$requests) {
        [$envelope, $method] = mediaRequest($request);
        $requests[] = [$request->url(), $envelope, $method];
        if (str_starts_with($method['_'], 'upload.')) {
            // Changing config mid-upload must not reroute the final send.
            config(['eitaa.upload_endpoint' => 'https://changed.test/']);
            return Http::response(pack('V', 0x997275b5));
        }
        return Http::response(mediaTl(['_' => 'updateShortSentMessage', 'flags' => 0, 'id' => 101, 'pts' => 1, 'pts_count' => 1, 'date' => 1700000000]));
    });
    $path = tempnam(sys_get_temp_dir(), 'eitaa-unit-');
    $stream = fopen($path, 'wb');
    try {
        ftruncate($stream, $size);
    } finally {
        fclose($stream);
    }
    $peer = ['_' => 'inputPeerUser', 'user_id' => '9007199254740993', 'access_hash' => '-9223372036854775807'];
    $filename = $photo ? 'synthetic.jpeg' : 'synthetic.txt';
    $mimeType = $photo ? 'image/jpeg' : 'text/plain';
    try {
        $response = Eitaa::sendFile($path, $peer, '42', $filename, $mimeType, 'test caption', $photo, 'token', 'imei');
        expect(EitaaSentMessage::id($response, '42'))->toBe('101');
        Eitaa::send('messages.sendMessage', ['flags' => 0, 'peer' => $peer, 'random_id' => '43', 'message' => 'text'], 'token', 'imei');
    } finally {
        unlink($path);
    }
    $parts = (int) ceil($size / 65536);
    expect(count($requests))->toBe($parts + 2);
    for ($i = 0; $i < $parts; $i++) {
        [$url, $envelope, $part] = $requests[$i];
        expect($url)->toBe('https://upload.test/eitaa/')
            ->and($envelope['layer'])->toBe(135)->and($envelope['flags'])->toBe(128)
            ->and($part['_'])->toBe($size >= 10485760 ? 'upload.saveBigFilePart' : 'upload.saveFilePart')
            ->and($part['file_part'])->toBe($i)->and($part['flags'])->toBe($i === 0 ? 3 : 0)
            ->and(strlen($part['bytes']))->toBe(min(65536, $size - $i * 65536));
        if ($i === 0) {
            expect($part['peer'])->toBe(['_' => 'peerUser', 'user_id' => '9007199254740993'])
                ->and($part['totalFileSize'])->toBe((string) $size);
        } else {
            expect($part)->not->toHaveKey('peer')->not->toHaveKey('totalFileSize');
        }
        if ($size >= 10485760) {
            expect($part['file_total_parts'])->toBe($parts);
        }
    }
    [$url, $envelope, $send] = $requests[$parts];
    expect($url)->toBe('https://upload.test/eitaa/')
        ->and($envelope['layer'])->toBe(135)->and($envelope['flags'])->toBe(128)
        ->and($send['_'])->toBe('messages.sendMedia')->and($send['peer'])->toBe($peer)
        ->and($send['random_id'])->toBe('42')->and($send['message'])->toBe('test caption')
        ->and($send['media']['_'])->toBe($photo ? 'inputMediaUploadedPhoto' : 'inputMediaUploadedDocument')
        ->and($send['media']['file']['_'])->toBe($size >= 10485760 ? 'inputFileBig' : 'inputFile')
        ->and($send['media']['file']['parts'])->toBe($parts)
        ->and($send['media']['file']['name'])->toBe($filename)
        ->and($send['media']['file']['id'])->toBe($requests[0][2]['file_id']);
    if ($size < 10485760) {
        expect($send['media']['file']['md5_checksum'])->toBe('');
    }
    if (! $photo) {
        expect($send['media']['pFlags']['force_file'])->toBeTrue()
            ->and($send['media']['mime_type'])->toBe('text/plain')
            ->and($send['media']['attributes'][0]['file_name'])->toBe('synthetic.txt');
    }
    expect($requests[$parts + 1][0])->toBe('https://normal.test/eitaa/')
        ->and($requests[$parts + 1][1]['layer'])->toBe(133)->and($requests[$parts + 1][1]['flags'])->toBe(0);
})->with([[true, 98067], [false, 31], [false, 10485759], [false, 10485760]]);

it('stops after a rejected part without sending media', function (): void {
    Http::fakeSequence()->push(pack('V', 0x997275b5))->push(pack('V', 0xbc799737));
    $path = tempnam(sys_get_temp_dir(), 'eitaa-unit-');
    file_put_contents($path, str_repeat('x', 65537));
    try {
        expect(fn () => app(EitaaGatewayClient::class)->sendFile($path,
            ['_' => 'inputPeerChat', 'chat_id' => '123'], '42', 'test.txt', 'text/plain'))
            ->toThrow(EitaaUploadException::class, 'part 1');
    } finally {
        unlink($path);
    }
    Http::assertSentCount(2);
});

it('accepts native true upload results and converts chat and channel peers without losing send hashes', function (array $peer, array $plain): void {
    $client = new class extends EitaaGatewayClient
    {
        public array $calls = [];

        public function sendUpload(string $method, array $params = [], ?string $token = null, ?string $imei = null): mixed
        {
            // Cloning for pinned configuration shares this recorder object.
            $this->recorder->calls[] = [$method, $params];
            return $method === 'messages.sendMedia' ? ['_' => 'updateShortSentMessage', 'id' => 4] : true;
        }

        public object $recorder;
    };
    $client->recorder = (object) ['calls' => []];
    $path = tempnam(sys_get_temp_dir(), 'eitaa-unit-');
    file_put_contents($path, 'test');
    try {
        $result = $client->sendFile($path, $peer, '42', 'test.txt', 'text/plain');
        expect(EitaaSentMessage::id($result))->toBe('4')
            ->and($client->recorder->calls[0][1]['peer'])->toBe($plain)
            ->and($client->recorder->calls[1][1]['peer'])->toBe($peer);
    } finally {
        unlink($path);
    }
})->with([
    [['_' => 'inputPeerChat', 'chat_id' => '123'], ['_' => 'peerChat', 'chat_id' => '123']],
    [['_' => 'inputPeerChannel', 'channel_id' => '123', 'access_hash' => '9007199254740993'], ['_' => 'peerChannel', 'channel_id' => '123']],
]);

it('uses configured schema and profile through container and facade with nullable defaults', function (): void {
    $schema = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents(__DIR__.'/../../resources/eitaa/schema.json')), true);
    foreach ($schema['API']['methods'] as &$method) {
        if ($method['method'] === 'help.getConfig') {
            $method['id'] = 12345;
        }
    }
    unset($method);
    $path = tempnam(sys_get_temp_dir(), 'eitaa-schema-');
    file_put_contents($path, json_encode($schema));
    config(['eitaa.schema_path' => $path, 'eitaa.endpoint' => 'https://custom.test/',
        'eitaa.layer' => 133, 'eitaa.envelope_flags' => 64, 'eitaa.default_imei' => 'configured-imei', 'eitaa.timeout' => 17]);
    $this->app->forgetInstance(TlSchema::class);
    $this->app->forgetInstance(EitaaGatewayClient::class);
    Eitaa::clearResolvedInstances();
    Http::fake(['*' => Http::response(pack('V', 0x997275b5))]);
    try {
        Eitaa::send('help.getConfig');
        Http::assertSent(function ($request): bool {
            $envelope = (new TlDeserializer(eitaaSchema(), $request->body()))->fetchObject('');
            return $request->url() === 'https://custom.test/' && $envelope['flags'] === 64 &&
                $envelope['imei'] === 'configured-imei' && $envelope['packed_data'] === pack('V', 12345);
        });
    } finally {
        unlink($path);
    }
});

it('routes uploadMedia and keeps low level send routing explicit', function (): void {
    config(['eitaa.endpoint' => 'https://normal.test/', 'eitaa.upload_endpoint' => 'https://upload.test/']);
    Http::fake(['*' => Http::response(pack('V', 0x997275b5))]);
    $params = ['peer' => ['_' => 'inputPeerChat', 'chat_id' => '123'],
        'media' => ['_' => 'inputMediaUploadedPhoto', 'flags' => 0,
            'file' => ['_' => 'inputFile', 'id' => '42', 'parts' => 1, 'name' => 'test.jpg', 'md5_checksum' => '']]];
    Eitaa::sendUpload('messages.uploadMedia', $params);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://upload.test/');
    expect(fn () => Eitaa::sendUpload('messages.sendMessage'))->toThrow(InvalidArgumentException::class);
    Http::assertSentCount(1);
});

it('retains strict decoding for trailing bytes and unknown constructors', function (): void {
    expect(fn () => app(EitaaGatewayClient::class)->parseResponse(pack('V2', 0x997275b5, 0)))
        ->toThrow(RuntimeException::class, 'unread bytes');
});

it('unwraps rpc_result errors before assuming a vector result', function (): void {
    $definition = eitaaSchema()->constructorByPredicate('MTProto', 'rpc_result');
    $error = mediaTl(['_' => 'rpc_error', 'error_code' => 401, 'error_message' => 'private session token'], true);
    $body = pack('V3', $definition['id'], 42, 0).$error;
    try {
        app(EitaaGatewayClient::class)->parseResponse($body, 'Vector<int>');
        $this->fail('Expected session RPC failure.');
    } catch (EitaaRpcException $exception) {
        expect($exception->classification())->toBe('session')->and($exception->getMessage())->not->toContain('token');
    }
});
