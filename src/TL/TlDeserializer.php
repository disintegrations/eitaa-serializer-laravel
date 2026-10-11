<?php

namespace Disintegrations\EitaaSerializer\TL;

use RuntimeException;

class TlDeserializer
{
    private int $offset = 0;

    private ?int $refreshExtensionOffset = null;

    public function __construct(
        private TlSchema $schema,
        private string $buffer,
        private bool $mtproto = false,
    ) {
    }

    public function fetchObject(string $type = ''): mixed
    {
        return $this->fetchTypedObject($type, false);
    }

    /** Decode one complete gateway response, including the extended token response. */
    public function fetchResponse(string $type = ''): mixed
    {
        $response = $this->fetchTypedObject($type, true);
        $result = $response;

        for ($depth = 0; is_array($result) && ($result['_'] ?? null) === 'rpc_result'; $depth++) {
            if ($depth >= 16) {
                throw new RuntimeException('Too many nested RPC result envelopes.');
            }
            $result = $result['result'];
        }

        if ($this->offset < strlen($this->buffer) && $this->refreshExtensionOffset === $this->offset &&
            is_array($result) && ($result['_'] ?? null) === 'eitaa_updates_token') {
            // Verified extensions are opaque; consume them only at the response boundary.
            $this->fetchLong();
            $this->fetchString();
        }

        $this->fetchEnd();

        return $response;
    }

    private function fetchTypedObject(string $type, bool $responseBoundary): mixed
    {
        return match ($type) {
            '#', 'int' => $this->fetchInt(),
            'long' => $this->fetchLong(),
            'int128' => $this->fetchRawBytes(16),
            'int256' => $this->fetchRawBytes(32),
            'int512' => $this->fetchRawBytes(64),
            'string' => $this->fetchString(),
            'bytes' => $this->fetchBytes(),
            'double' => $this->fetchDouble(),
            'Bool' => $this->fetchBool(),
            'true' => true,
            default => $this->fetchComplexObject($type, $responseBoundary),
        };
    }

    public function fetchEnd(): void
    {
        if ($this->offset !== strlen($this->buffer)) {
            throw new RuntimeException('Fetch ended with unread bytes remaining.');
        }
    }

    private function fetchComplexObject(string $type, bool $responseBoundary): mixed
    {
        if ($this->isVectorType($type)) {
            if (str_starts_with($type, 'Vector')) {
                $constructor = $this->readInt();

                if ($constructor === 0x3072cfa1) {
                    return $this->unpackGzipAndFetch($type, $responseBoundary);
                }

                if ($constructor !== 0x1cb5c415) {
                    // RPC errors and envelopes may replace a nominal vector result.
                    $this->offset -= 4;

                    return $this->fetchTypedObject('', $responseBoundary);
                }
            }

            $count = $this->readInt();
            if ($count < 0) {
                throw new RuntimeException('Invalid negative TL vector length.');
            }
            $itemType = substr($type, 7, -1);
            $items = [];

            for ($i = 0; $i < $count; $i++) {
                $items[] = $this->fetchObject($itemType);
            }

            return $items;
        }

        $resolved = $this->resolveDefinition($type, $responseBoundary);
        if (array_key_exists('value', $resolved)) {
            return $resolved['value'];
        }

        [$definition, $fallback] = $resolved;
        $predicate = $definition['predicate'] ?? $definition['method'];

        if ($predicate === 'gzip_packed') {
            return $this->unpackGzipAndFetch($type, $responseBoundary);
        }

        $result = ['_' => $predicate];

        foreach ($definition['params'] as $param) {
            $paramType = $param['type'];
            $conditional = false;

            if ($paramType === '#' && ! array_key_exists('pFlags', $result)) {
                $result['pFlags'] = [];
            }

            if (str_contains($paramType, '?')) {
                $conditional = true;
                [$condition, $paramType] = explode('?', $paramType, 2);
                [$flagField, $bit] = explode('.', $condition, 2);

                if (! (((int) ($result[$flagField] ?? 0)) & (1 << (int) $bit))) {
                    continue;
                }
            }

            $value = $this->fetchTypedObject(
                $paramType,
                $responseBoundary && $predicate === 'rpc_result' && $param['name'] === 'result',
            );

            if ($conditional && $paramType === 'true') {
                $result['pFlags'][$param['name']] = $value;
            } else {
                $result[$param['name']] = $value;
            }
        }

        if ($fallback) {
            $this->mtproto = true;
        }

        if ($responseBoundary && $predicate === 'eitaa_updates_token' && $definition['params'] === [
            ['name' => 'token', 'type' => 'string'],
            ['name' => 'expire', 'type' => 'int'],
            ['name' => 'date', 'type' => 'int'],
        ]) {
            $this->refreshExtensionOffset = $this->offset;
        }

        return $result;
    }

