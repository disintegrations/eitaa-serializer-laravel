<?php

use Disintegrations\EitaaSerializer\TL\TlSchema;
use Disintegrations\EitaaSerializer\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Integration');

function eitaaSchema(): TlSchema
{
    static $schema = null;

    return $schema ??= TlSchema::fromFile(__DIR__.'/../resources/eitaa/schema.json');
}

function tlLengthPrefixedBytes(string $bytes): string
{
    $length = strlen($bytes);

    $encoded = $length <= 253
        ? chr($length)
        : chr(254).chr($length & 0xff).chr(($length >> 8) & 0xff).chr(($length >> 16) & 0xff);

    $encoded .= $bytes;

    while ((strlen($encoded) % 4) !== 0) {
        $encoded .= "\0";
    }

    return $encoded;
}
