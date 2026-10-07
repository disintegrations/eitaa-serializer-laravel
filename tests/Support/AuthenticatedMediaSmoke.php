<?php

namespace Disintegrations\EitaaSerializer\Tests\Support;

use Disintegrations\EitaaSerializer\EitaaGatewayClient;
use Disintegrations\EitaaSerializer\EitaaSentMessage;
use Disintegrations\EitaaSerializer\Exceptions\EitaaRpcException;
use RuntimeException;
use Throwable;

class AuthenticatedMediaSmoke
{
    public static function run(string $fixturePath): array
    {
        if (! filter_var(getenv('EITAA_RUN_INTEGRATION'), FILTER_VALIDATE_BOOLEAN) ||
            ! filter_var(getenv('EITAA_LIVE_SEND'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('Authenticated smoke requires both integration and live-send opt-ins.');
        }
        $fixturePath = realpath($fixturePath);
        if ($fixturePath === false) {
            throw new RuntimeException('Private fixture is missing.');
        }
        $fixture = json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        if (strtotime($fixture['expires_at_utc']) <= time()) {
            throw new RuntimeException('Exported session expired; refresh it in the application and re-export.');
        }
        if ($fixture['recipient']['contact_id'] !== $fixture['authorization']['recipient_contact_id']) {
            throw new RuntimeException('Fixture recipient is not authorized.');
        }
        $root = dirname($fixturePath);
        foreach (['photo', 'document'] as $kind) {
            $file = $fixture['files'][$kind];
            $path = realpath($root.'/'.$file['relative_path']);
            if ($path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) ||
                ! hash_equals($file['sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('Private attachment hash or path validation failed.');
            }
            $fixture['files'][$kind]['resolved_path'] = $path;
        }
        // State stays beside the private export, never in the source checkout.
        $repo = realpath(dirname(__DIR__, 2));
        if (str_starts_with($root.'/', $repo.'/')) {
            throw new RuntimeException('Keep the private fixture and smoke state outside the repository.');
        }
        $oldUmask = umask(0077);
        try {
            $statePath = $root.'/package-media-smoke-state.json';
            $stateFile = fopen($root.'/package-media-smoke.lock', 'c+');
        } finally {
            umask($oldUmask);
        }
        if ($stateFile === false || ! chmod($root.'/package-media-smoke.lock', 0600) || ! flock($stateFile, LOCK_EX)) {
            throw new RuntimeException('Unable to lock private smoke state.');
        }
        try {
            $saved = is_file($statePath) ? file_get_contents($statePath) : '';
            if ($saved === false || ($saved === '' && is_file($statePath))) {
                throw new RuntimeException('Smoke state is damaged; stopped without sending.');
            }
            $state = $saved === '' ? [] : json_decode($saved, true, 512, JSON_THROW_ON_ERROR);
            if ($state === []) {
                foreach (['text', 'photo', 'document'] as $kind) {
                    $randomId = (string) random_int(1, PHP_INT_MAX);
                    $state[$kind] = ['random_id' => $randomId, 'caption' => 'Package delivery test '.$kind.' '.substr(hash('sha256', $randomId), 0, 12), 'status' => 'ready'];
                }
                self::save($statePath, $state);
            }
            $client = new EitaaGatewayClient(endpoint: $fixture['endpoints']['client'],
                layer: $fixture['protocol']['legacy_text_layer'],
                uploadEndpoint: $fixture['endpoints']['upload'],
                uploadLayer: $fixture['protocol']['verified_media_layer'],
                uploadEnvelopeFlags: $fixture['protocol']['media_envelope_flags']);
            $historyClient = new EitaaGatewayClient(endpoint: $fixture['endpoints']['client'],
                layer: $fixture['protocol']['verified_media_layer'], envelopeFlags: $fixture['protocol']['media_envelope_flags']);
            // Check the session and response schema before attempting any sends.
            self::history($historyClient, $fixture);
            foreach (['text', 'photo', 'document'] as $kind) {
                $entry = &$state[$kind];
                if ($entry['status'] !== 'ready') {
                    self::confirmFromHistory($entry, $kind, $historyClient, $fixture);
                    self::save($statePath, $state);
                    if ($entry['status'] !== 'confirmed') {
                        throw new RuntimeException('Previous send remains ambiguous; stopped without resending.');
                    }
                    continue;
                }
                // Persist before the network call: a crash or decoding exception may follow delivery.
                $entry['status'] = 'pending';
                self::save($statePath, $state);
                try {
                    if ($kind === 'text') {
                        $response = $client->send('messages.sendMessage', ['flags' => 0,
                            'peer' => $fixture['recipient']['input_peer'], 'random_id' => $entry['random_id'],
                            'message' => $entry['caption']], $fixture['token'], $fixture['imei']);
                    } else {
                        $file = $fixture['files'][$kind];
                        $response = $client->sendFile($file['resolved_path'], $fixture['recipient']['input_peer'],
                            $entry['random_id'], $file['original_filename'], $file['mime_type'], $entry['caption'],
                            $kind === 'photo', $fixture['token'], $fixture['imei']);
                    }
                    $entry['message_id'] = EitaaSentMessage::id($response, $entry['random_id']);
                    $entry['api_confirmed'] = $entry['message_id'] !== null;
                } catch (Throwable $exception) {
                    $entry['failure_type'] = $exception instanceof EitaaRpcException ? 'rpc' :
                        ($exception instanceof \Disintegrations\EitaaSerializer\Exceptions\EitaaUploadException ? 'upload_rejected' : 'transport_or_decode');
                    if ($exception instanceof EitaaRpcException) {
                        $entry['rpc_code'] = $exception->rpcCode;
                    }
                    self::save($statePath, $state);
                    // Inspect history before deciding whether the send happened. Never resend here.
                }
                self::confirmFromHistory($entry, $kind, $historyClient, $fixture);
                self::save($statePath, $state);
                if ($entry['status'] !== 'confirmed') {
                    throw new RuntimeException('Send not confirmed in history; stopped without retrying. See private sanitized smoke state.');
                }
            }

            return array_map(fn (array $entry): array => array_intersect_key($entry, array_flip([
                'message_id', 'api_confirmed', 'history_confirmed', 'media_constructor', 'status', 'failure_type', 'rpc_code',
            ])), $state);
        } finally {
            flock($stateFile, LOCK_UN);
            fclose($stateFile);
        }
    }

    private static function history(EitaaGatewayClient $client, array $fixture): array
    {
        $response = $client->send('messages.getHistory', [
            'peer' => $fixture['recipient']['input_peer'], 'offset_id' => 0, 'offset_date' => 0,
            'add_offset' => 0, 'limit' => 100, 'max_id' => 0, 'min_id' => 0, 'hash' => '0',
        ], $fixture['token'], $fixture['imei']);
        if (! is_array($response) || ! in_array($response['_'] ?? '', ['messages.messages', 'messages.messagesSlice', 'messages.channelMessages'], true)) {
            throw new RuntimeException('Unrecognized history response; stopped without sending.');
        }

        return $response['messages'];
    }

    private static function confirm(array &$entry, string $kind, array $messages): void
    {
        $matches = array_values(array_filter($messages, fn (array $message): bool =>
            ($message['_'] ?? '') === 'message' && ($message['pFlags']['out'] ?? false) === true &&
            ($message['message'] ?? '') === $entry['caption'] &&
            (! isset($entry['message_id']) || $entry['message_id'] === null || (string) $message['id'] === $entry['message_id'])));
        if (count($matches) !== 1) {
            return;
        }
        $message = $matches[0];
        $media = $message['media']['_'] ?? null;
        if (($kind === 'photo' && $media !== 'messageMediaPhoto') ||
            ($kind === 'document' && $media !== 'messageMediaDocument') || ($kind === 'text' && $media !== null)) {
            return;
        }
        if ($kind === 'document' && ($message['media']['document']['mime_type'] ?? '') !== 'text/plain') {
            return;
        }
        $entry['message_id'] = (string) $message['id'];
        $entry['history_confirmed'] = true;
        $entry['media_constructor'] = $media;
        $entry['status'] = 'confirmed';
    }

    private static function confirmFromHistory(array &$entry, string $kind, EitaaGatewayClient $client, array $fixture): void
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            self::confirm($entry, $kind, self::history($client, $fixture));
            if ($entry['status'] === 'confirmed') {
                return;
            }
            if ($attempt < 3) {
                usleep(500000);
            }
        }
    }

    private static function save(string $path, array $state): void
    {
        $temporary = tempnam(dirname($path), '.smoke-state-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to persist smoke state; do not retry automatically.');
        }
        chmod($temporary, 0600);
        try {
            $stream = fopen($temporary, 'wb');
            if ($stream === false) {
                throw new RuntimeException('Unable to persist smoke state.');
            }
            try {
                $json = json_encode($state, JSON_THROW_ON_ERROR);
                if (fwrite($stream, $json) !== strlen($json) || ! fflush($stream) || ! fsync($stream)) {
                    throw new RuntimeException('Unable to persist smoke state.');
                }
            } finally {
                fclose($stream);
            }
            if (! rename($temporary, $path)) {
                throw new RuntimeException('Unable to persist smoke state.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