    private function resolveDefinition(string $type, bool $responseBoundary): array
    {
        $schemaName = $this->schemaName();

        if ($type !== '' && str_starts_with($type, '%')) {
            $definition = $this->schema->constructorByType($schemaName, substr($type, 1));

            if ($definition === null) {
                throw new RuntimeException("Constructor not found for TL type [{$type}].");
            }

            return [$definition, false];
        }

        if ($type !== '' && ctype_lower($type[0])) {
            $definition = $this->schema->findConstructorByPredicate($schemaName, $type);

            if ($definition !== null) {
                return [$definition, false];
            }
        }

        $constructor = $this->readInt();

        if ($constructor === 0x1cb5c415) {
            $this->offset -= 4;

            return ['value' => $this->fetchTypedObject('Vector<Object>', $responseBoundary)];
        }

        if ($constructor === 0x3072cfa1) {
            return ['value' => $this->unpackGzipAndFetch($type, $responseBoundary)];
        }

        // Some response IDs also appear as methods under different names.
        $definition = ($responseBoundary ? $this->schema->constructorById($schemaName, $constructor) : null)
            ?? $this->schema->methodById($schemaName, $constructor)
            ?? $this->schema->constructorById($schemaName, $constructor);

        if ($definition !== null) {
            return [$definition, false];
        }

        if ($this->mtproto) {
            $definition = ($responseBoundary ? $this->schema->constructorById('API', $constructor) : null)
                ?? $this->schema->methodById('API', $constructor)
                ?? $this->schema->constructorById('API', $constructor);

            if ($definition !== null) {
                $this->mtproto = false;

                return [$definition, true];
            }
        }

        throw new RuntimeException("Constructor not found for id [{$constructor}].");
    }

    private function unpackGzipAndFetch(string $type, bool $responseBoundary): mixed
    {
        $compressed = $this->fetchBytes();
        $uncompressed = gzdecode($compressed);

        if ($uncompressed === false) {
            throw new RuntimeException('Unable to decode gzip-packed TL payload.');
        }

        $deserializer = new self($this->schema, $uncompressed, $this->mtproto);
        if ($responseBoundary) {
            return $deserializer->fetchResponse($type);
        }

        $result = $deserializer->fetchObject($type);
        $deserializer->fetchEnd();

        return $result;
    }

    private function fetchInt(): int
    {
        return $this->readInt();
    }

    private function fetchLong(): string
    {
        $low = $this->readUint32();
        $high = $this->readUint32();

        return $this->wordsToSignedDecimal($high, $low);
    }

    private function fetchDouble(): float
    {
        $bytes = $this->readBytes(8);

        return unpack('e', $bytes)[1];
    }

    private function fetchBool(): mixed
    {
        $value = $this->readInt();

        if ($value === $this->uintToInt(0x997275b5)) {
            return true;
        }

        if ($value === $this->uintToInt(0xbc799737)) {
            return false;
        }

        $this->offset -= 4;

        return $this->fetchObject('Object');
    }

    private function fetchString(): string
    {
        return $this->fetchLengthPrefixedBytes();
    }

    private function fetchBytes(): string
    {
        return $this->fetchLengthPrefixedBytes();
    }

    private function fetchLengthPrefixedBytes(): string
    {
        $length = ord($this->readBytes(1));

        if ($length === 254) {
            $lengthBytes = $this->readBytes(3);
            $length = ord($lengthBytes[0]) | (ord($lengthBytes[1]) << 8) | (ord($lengthBytes[2]) << 16);
        }

        $bytes = $this->readBytes($length);

        while (($this->offset % 4) !== 0) {
            $this->offset++;
        }

        return $bytes;
    }

    private function fetchRawBytes(int $length): string
    {
        return $this->readBytes($length);
    }

    private function readInt(): int
    {
        return $this->uintToInt($this->readUint32());
    }

    private function readUint32(): int
    {
        return unpack('V', $this->readBytes(4))[1];
    }

    private function readBytes(int $length): string
    {
        if ($this->offset + $length > strlen($this->buffer)) {
            throw new RuntimeException(sprintf(
                'Unexpected end of TL buffer. Tried to read %d bytes at offset %d from %d total bytes.',
                $length,
                $this->offset,
                strlen($this->buffer),
            ));
        }

        $bytes = substr($this->buffer, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }

    private function wordsToSignedDecimal(int $high, int $low): string
    {
        if (($high & 0x80000000) === 0) {
            return $this->wordsToUnsignedDecimal($high, $low);
        }

        $low = (~$low + 1) & 0xffffffff;
        $high = (~$high + ($low === 0 ? 1 : 0)) & 0xffffffff;

        return '-'.$this->wordsToUnsignedDecimal($high, $low);
    }

    private function wordsToUnsignedDecimal(int $high, int $low): string
    {
        $chunks = [
            ($high >> 16) & 0xffff,
            $high & 0xffff,
            ($low >> 16) & 0xffff,
            $low & 0xffff,
        ];

        if (! array_filter($chunks)) {
            return '0';
        }

        $digits = '';

        while (array_filter($chunks)) {
            $carry = 0;

            foreach ($chunks as $index => $chunk) {
                $value = ($carry * 0x10000) + $chunk;
                $chunks[$index] = intdiv($value, 10);
                $carry = $value % 10;
            }

            $digits = (string) $carry.$digits;
        }

        return $digits;
    }

    private function uintToInt(int $value): int
    {
        return $value > 0x7fffffff ? $value - 0x100000000 : $value;
    }

    private function isVectorType(string $type): bool
    {
        return str_starts_with($type, 'Vector') || str_starts_with($type, 'vector');
    }

    private function schemaName(): string
    {
        return $this->mtproto ? 'MTProto' : 'API';
    }
}
