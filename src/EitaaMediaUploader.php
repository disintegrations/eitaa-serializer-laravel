<?php

namespace Disintegrations\EitaaSerializer;

use Disintegrations\EitaaSerializer\Exceptions\EitaaUploadException;
use InvalidArgumentException;

class EitaaMediaUploader
{
    public function __construct(private EitaaGatewayClient $client)
    {
        $this->client = $client->forUpload();
    }

    /** Returns the raw send updates; callers must verify a recognized sent message ID. */
    public function sendFile(
        string $path,
        array $peer,
        string $randomId,
        string $filename,
        string $mimeType,
        string $caption = '',
        bool $photo = false,
        ?string $token = null,
        ?string $imei = null,
    ): mixed
    {
        [$predicate, $idField] = match ($peer['_'] ?? '') {
            'inputPeerUser' => ['peerUser', 'user_id'],
            'inputPeerChat' => ['peerChat', 'chat_id'],
            'inputPeerChannel' => ['peerChannel', 'channel_id'],
            default => throw new InvalidArgumentException('Upload requires a user, chat or channel input peer.'),
        };
        foreach ([$peer[$idField] ?? '', $randomId, ...($predicate === 'peerChat' ? [] : [$peer['access_hash'] ?? ''])] as $long) {
            if (! is_string($long) || preg_match('/^-?\d+$/D', $long) !== 1) {
                throw new InvalidArgumentException('Peer IDs, access hashes and random IDs must be decimal strings.');
            }
        }

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new EitaaUploadException('Unable to open upload file.');
        }

        try {
            $stat = fstat($stream);
            $size = $stat['size'] ?? 0;
            if ($size <= 0 || ! is_file($path)) {
                throw new EitaaUploadException('Upload requires a nonempty regular file.');
            }
            $big = $size >= 10 * 1024 * 1024;
            $totalParts = (int) ceil($size / 65536);
            $fileId = (string) random_int(1, PHP_INT_MAX);
            for ($part = 0; $part < $totalParts; $part++) {
                $length = min(65536, $size - $part * 65536);
                $bytes = '';
                while (strlen($bytes) < $length) {
                    $chunk = fread($stream, $length - strlen($bytes));
                    if ($chunk === false || $chunk === '') {
                        throw new EitaaUploadException('Unable to read complete upload part.');
                    }
                    $bytes .= $chunk;
                }
                $params = [
                    'file_id' => $fileId, 'file_part' => $part, 'bytes' => $bytes,
                    'flags' => $part === 0 ? 3 : 0,
                ];
                if ($part === 0) {
                    $params['peer'] = ['_' => $predicate, $idField => $peer[$idField]];
                    $params['totalFileSize'] = (string) $size;
                }
                if ($big) {
                    $params['file_total_parts'] = $totalParts;
                }
                $accepted = $this->client->sendUpload($big ? 'upload.saveBigFilePart' : 'upload.saveFilePart', $params, $token, $imei);
                if ($accepted !== true && (! is_array($accepted) || ($accepted['_'] ?? null) !== 'boolTrue')) {
                    throw new EitaaUploadException('Eitaa rejected upload part '.$part.'.');
                }
            }
            if (fread($stream, 1) !== '') {
                throw new EitaaUploadException('Upload file size changed while reading.');
            }
        } finally {
            fclose($stream);
        }

        $file = ['_' => $big ? 'inputFileBig' : 'inputFile', 'id' => $fileId, 'parts' => $totalParts, 'name' => $filename];
        if (! $big) {
            $file['md5_checksum'] = '';
        }
        $media = $photo
            ? ['_' => 'inputMediaUploadedPhoto', 'flags' => 0, 'file' => $file]
            : ['_' => 'inputMediaUploadedDocument', 'flags' => 16, 'file' => $file, 'mime_type' => $mimeType,
                'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => $filename]]];

        return $this->client->sendUpload('messages.sendMedia', [
            'flags' => 0, 'peer' => $peer, 'media' => $media, 'message' => $caption, 'random_id' => $randomId,
        ], $token, $imei);
    }
}
