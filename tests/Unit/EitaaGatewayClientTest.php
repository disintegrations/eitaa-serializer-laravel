<?php

use Disintegrations\EitaaSerializer\EitaaGatewayClient;
use Disintegrations\EitaaSerializer\TL\TlDeserializer;
use Disintegrations\EitaaSerializer\Exceptions\EitaaRpcException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

it('captures failed login response bytes privately with only safe metadata in logs', function (): void {
    $directory = sys_get_temp_dir().'/eitaa-diagnostics-'.bin2hex(random_bytes(8));
    config(['eitaa.diagnostics.capture_failed_responses' => true, 'eitaa.diagnostics.directory' => $directory]);
    $body = pack('V', 0x997275b5).'private-response-token';
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response($body)]);
    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
        expect($context)->toHaveKeys(['capture_file'])
            ->and(array_diff_key($context, ['capture_file' => true]))->toBe([
                'method' => 'auth.signIn',
                'http_status' => 200,
                'response_bytes' => 26,
                'exception_class' => RuntimeException::class,
            ]);

        return $message === 'Eitaa response processing failed; private response captured.';
    });

    try {
        expect(fn () => (new EitaaGatewayClient(eitaaSchema()))->send('auth.signIn', [
            'phone_number' => '+989120000000', 'phone_code_hash' => 'private-hash', 'phone_code' => '12345',
        ]))->toThrow(RuntimeException::class, 'Fetch ended with unread bytes remaining.');

        $files = glob($directory.'/*.bin');
        expect($files)->toHaveCount(1)
            ->and(file_get_contents($files[0]))->toBe($body)
            ->and(fileperms($files[0]) & 0777)->toBe(0600)
            ->and(fileperms($directory) & 0777)->toBe(0700);
        Http::assertSentCount(1);
    } finally {
        foreach (glob($directory.'/*.bin') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('does not capture disabled diagnostics or successful responses', function (bool $enabled, string $body, ?string $exception): void {
    $directory = sys_get_temp_dir().'/eitaa-diagnostics-'.bin2hex(random_bytes(8));
    config(['eitaa.diagnostics.capture_failed_responses' => $enabled, 'eitaa.diagnostics.directory' => $directory]);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response($body)]);
    Log::shouldReceive('warning')->never();
    $client = new EitaaGatewayClient(eitaaSchema());

    if ($exception !== null) {
        expect(fn () => $client->sendPackedData(pack('V', 0)))->toThrow($exception);
    } else {
        expect($client->sendPackedData(pack('V', 0)))->toBe(['_' => 'boolTrue']);
    }

    expect(is_dir($directory))->toBeFalse();
})->with([
    'disabled' => [false, pack('V', 0x997275b5).'trailing', RuntimeException::class],
    'success' => [true, pack('V', 0x997275b5), null],
    'disabled RPC error' => [false, pack('V2', 0x2144ca19, 400).tlLengthPrefixedBytes('private-error'), EitaaRpcException::class],
]);

it('preserves the decoding failure when capture storage and logging fail', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'eitaa-diagnostics-');
    config(['eitaa.diagnostics.capture_failed_responses' => true, 'eitaa.diagnostics.directory' => $file.'/unavailable']);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(pack('V', 0x997275b5).'private-response-token')]);
    Log::shouldReceive('warning')->once()->with('Eitaa response processing failed; private capture unavailable.', [
        'method' => null, 'http_status' => 200, 'response_bytes' => 26, 'exception_class' => RuntimeException::class,
    ])->andThrow(new RuntimeException('Logger unavailable.'));

    try {
        expect(fn () => (new EitaaGatewayClient(eitaaSchema()))->sendPackedData(pack('V', 0)))
            ->toThrow(RuntimeException::class, 'Fetch ended with unread bytes remaining.');
    } finally {
        unlink($file);
    }
});

it('captures decoded RPC errors privately without leaking provider text into logs', function (): void {
    $directory = sys_get_temp_dir().'/eitaa-diagnostics-'.bin2hex(random_bytes(8));
    config(['eitaa.diagnostics.capture_failed_responses' => true, 'eitaa.diagnostics.directory' => $directory]);
    $body = pack('V2', 0x2144ca19, 400).tlLengthPrefixedBytes('private-token /provider/path');
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response($body)]);
    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) use ($body): bool {
        expect(array_diff_key($context, ['capture_file' => true]))->toBe([
            'method' => 'auth.sendCode', 'http_status' => 200,
            'response_bytes' => strlen($body), 'exception_class' => EitaaRpcException::class,
        ]);

        return $message === 'Eitaa response processing failed; private response captured.';
    });

    try {
        expect(fn () => (new EitaaGatewayClient(eitaaSchema()))->send('auth.sendCode', [
            'phone_number' => '+989120000000', 'api_id' => 2496, 'api_hash' => 'private-api-hash',
            'settings' => ['_' => 'codeSettings', 'flags' => 0],
        ]))->toThrow(EitaaRpcException::class, 'Eitaa RPC failure (rpc_error, code 400).');

        $files = glob($directory.'/*.bin');
        expect($files)->toHaveCount(1)->and(file_get_contents($files[0]))->toBe($body);
        Http::assertSentCount(1);
    } finally {
        foreach (glob($directory.'/*.bin') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});
