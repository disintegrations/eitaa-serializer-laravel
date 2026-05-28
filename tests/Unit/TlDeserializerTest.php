<?php

use Disintegrations\EitaaSerializer\TL\TlDeserializer;

it('deserializes TL bool constructors', function (): void {
    expect((new TlDeserializer(eitaaSchema(), hex2bin('379779bc')))->fetchObject('Bool'))->toBeFalse()
        ->and((new TlDeserializer(eitaaSchema(), hex2bin('b5757299')))->fetchObject('Bool'))->toBeTrue();
});

it('deserializes gzip packed API objects through MTProto fallback', function (): void {
    $payload = hex2bin('a1cf7230').tlLengthPrefixedBytes(gzencode(hex2bin('b5757299')));

    $object = (new TlDeserializer(eitaaSchema(), $payload, true))->fetchObject('');

    expect($object)->toBe(['_' => 'boolTrue']);
});
