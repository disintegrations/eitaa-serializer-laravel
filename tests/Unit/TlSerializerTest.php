<?php

use Disintegrations\EitaaSerializer\TL\TlSerializer;

it('serializes a schema method id using TL little-endian int encoding', function (): void {
    $serializer = new TlSerializer(eitaaSchema());

    $type = $serializer->storeMethod('help.getConfig');

    expect($type)->toBe('Config')
        ->and(bin2hex($serializer->bytes()))->toBe('6b18f9c4');
});

it('serializes eitaaObject request wrappers', function (): void {
    $serializer = new TlSerializer(eitaaSchema());

    $serializer->storeMethod('eitaaObject', [
        'token' => 'test-token',
        'imei' => 'test-imei',
        'packed_data' => hex2bin('6b18f9c4'),
        'layer' => 133,
    ]);

    expect(bin2hex(substr($serializer->bytes(), 0, 4)))->toBe('ed77be7a')
        ->and($serializer->bytes())->toContain('test-token')
        ->and($serializer->bytes())->toContain('test-imei');
});
