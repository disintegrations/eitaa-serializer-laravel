<?php

use Disintegrations\EitaaSerializer\Tests\Support\AuthenticatedMediaSmoke;
use Disintegrations\EitaaSerializer\TL\TlDeserializer;
use Disintegrations\EitaaSerializer\TL\TlSerializer;
use Illuminate\Support\Facades\Http;

it('requires two opt ins and refuses expired sessions before network activity', function (): void {
    $oldIntegration = getenv('EITAA_RUN_INTEGRATION');
    $oldSend = getenv('EITAA_LIVE_SEND');
    $path = tempnam(sys_get_temp_dir(), 'eitaa-expired-');
    file_put_contents($path, json_encode(['expires_at_utc' => '2000-01-01T00:00:00Z']));
    Http::fake();
    try {
        putenv('EITAA_RUN_INTEGRATION=1');
        putenv('EITAA_LIVE_SEND=0');
        expect(fn () => AuthenticatedMediaSmoke::run($path))->toThrow(RuntimeException::class, 'both');
        putenv('EITAA_LIVE_SEND=1');
        expect(fn () => AuthenticatedMediaSmoke::run($path))->toThrow(RuntimeException::class, 'expired');
        Http::assertNothingSent();
    } finally {
        putenv($oldIntegration === false ? 'EITAA_RUN_INTEGRATION' : 'EITAA_RUN_INTEGRATION='.$oldIntegration);
        putenv($oldSend === false ? 'EITAA_LIVE_SEND' : 'EITAA_LIVE_SEND='.$oldSend);
        unlink($path);
    }
});

it('retains private smoke state and never resends confirmed or ambiguous messages on a rerun', function (bool $ambiguous): void {
    $oldIntegration = getenv('EITAA_RUN_INTEGRATION');
    $oldSend = getenv('EITAA_LIVE_SEND');
    $directory = sys_get_temp_dir().'/eitaa-smoke-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory.'/photo.jpeg', 'synthetic photo');
    file_put_contents($directory.'/document.txt', 'synthetic document');
    $fixture = [
        'expires_at_utc' => gmdate('c', time() + 3600), 'token' => 'synthetic-token', 'imei' => 'synthetic-imei',
        'recipient' => ['contact_id' => 1, 'input_peer' => ['_' => 'inputPeerUser', 'user_id' => '123', 'access_hash' => '456']],
        'authorization' => ['recipient_contact_id' => 1],
        'endpoints' => ['client' => 'https://normal.test/', 'upload' => 'https://upload.test/'],
        'protocol' => ['legacy_text_layer' => 133, 'verified_media_layer' => 135, 'media_envelope_flags' => 128],
        'files' => [],
    ];
    foreach (['photo' => ['photo.jpeg', 'image/jpeg'], 'document' => ['document.txt', 'text/plain']] as $kind => [$name, $mime]) {
        $fixture['files'][$kind] = ['relative_path' => $name, 'original_filename' => $name,
            'mime_type' => $mime, 'sha256' => hash_file('sha256', $directory.'/'.$name)];
    }
    file_put_contents($directory.'/fixture.json', json_encode($fixture));
    $messages = [];
    $sendCount = 0;
    Http::fake(function ($request) use (&$messages, &$sendCount, $ambiguous) {
        $envelope = (new TlDeserializer(eitaaSchema(), $request->body()))->fetchObject('');
        $method = (new TlDeserializer(eitaaSchema(), $envelope['packed_data']))->fetchObject('');
        if (str_starts_with($method['_'], 'upload.')) {
            return Http::response(pack('V', 0x997275b5));
        }
        if ($method['_'] === 'messages.getHistory') {
            $value = ['_' => 'messages.messages', 'messages' => $ambiguous ? [] : $messages, 'chats' => [], 'users' => []];
        } else {
            $sendCount++;
            $message = ['_' => 'message', 'flags' => 2, 'id' => $sendCount,
                'peer_id' => ['_' => 'peerUser', 'user_id' => '123'], 'date' => 1700000000, 'message' => $method['message']];
            if ($method['_'] === 'messages.sendMedia') {
                $message['flags'] |= 512;
                $message['media'] = $method['media']['_'] === 'inputMediaUploadedPhoto'
                    ? ['_' => 'messageMediaPhoto', 'flags' => 1, 'photo' => ['_' => 'photoEmpty', 'id' => '1']]
                    : ['_' => 'messageMediaDocument', 'flags' => 1, 'document' => ['_' => 'document', 'flags' => 0,
                        'id' => '1', 'access_hash' => '2', 'file_reference' => '', 'date' => 1700000000,
                        'mime_type' => 'text/plain', 'size' => 18, 'dc_id' => 1,
                        'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => 'document.txt']]]];
            }
            $messages[] = $message;
            $value = ['_' => 'updateShortSentMessage', 'flags' => 0, 'id' => $sendCount, 'pts' => 1, 'pts_count' => 1, 'date' => 1700000000];
        }
        $serializer = new TlSerializer(eitaaSchema());
        $serializer->storeObject($value, 'Object');

        return Http::response($serializer->bytes());
    });
    try {
        putenv('EITAA_RUN_INTEGRATION=1');
        putenv('EITAA_LIVE_SEND=1');
        if ($ambiguous) {
            expect(fn () => AuthenticatedMediaSmoke::run($directory.'/fixture.json'))->toThrow(RuntimeException::class, 'not confirmed');
            $saved = file_get_contents($directory.'/package-media-smoke-state.json');
            expect(fn () => AuthenticatedMediaSmoke::run($directory.'/fixture.json'))->toThrow(RuntimeException::class, 'ambiguous');
            expect($sendCount)->toBe(1)->and(file_get_contents($directory.'/package-media-smoke-state.json'))->toBe($saved);
            return;
        }
        $first = AuthenticatedMediaSmoke::run($directory.'/fixture.json');
        $saved = file_get_contents($directory.'/package-media-smoke-state.json');
        $second = AuthenticatedMediaSmoke::run($directory.'/fixture.json');
        expect($sendCount)->toBe(3)->and($second)->toBe($first)
            ->and(file_get_contents($directory.'/package-media-smoke-state.json'))->toBe($saved)
            ->and(fileperms($directory.'/package-media-smoke-state.json') & 0777)->toBe(0600);
    } finally {
        putenv($oldIntegration === false ? 'EITAA_RUN_INTEGRATION' : 'EITAA_RUN_INTEGRATION='.$oldIntegration);
        putenv($oldSend === false ? 'EITAA_LIVE_SEND' : 'EITAA_LIVE_SEND='.$oldSend);
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with([false, true]);
