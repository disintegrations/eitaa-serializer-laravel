<?php

namespace Disintegrations\EitaaSerializer\TL;

use InvalidArgumentException;

class TlSerializer
{
    private string $buffer = '';

    public function __construct(
        private TlSchema $schema,
        private bool $mtproto = false,
    ) {
    }

    public function bytes(): string
    {
        return $this->buffer;
    }

    public function storeMethod(string $method, array $params = []): string
    {
        $methodData = $this->schema->method($this->schemaName(), $method);

        $this->storeInt($methodData['id']);

        foreach ($methodData['params'] as $param) {
            $type = $param['type'];

            if (str_contains($type, '?')) {
                [$condition, $type] = explode('?', $type, 2);
                [$flagField, $bit] = explode('.', $condition, 2);

                if (! (((int) ($params[$flagField] ?? 0)) & (1 << (int) $bit))) {
                    continue;
                }
            }

            $this->storeObject($params[$param['name']] ?? null, $type);
        }

        return $methodData['type'];
    }

    public function storeObject(mixed $value, string $type): void
    {
        switch ($type) {
            case '#':
            case 'int':
                $this->storeInt($value);
                return;
            case 'long':
                $this->storeLong($value);
                return;
            case 'int128':
                $this->storeIntBytes($value, 128);
                return;
            case 'int256':
                $this->storeIntBytes($value, 256);
                return;
            case 'int512':
                $this->storeIntBytes($value, 512);
                return;
            case 'string':
                $this->storeString((string) ($value ?? ''));
                return;
            case 'bytes':
                $this->storeBytes($value);
                return;
            case 'double':
                $this->storeDouble((float) $value);
                return;
            case 'Bool':
                $this->storeBool((bool) $value);
                return;
            case 'true':
                return;
            default:
                $this->storeComplexObject($value, $type);
        }
    }

    private function storeComplexObject(mixed $value, string $type): void
    {
        if ($this->isVectorType($type)) {
            if (! is_array($value)) {
                throw new InvalidArgumentException("Invalid vector value for TL type [{$type}].");
            }

            if (str_starts_with($type, 'Vector')) {
                $this->storeInt(0x1cb5c415);
            }

            $itemType = substr($type, 7, -1);
            $this->storeInt(count($value));

            foreach ($value as $item) {
                $this->storeObject($item, $itemType);
            }

            return;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("Invalid object value for TL type [{$type}].");
        }

        $predicate = $value['_'] ?? null;
        if (! is_string($predicate) || $predicate === '') {
            throw new InvalidArgumentException("TL object for type [{$type}] must contain a [_] predicate.");
        }

        $isBare = str_starts_with($type, '%');
        $bareType = $isBare ? substr($type, 1) : $type;
        $constructor = $this->schema->constructorByPredicate($this->schemaName(), $predicate);

        if ($predicate === $bareType) {
            $isBare = true;
        }

        if (! $isBare) {
            $this->storeInt($constructor['id']);
        }

        foreach ($constructor['params'] as $param) {
            $paramType = $param['type'];

            if (str_contains($paramType, '?')) {
                [$condition, $paramType] = explode('?', $paramType, 2);
                [$flagField, $bit] = explode('.', $condition, 2);

                if (! (((int) ($value[$flagField] ?? 0)) & (1 << (int) $bit))) {
                    continue;
                }
            }

            $this->storeObject($value[$param['name']] ?? null, $paramType);
        }
    }

    private function storeInt(mixed $value): void
    {
        $this->buffer .= pack('V', $this->uint32((int) $value));
    }

    private function storeBool(bool $value): void
    {
        $this->storeInt($value ? 0x997275b5 : 0xbc799737);
    }

    private function storeLong(mixed $value): void
    {
        if (is_array($value)) {
            if (count($value) !== 2) {
                throw new InvalidArgumentException('Long arrays must be [high, low].');
            }

            $this->storeInt($value[1]);
            $this->storeInt($value[0]);

            return;
        }

        [$high, $low] = $this->decimalToUint32Words((string) ($value ?? '0'));
        $this->storeInt($low);
        $this->storeInt($high);
    }

    private function storeDouble(float $value): void
    {
        $this->buffer .= pack('e', $value);
    }

    private function storeString(string $value): void
    {
        $this->storeLengthPrefixedBytes($value);
    }

    private function storeBytes(mixed $value): void
    {
        $this->storeLengthPrefixedBytes($this->bytesFromValue($value));
    }

    private function storeIntBytes(mixed $value, int $bits): void
    {
        $bytes = $this->bytesFromValue($value);
        $expectedLength = intdiv($bits, 8);

        if (($bits % 32) !== 0 || strlen($bytes) !== $expectedLength) {
            throw new InvalidArgumentException("Invalid int{$bits} byte length.");
        }

        $this->buffer .= $bytes;
    }

    private function storeLengthPrefixedBytes(string $bytes): void
    {
        $length = strlen($bytes);

        if ($length <= 253) {
            $this->buffer .= chr($length);
        } else {
            $this->buffer .= chr(254).chr($length & 0xff).chr(($length >> 8) & 0xff).chr(($length >> 16) & 0xff);
        }

        $this->buffer .= $bytes;

        while ((strlen($this->buffer) % 4) !== 0) {
            $this->buffer .= "\0";
        }
    }

    private function bytesFromValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            $bytes = '';
            foreach ($value as $byte) {
                $bytes .= chr(((int) $byte) & 0xff);
            }

            return $bytes;
        }

        throw new InvalidArgumentException('Byte values must be binary strings or arrays of integers.');
    }

    private function decimalToUint32Words(string $decimal): array
    {
        $decimal = trim($decimal);
        $negative = str_starts_with($decimal, '-');
        $digits = ltrim($negative ? substr($decimal, 1) : $decimal, '+');
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return [0, 0];
        }

        if (! ctype_digit($digits)) {
            throw new InvalidArgumentException("Invalid long value [{$decimal}].");
        }

        $high = 0;
        $low = 0;

        foreach (str_split($digits) as $digit) {
            $carry = ($low * 10) + (int) $digit;
            $low = $carry & 0xffffffff;
            $high = (($high * 10) + intdiv($carry, 0x100000000)) & 0xffffffff;
        }

        if ($negative) {
            $low = (~$low + 1) & 0xffffffff;
            $high = (~$high + ($low === 0 ? 1 : 0)) & 0xffffffff;
        }

        return [$high, $low];
    }

    private function uint32(int $value): int
    {
        return $value < 0 ? $value + 0x100000000 : $value;
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

