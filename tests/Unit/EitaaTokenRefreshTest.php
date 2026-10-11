<?php

use Disintegrations\EitaaSerializer\EitaaGatewayClient;
use Disintegrations\EitaaSerializer\Responses\TokenRefreshResult;
use Disintegrations\EitaaSerializer\Exceptions\EitaaRpcException;
use Disintegrations\EitaaSerializer\Facades\Eitaa;
use Disintegrations\EitaaSerializer\TL\TlDeserializer;
use Disintegrations\EitaaSerializer\TL\TlSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

function tokenRefreshBytes(int $expire = 1_791_774_800, int $date = 1_791_731_600, string $token = 'replacement-secret-token'): string
{
    return pack('V', -1519642063).tlLengthPrefixedBytes($token).pack('V2', $expire, $date);
}

function tokenRefreshExtension(): string
{
    return pack('V2', 0, 0).tlLengthPrefixedBytes(str_repeat('s', 56));
}

function tokenRefreshGzip(string $bytes): string
{
    return pack('V', 0x3072cfa1).tlLengthPrefixedBytes(gzencode($bytes));
}

function tokenRefreshRpc(string $bytes): string
{
    return pack('V3', eitaaSchema()->constructorByPredicate('MTProto', 'rpc_result')['id'], 42, 0).$bytes;
}

it('refreshes with the original credentials and shared app info through the facade', function (): void {
    config([
        'eitaa.endpoint' => 'https://refresh.test/eitaa/',
        'eitaa.app_info.build_version' => 123,
        'eitaa.app_info.device_model' => 'configured-device',
        'eitaa.app_info.system_version' => 'configured-system',
        'eitaa.app_info.app_version' => 'configured-version',
        'eitaa.app_info.lang_code' => 'en',
        'eitaa.app_info.sign' => 'configured-sign',
    ]);
    Http::preventStrayRequests();
    Http::fake(['https://refresh.test/eitaa/' => Http::response(tokenRefreshBytes().tokenRefreshExtension())]);

    $result = Eitaa::refreshToken('original-secret-token', 'original-session-imei', [
        '_' => 'unsupported-constructor',
        'app_version' => 'override-version',
        'sign' => 'override-sign',
    ]);

    expect($result)->toBeInstanceOf(TokenRefreshResult::class)
        ->and($result->status)->toBe('refreshed')
        ->and($result->token)->toBe('replacement-secret-token')
        ->and($result->expiresAt?->format(DATE_ATOM))->toBe('2026-10-12T03:13:20+00:00')
        ->and($result->providerExpire)->toBe(1_791_774_800)
        ->and($result->providerDate)->toBe(1_791_731_600);
    Http::assertSent(function ($request): bool {
        $decoder = new TlDeserializer(eitaaSchema(), $request->body());
        $envelope = $decoder->fetchObject();
        $decoder->fetchEnd();
        $decoder = new TlDeserializer(eitaaSchema(), $envelope['packed_data']);
        $method = $decoder->fetchObject();
        $decoder->fetchEnd();

        return $request->url() === 'https://refresh.test/eitaa/' &&
            $request->hasHeader('Content-Type', 'application/octet-stream') &&
            $envelope['token'] === 'original-secret-token' && $envelope['imei'] === 'original-session-imei' &&
            $envelope['layer'] === 133 && $envelope['flags'] === 0 &&
            $method === ['_' => 'eitaaRefreshToken', 'app_info' => [
                '_' => 'eitaaAppInfo', 'build_version' => 123, 'device_model' => 'configured-device',
                'system_version' => 'configured-system', 'app_version' => 'override-version',
                'lang_code' => 'en', 'sign' => 'override-sign',
            ]];
    });
    Http::assertSentCount(1);
});

it('sends default app info and returns the legacy refresh response', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(tokenRefreshBytes())]);

    $result = app(EitaaGatewayClient::class)->refreshToken('token', 'imei');

    expect($result->status)->toBe('refreshed');
    Http::assertSent(function ($request): bool {
        $envelope = (new TlDeserializer(eitaaSchema(), $request->body()))->fetchObject();
        $method = (new TlDeserializer(eitaaSchema(), $envelope['packed_data']))->fetchObject();

        return $method['app_info'] === [
            '_' => 'eitaaAppInfo', 'build_version' => 0, 'device_model' => 'web',
            'system_version' => 'v0.1', 'app_version' => 'v0.1', 'lang_code' => 'fa', 'sign' => '',
        ];
    });
    Http::assertSentCount(1);
});

