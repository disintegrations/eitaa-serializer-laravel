<?php

namespace Disintegrations\EitaaSerializer\Exceptions;

use RuntimeException;

class EitaaRpcException extends RuntimeException
{
    public function __construct(public readonly int $rpcCode, public readonly string $constructor)
    {
        // Provider text can contain private paths, credentials or uploaded content.
        parent::__construct("Eitaa RPC failure ({$constructor}, code {$rpcCode}).", $rpcCode);
    }

    public function classification(): string
    {
        return match (true) {
            in_array($this->rpcCode, [401, 403], true) => 'session',
            in_array($this->rpcCode, [420, 429], true) => 'rate_limit',
            $this->rpcCode >= 500 && $this->rpcCode < 600 => 'transient',
            default => 'rpc',
        };
    }
}
