<?php

use Disintegrations\EitaaSerializer\EitaaGatewayClient;
use Disintegrations\EitaaSerializer\TL\TlDeserializer;
use Disintegrations\EitaaSerializer\TL\TlSchema;
use Illuminate\Support\Facades\Http;

function loginAuthorizationBytes(): string
{
    return pack('V2', -855308010, 1024).tlLengthPrefixedBytes('synthetic-login-token')
        .pack('V5', -321753653, 1024, 0, 0, 42).pack('V', 0);
}

function loginAuthorizationExtension(string $signature = ''): string
{
    return tlLengthPrefixedBytes($signature === '' ? str_repeat('s', 56) : $signature);
}

function loginAuthorizationGzip(string $bytes): string
{
    return pack('V', 0x3072cfa1).tlLengthPrefixedBytes(gzencode($bytes));
}

function loginAuthorizationRpc(string $bytes): string
{
    return pack('V3', eitaaSchema()->constructorByPredicate('MTProto', 'rpc_result')['id'], 42, 0).$bytes;
}

it('returns login tokens from legacy and extended authorization responses through gateway wrappers', function (bool $extended, string $wrapper): void {
    $client = new EitaaGatewayClient(eitaaSchema());
    $bytes = loginAuthorizationBytes().($extended ? loginAuthorizationExtension() : '');
    $bytes = match ($wrapper) {
        'rpc' => loginAuthorizationRpc($bytes),
        'gzip' => loginAuthorizationGzip($bytes),
        'rpc gzip' => loginAuthorizationRpc(loginAuthorizationGzip($bytes)),
        'gzip rpc' => loginAuthorizationGzip(loginAuthorizationRpc($bytes)),
        'packed' => $client->wrapRequest($bytes),
        default => $bytes,
    };
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response($bytes)]);

    $response = $client->send('auth.signIn', [
        'phone_number' => '+989120000000', 'phone_code_hash' => 'synthetic-hash', 'phone_code' => '12345',
    ]);

    expect($response)->toBe([
        '_' => 'auth.authorization', 'pFlags' => [], 'flags' => 1024, 'token' => 'synthetic-login-token',
        'user' => ['_' => 'user', 'pFlags' => ['self' => true], 'flags' => 1024, 'flags2' => 0, 'eFlags' => 0, 'id' => '42'],
    ]);
    Http::assertSentCount(1);
})->with([false, true])->with(['plain', 'rpc', 'gzip', 'rpc gzip', 'gzip rpc', 'packed']);

it('rejects malformed login extensions and extensions outside the authorization boundary', function (string $bytes): void {
    expect(fn () => (new EitaaGatewayClient(eitaaSchema()))->parseResponse($bytes))->toThrow(RuntimeException::class);
})->with([
    'truncated string' => loginAuthorizationBytes().chr(56).'short',
    'truncated padding' => loginAuthorizationBytes().substr(loginAuthorizationExtension(), 0, -1),
    'old incorrect layout' => loginAuthorizationBytes().pack('V2', 0, 0).loginAuthorizationExtension(),
    'extra tail' => loginAuthorizationBytes().loginAuthorizationExtension().'extra',
    'duplicate extension' => loginAuthorizationBytes().loginAuthorizationExtension().loginAuthorizationExtension(),
    'gzip outer tail' => loginAuthorizationGzip(loginAuthorizationBytes()).loginAuthorizationExtension(),
    'unrelated object' => pack('V', 0x997275b5).loginAuthorizationExtension(),
    'nested vector' => pack('V2', 0x1cb5c415, 1).loginAuthorizationBytes().loginAuthorizationExtension(),
]);

it('keeps raw authorization decoding strict and custom extension fields authoritative', function (): void {
    $bytes = loginAuthorizationBytes().loginAuthorizationExtension();
    $raw = new TlDeserializer(eitaaSchema(), $bytes, true);
    $raw->fetchObject();

    expect(fn () => $raw->fetchEnd())->toThrow(RuntimeException::class, 'unread bytes');

    $definition = eitaaSchema()->constructorByPredicate('API', 'auth.authorization');
    $definition['params'][] = ['name' => 'opaque_string', 'type' => 'string'];
    $schema = new TlSchema(['API' => ['constructors' => [
        $definition, eitaaSchema()->constructorById('API', -321753653),
    ]]]);
    $client = new EitaaGatewayClient($schema);
    $response = $client->parseResponse($bytes);

    expect($response['opaque_string'])->toBe(str_repeat('s', 56));
    expect(fn () => $client->parseResponse($bytes.loginAuthorizationExtension()))
        ->toThrow(RuntimeException::class, 'unread bytes');
});

it('decodes authorization signatures whose eighth byte cannot be mistaken for a nested TL string length', function (string $signature): void {
    $response = (new EitaaGatewayClient(eitaaSchema()))->parseResponse(
        loginAuthorizationBytes().loginAuthorizationExtension($signature),
    );

    expect($response['token'])->toBe('synthetic-login-token');
})->with([
    'previous accidental match' => str_repeat('a', 7).'1'.str_repeat('b', 48),
    'reported read of 57 bytes' => str_repeat('a', 7).'9'.str_repeat('b', 48),
    'longer mistaken length' => str_repeat('a', 7).'z'.str_repeat('b', 48),
]);
