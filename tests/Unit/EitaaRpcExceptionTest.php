<?php

use Disintegrations\EitaaSerializer\EitaaGatewayClient;
use Disintegrations\EitaaSerializer\Exceptions\EitaaRpcException;
use Illuminate\Support\Facades\Http;

it('classifies exact flood-wait signals and preserves only their numeric delay without retrying', function (string $text, ?int $wait, string $kind): void {
    $code = 400;
    $bytes = $kind === 'error'
        ? pack('V2', 0xc4b9f9bb, $code).tlLengthPrefixedBytes($text)
        : pack('V2', 0x2144ca19, $code).tlLengthPrefixedBytes($text);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response($bytes)]);

    try {
        (new EitaaGatewayClient(eitaaSchema()))->sendPackedData(pack('V', 0));
        $this->fail('Expected an RPC exception.');
    } catch (EitaaRpcException $exception) {
        expect($exception->rpcCode)->toBe(400)
            ->and($exception->retryAfterSeconds)->toBe($wait)
            ->and($exception->classification())->toBe($wait === null ? 'rpc' : 'rate_limit')
            ->and($exception->getMessage())->toBe("Eitaa RPC failure ({$kind}, code 400).");
    }
    Http::assertSentCount(1);
})->with([
    'provider wait' => ['FLOOD_WAIT_164', 164],
    'single second' => ['FLOOD_WAIT_1', 1],
    'unknown error' => ['private-token /provider/path', null],
    'zero delay' => ['FLOOD_WAIT_0', null],
    'unbounded delay' => ['FLOOD_WAIT_99999999999999999999', null],
    'negative delay' => ['FLOOD_WAIT_-1', null],
    'embedded signal' => ['private-token FLOOD_WAIT_164', null],
    'trailing private data' => ["FLOOD_WAIT_164\nprivate-token", null],
])->with(['error', 'rpc_error']);
