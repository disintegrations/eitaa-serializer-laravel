<?php

use Disintegrations\EitaaSerializer\TL\TlSchema;

it('loads API and MTProto definitions from the bundled schema', function (): void {
    $schema = eitaaSchema();

    expect($schema)->toBeInstanceOf(TlSchema::class)
        ->and($schema->method('API', 'help.getConfig')['id'])->toBe(-990308245)
        ->and($schema->method('API', 'eitaaObject')['type'])->toBe('EitaaObject')
        ->and($schema->constructorByPredicate('API', 'eitaaObject')['type'])->toBe('EitaaObject')
        ->and($schema->constructorByPredicate('MTProto', 'adsEmptyAd')['type'])->toBe('AdsAd.adsEmptyAd');
});
