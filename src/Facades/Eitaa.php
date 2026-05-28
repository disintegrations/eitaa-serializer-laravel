<?php

namespace Disintegrations\EitaaSerializer\Facades;

use Illuminate\Support\Facades\Facade;
use Disintegrations\EitaaSerializer\EitaaGatewayClient;

/**
 * @method static mixed send(string $method, array $params = [], ?string $token = null, ?string $imei = null)
 * @method static mixed sendPackedData(string $packedData, ?string $token = null, ?string $imei = null, bool $expectVector = false)
 * @method static string wrapRequest(string $packedData, ?string $token = null, ?string $imei = null)
 * @method static mixed parseResponse(string $responseBody, string $type = '')
 */
class Eitaa extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EitaaGatewayClient::class;
    }
}