it('decodes legacy and extended refresh responses through supported wrappers', function (bool $extended, string $wrapper): void {
    $client = app(EitaaGatewayClient::class);
    $bytes = tokenRefreshBytes().($extended ? tokenRefreshExtension() : '');
    $bytes = match ($wrapper) {
        'packed' => $client->wrapRequest($client->wrapRequest($bytes)),
        'rpc' => tokenRefreshRpc($bytes),
        'gzip' => tokenRefreshGzip($bytes),
        'gzip rpc' => tokenRefreshGzip(tokenRefreshRpc($bytes)),
        'rpc gzip' => tokenRefreshRpc(tokenRefreshGzip($bytes)),
        'packed gzip rpc' => $client->wrapRequest(tokenRefreshGzip(tokenRefreshRpc($bytes))),
        default => $bytes,
    };

    $response = $client->parseResponse($bytes);

    expect($response)->toBe([
        '_' => 'eitaa_updates_token', 'token' => 'replacement-secret-token',
        'expire' => 1_791_774_800, 'date' => 1_791_731_600,
    ]);
})->with([false, true])->with(['direct', 'packed', 'rpc', 'gzip', 'gzip rpc', 'rpc gzip', 'packed gzip rpc']);

it('supports extended responses through generic send and packed data APIs', function (bool $packed): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(tokenRefreshBytes().tokenRefreshExtension())]);
    $client = app(EitaaGatewayClient::class);

    $response = $packed
        ? $client->sendPackedData(pack('V', -599448727), 'token', 'imei')
        : $client->send('eitaaRefreshToken', ['app_info' => ['_' => 'eitaaAppInfo', ...config('eitaa.app_info')]], 'token', 'imei');

    expect($response['token'])->toBe('replacement-secret-token');
    Http::assertSentCount(1);
})->with([false, true]);

it('uses provider date for a relative lifetime', function (): void {
    Http::fake(['*' => Http::response(tokenRefreshBytes(expire: 3600))]);

    $result = app(EitaaGatewayClient::class)->refreshToken('token', 'imei');

    expect($result->expiresAt?->getTimestamp())->toBe(1_791_735_200);
});

it('uses current UTC time when a relative lifetime lacks a valid provider date', function (): void {
    Http::fake(['*' => Http::response(tokenRefreshBytes(expire: 3600, date: 0))]);
    $before = time();

    $result = app(EitaaGatewayClient::class)->refreshToken('token', 'imei');

    expect($result->expiresAt?->getTimestamp())->toBeGreaterThanOrEqual($before + 3600)
        ->toBeLessThanOrEqual(time() + 3600)
        ->and($result->expiresAt?->getTimezone()->getName())->toBe('UTC');
});

it('returns refresh signals without leaking or replacing the token', function (string $bytes, string $status): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response($bytes)]);

    $result = app(EitaaGatewayClient::class)->refreshToken('token', 'imei');

    expect($result->status)->toBe($status)
        ->and($result->token)->toBeNull()
        ->and($result->expiresAt)->toBeNull();
    Http::assertSentCount(1);
})->with([
    'updating' => [pack('V', -601545867), 'updating'],
    'refresh required' => [pack('V', -601545863), 'refresh_required'],
    'zero lifetime' => [tokenRefreshBytes(expire: 0), 'expired'],
    'negative lifetime' => [tokenRefreshBytes(expire: -1), 'expired'],
]);

it('rejects empty credentials before network activity', function (string $token, string $imei): void {
    Http::preventStrayRequests();

    expect(fn () => app(EitaaGatewayClient::class)->refreshToken($token, $imei))
        ->toThrow(InvalidArgumentException::class, 'nonempty token and session IMEI');
    Http::assertNothingSent();
})->with([['', 'imei'], ['token', ''], [' ', 'imei'], ['token', "\t"]]);

it('rejects invalid app metadata without making a request', function (array $appInfo): void {
    Http::preventStrayRequests();

    expect(fn () => app(EitaaGatewayClient::class)->refreshToken('token', 'imei', $appInfo))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    'build must be integer' => [['build_version' => '123']],
    'metadata must be string' => [['sign' => ['private-sign']]],
]);

it('rejects malformed and unexpected refresh payloads with sanitized diagnostics', function (string $bytes): void {
    Http::fake(['*' => Http::response($bytes)]);

    expect(fn () => app(EitaaGatewayClient::class)->refreshToken('token', 'imei'))
        ->toThrow(RuntimeException::class, 'Unexpected Eitaa token refresh response.');
})->with([
    'unrelated constructor' => pack('V', 0x997275b5),
    'empty token' => tokenRefreshBytes(token: ''),
]);

