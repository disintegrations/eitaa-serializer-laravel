<?php

use Disintegrations\EitaaSerializer\EitaaGatewayClient;
use Disintegrations\EitaaSerializer\TL\TlDeserializer;

it('wraps packed method bytes as an eitaaObject payload', function (): void {
    $client = new EitaaGatewayClient(
        schema: eitaaSchema(),
        endpoint: 'https://example.test/eitaa/',
        layer: 133,
        defaultImei: 'test-imei',
        timeout: 10,
    );

    $wrapped = $client->wrapRequest(hex2bin('6b18f9c4'), 'test-token');
    $object = (new TlDeserializer(eitaaSchema(), $wrapped))->fetchObject('');

    expect($object['_'])->toBe('eitaaObject')
        ->and($object['token'])->toBe('test-token')
        ->and($object['imei'])->toBe('test-imei')
        ->and(bin2hex($object['packed_data']))->toBe('6b18f9c4')
        ->and($object['layer'])->toBe(133);
});

it('unwraps packed response data without making an HTTP request', function (): void {
    $client = new EitaaGatewayClient(
        schema: eitaaSchema(),
        endpoint: 'https://example.test/eitaa/',
        layer: 133,
        defaultImei: 'test-imei',
        timeout: 10,
    );

    $responseBody = $client->wrapRequest(hex2bin('b5757299'), 'test-token');

    expect($client->parseResponse($responseBody))->toBe(['_' => 'boolTrue']);
});
