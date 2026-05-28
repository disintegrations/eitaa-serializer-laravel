<?php

use Disintegrations\EitaaSerializer\EitaaGatewayClient;

beforeEach(function (): void {
    if (! filter_var(getenv('EITAA_RUN_INTEGRATION'), FILTER_VALIDATE_BOOLEAN)) {
        $this->markTestSkipped('Set EITAA_RUN_INTEGRATION=1 to call the live Eitaa gateway.');
    }
});

it('calls live Eitaa no-auth help methods', function (string $method, array $params, array $expectedPredicates): void {
    $client = app(EitaaGatewayClient::class);

    $response = $client->send(
        method: $method,
        params: $params,
        token: null,
        imei: null,
    );

    expect($response)->toBeArray()
        ->and($response['_'] ?? null)->toBeIn($expectedPredicates);
})->with([
    'help.getConfig' => ['help.getConfig', [], ['config']],
    'help.getNearestDc' => ['help.getNearestDc', [], ['nearestDc']],
])->group('integration');
