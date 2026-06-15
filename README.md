# Eitaa Serializer

[![Package CI](https://github.com/disintegrations/eitaa-serializer-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/disintegrations/eitaa-serializer-laravel/actions/workflows/tests.yml)

Laravel client for serializing Eitaa TL requests, sending them to `https://sajad.eitaa.ir/eitaa/`,
and deserializing the binary response.

## Installation

Requires PHP 8.3 or newer and Laravel 12 or newer.

```bash
composer require disintegrations/eitaa-serializer-laravel
```

Laravel package discovery registers the service provider automatically.

## Configuration

Publish the config when you need to override defaults:

```bash
php artisan vendor:publish --tag=eitaa-config
```

Available environment variables:

```dotenv
EITAA_GATEWAY_ENDPOINT=https://sajad.eitaa.ir/eitaa/
EITAA_LAYER=133
EITAA_DEFAULT_IMEI=00__web
EITAA_TIMEOUT=30
```

The package uses its bundled TL schema by default. If you need to customize the schema:

```bash
php artisan vendor:publish --tag=eitaa-schema
```

Then set:

```dotenv
EITAA_SCHEMA_PATH=/absolute/path/to/resources/eitaa/schema.json
```

## Usage

Inject or resolve the client:

```php
use Disintegrations\EitaaSerializer\EitaaGatewayClient;

$response = app(EitaaGatewayClient::class)->send(
    method: 'help.getConfig',
    params: [],
    token: null,
    imei: null,
);
```

Or use the facade:

```php
use Disintegrations\EitaaSerializer\Facades\Eitaa;

$response = Eitaa::send('help.getConfig');
```

Authenticated calls:

```php
$response = app(EitaaGatewayClient::class)->send(
    method: 'messages.sendMessage',
    params: [
        'flags' => 0,
        'peer' => [
            '_' => 'inputPeerUser',
            'user_id' => '123456789',
            'access_hash' => '987654321',
        ],
        'message' => 'Hello',
        'random_id' => (string) random_int(1, PHP_INT_MAX),
    ],
    token: $token,
    imei: $imei,
);
```

## TL Value Rules

- TL objects use `_` for the constructor predicate, for example `['_'=> 'inputPeerSelf']`.
- Optional fields require the correct `flags` bits from the schema.
- `long` values should be strings when they can exceed PHP integer range.
- `bytes`, `int128`, `int256`, and `int512` can be raw binary strings or arrays of byte integers.

## Testing

```bash
composer install
composer test
```

Live Eitaa integration tests are available for no-auth methods. They are disabled by default because they call the external gateway:

```bash
EITAA_RUN_INTEGRATION=1 composer test:integration
```

On PowerShell:

```powershell
$env:EITAA_RUN_INTEGRATION='1'; composer test:integration
```

The integration suite currently calls `help.getConfig` and `help.getNearestDc` without a token.
