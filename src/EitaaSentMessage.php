<?php

namespace Disintegrations\EitaaSerializer;

class EitaaSentMessage
{
    /** Acknowledgement only; this does not confirm human receipt. */
    public static function id(mixed $response, ?string $randomId = null): ?string
    {
        if (! is_array($response)) {
            return null;
        }
        $predicate = $response['_'] ?? null;
        if ($predicate === 'updateShortSentMessage') {
            return self::positiveId($response['id'] ?? null);
        }
        if (in_array($predicate, ['updates', 'updatesCombined'], true)) {
            $updates = $response['updates'] ?? [];
        } elseif ($predicate === 'updateShort') {
            $updates = [$response['update'] ?? []];
        } else {
            return null;
        }
        foreach ($updates as $update) {
            if (($update['_'] ?? null) === 'updateMessageID' &&
                ($randomId === null || (string) ($update['random_id'] ?? '') === $randomId)) {
                return self::positiveId($update['id'] ?? null);
            }
        }
        foreach ($updates as $update) {
            if (in_array($update['_'] ?? null, ['updateNewMessage', 'updateNewChannelMessage'], true) &&
                in_array($update['message']['_'] ?? null, ['message', 'messageService'], true) &&
                ($update['message']['pFlags']['out'] ?? false) === true) {
                return self::positiveId($update['message']['id'] ?? null);
            }
        }

        return null;
    }

    private static function positiveId(mixed $id): ?string
    {
        return (is_int($id) || is_string($id)) && preg_match('/^[1-9]\d*$/D', (string) $id) === 1 ? (string) $id : null;
    }
}
