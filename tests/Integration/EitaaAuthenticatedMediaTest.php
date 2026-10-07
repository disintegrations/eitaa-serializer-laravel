<?php

use Disintegrations\EitaaSerializer\Tests\Support\AuthenticatedMediaSmoke;

it('sends one labeled text photo and document to the exported authorized recipient', function (): void {
    if (! filter_var(getenv('EITAA_RUN_INTEGRATION'), FILTER_VALIDATE_BOOLEAN) ||
        ! filter_var(getenv('EITAA_LIVE_SEND'), FILTER_VALIDATE_BOOLEAN) || ! getenv('EITAA_LIVE_FIXTURE')) {
        $this->markTestSkipped('Requires EITAA_RUN_INTEGRATION=1, EITAA_LIVE_SEND=1 and an external EITAA_LIVE_FIXTURE.');
    }
    $outcomes = AuthenticatedMediaSmoke::run(getenv('EITAA_LIVE_FIXTURE'));
    foreach ($outcomes as $outcome) {
        expect($outcome['history_confirmed'])->toBeTrue()->and($outcome['message_id'])->not->toBeNull();
    }
    fwrite(STDOUT, "\nSanitized package smoke outcomes: ".json_encode($outcomes).PHP_EOL);
})->group('integration', 'authenticated-media');