it('rejects incomplete refresh extensions and extra trailing bytes', function (string $bytes): void {
    expect(fn () => app(EitaaGatewayClient::class)->parseResponse($bytes))->toThrow(RuntimeException::class);
})->with([
    'truncated base' => substr(tokenRefreshBytes(), 0, -1),
    'long only' => tokenRefreshBytes().pack('V2', 0, 0),
    'truncated string' => tokenRefreshBytes().pack('V2', 0, 0).chr(56).'short',
    'missing padding' => tokenRefreshBytes().substr(tokenRefreshExtension(), 0, -1),
    'unknown extra bytes' => tokenRefreshBytes().tokenRefreshExtension().pack('V', 0),
    'unrelated response' => pack('V', -601545867).tokenRefreshExtension(),
    'gzip with outer extension' => tokenRefreshGzip(tokenRefreshBytes()).tokenRefreshExtension(),
    'gzip truncated extension' => tokenRefreshGzip(tokenRefreshBytes().pack('V2', 0, 0)),
    'rpc extra bytes' => tokenRefreshRpc(tokenRefreshBytes().tokenRefreshExtension().pack('V', 0)),
    'nested vector extension' => pack('V2', 0x1cb5c415, 1).tokenRefreshGzip(tokenRefreshBytes().tokenRefreshExtension()),
]);

it('keeps raw TL decoding strict even for gzip packed refresh responses', function (bool $gzip): void {
    $bytes = tokenRefreshBytes().tokenRefreshExtension();
    $decoder = new TlDeserializer(eitaaSchema(), $gzip ? tokenRefreshGzip($bytes) : $bytes, true);

    expect(function () use ($decoder): void {
        $decoder->fetchObject();
        $decoder->fetchEnd();
    })->toThrow(RuntimeException::class, 'unread bytes');
})->with([false, true]);

it('preserves injected schemas that already define the refresh extension', function (): void {
    $definition = eitaaSchema()->constructorByPredicate('MTProto', 'eitaa_updates_token');
    $definition['params'][] = ['name' => 'opaque_long', 'type' => 'long'];
    $definition['params'][] = ['name' => 'opaque_string', 'type' => 'string'];
    $client = new EitaaGatewayClient(new TlSchema(['MTProto' => ['constructors' => [$definition]]]));

    $response = $client->parseResponse(tokenRefreshBytes().tokenRefreshExtension());

    expect($response['opaque_long'])->toBe('0')
        ->and($response['opaque_string'])->toBe(str_repeat('s', 56));
    expect(fn () => $client->parseResponse(tokenRefreshBytes().tokenRefreshExtension().tokenRefreshExtension()))
        ->toThrow(RuntimeException::class, 'unread bytes');
});

it('redacts refresh results in JSON and debug output and refuses native serialization', function (): void {
    $result = new TokenRefreshResult(status: 'refreshed', token: 'private-refresh-token');

    expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('private-refresh-token')
        ->and(print_r($result, true))->not->toContain('private-refresh-token')
        ->and($result->jsonSerialize()['token'])->toBe('[REDACTED]');
    expect(fn () => serialize($result))->toThrow(LogicException::class, 'must not be serialized');
});

it('preserves sanitized HTTP errors during refresh without retrying', function (): void {
    Http::fake(['*' => Http::response('provider-private-token', 500)]);

    try {
        app(EitaaGatewayClient::class)->refreshToken('token', 'imei');
        $this->fail('Expected an HTTP exception.');
    } catch (RequestException $exception) {
        expect($exception->response->status())->toBe(500)
            ->and($exception->response->body())->toBe('')
            ->and($exception->getMessage())->not->toContain('provider-private-token');
    }
    Http::assertSentCount(1);
});

it('preserves connection exceptions during refresh', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection failed.'));

    expect(fn () => app(EitaaGatewayClient::class)->refreshToken('token', 'imei'))
        ->toThrow(ConnectionException::class, 'Connection failed.');
});

it('preserves sanitized RPC errors during refresh without retrying', function (): void {
    $bytes = pack('V2', -994444869, 401).tlLengthPrefixedBytes('provider-private-token');
    Http::fake(['*' => Http::response(tokenRefreshGzip(tokenRefreshRpc($bytes)))]);

    try {
        app(EitaaGatewayClient::class)->refreshToken('token', 'imei');
        $this->fail('Expected an RPC exception.');
    } catch (EitaaRpcException $exception) {
        expect($exception->rpcCode)->toBe(401)
            ->and($exception->classification())->toBe('session')
            ->and($exception->getMessage())->not->toContain('provider-private-token');
    }
    Http::assertSentCount(1);
});
