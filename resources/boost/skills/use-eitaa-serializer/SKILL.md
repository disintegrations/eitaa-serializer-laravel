---
name: use-eitaa-serializer
description: Use when configuring, using, testing, or troubleshooting the disintegrations/eitaa-serializer-laravel package. Covers EitaaGatewayClient and facade usage, TL parameter rules, no-auth Eitaa methods, and Pest integration tests.
---

# Eitaa Serializer Package

Use this skill when working with the `disintegrations/eitaa-serializer-laravel` Laravel package in another project.

## Requirements

- Require PHP 8.3 or newer.
- Require Laravel 12 or newer.
- Use package namespace `Disintegrations\EitaaSerializer`.
- The package sends binary TL payloads to `https://sajad.eitaa.ir/eitaa/` through Laravel's HTTP client.

## Configuration

Publishing config is optional:

```bash
php artisan vendor:publish --tag=eitaa-config
```

Useful `.env` keys:

```dotenv
EITAA_GATEWAY_ENDPOINT=https://sajad.eitaa.ir/eitaa/
EITAA_LAYER=133
EITAA_DEFAULT_IMEI=00__web
EITAA_TIMEOUT=30
```

The bundled TL schema is used by default. Only publish the schema when it must be customized:

```bash
php artisan vendor:publish --tag=eitaa-schema
```

Then set `EITAA_SCHEMA_PATH` to the absolute schema path.

## Usage

Prefer dependency injection or `app()` resolution:

```php
use Disintegrations\EitaaSerializer\EitaaGatewayClient;

$response = app(EitaaGatewayClient::class)->send(
    method: 'help.getConfig',
    params: [],
    token: null,
    imei: null,
);
```

Facade usage:

```php
use Disintegrations\EitaaSerializer\Facades\Eitaa;

$response = Eitaa::send('help.getConfig');
```

Authenticated method example:

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

## TL Parameter Rules

- Use `_` for TL constructor predicates, for example `['_'=> 'inputPeerSelf']`.
- Set `flags` correctly for optional TL fields. If a flag bit is missing, the serializer omits that field.
- Pass `long` values as strings when they may exceed PHP integer range.
- Pass `bytes`, `int128`, `int256`, and `int512` as raw binary strings or arrays of byte integers.

## No-Auth Methods

Use these methods for live smoke tests because they do not require token/auth:

```php
help.getConfig
help.getNearestDc
```

Do not use auth-required methods in package smoke tests unless credentials are explicitly provided by the user.

## Testing In The Package

Normal tests avoid real Eitaa network calls:

```bash
composer test
```

Live integration tests call the Eitaa gateway:

```bash
EITAA_RUN_INTEGRATION=1 composer test:integration
```

PowerShell:

```powershell
$env:EITAA_RUN_INTEGRATION='1'; composer test:integration
```

In CI, run integration tests only after checking `https://sajad.eitaa.ir/eitaa/` is reachable.

## Troubleshooting

- If Laravel config values do not update, run `php artisan config:clear`.
- If deserialization fails, report the exception type, HTTP status and response length only. Do not dump raw responses, tokens, IMEI, access hashes, upload bytes or provider paths.
- If a TL object fails to serialize, check the schema for the exact method params, nested constructor predicates, and required flags.

## Uploaded Media

Use `EitaaGatewayClient::sendFile()` for streamed local uploads (see README for the complete example). Persist the decimal-string `randomId` before sending. Keep original filenames and MIME types; choose `photo: true` for a photo or `photo: false` for a document. It pins the configured upload endpoint for every part and the final `messages.sendMedia`, uses layer 135 / envelope flags 128, and converts input peers to plain upload peers. Ordinary `send()` retains layer 133 and normal routing. Remove subclasses that append flags manually.

For low-level upload code, use `$upload = $client->forUpload()` and `sendUpload()` consistently for parts and freshly uploaded media. Never switch endpoints mid-transaction. The envelope includes flags once; `EITAA_LEGACY_ENVELOPE=true` restores the old envelope only for ordinary calls. Refresh custom response schemas for modern user decoding.

Gateway native errors and RPC errors throw `EitaaRpcException`; use its numeric `rpcCode` and `classification()` rather than logging provider text. Raw TL decoding is still available through `TlDeserializer`. Confirm `EitaaSentMessage::id($response, $randomId)` rather than assuming arrays indicate success. A final-send decoding error may follow delivery: inspect history before retrying and retain the same random ID.

Authenticated tests require `EITAA_RUN_INTEGRATION=1`, `EITAA_LIVE_SEND=1` and an external private `EITAA_LIVE_FIXTURE`. No-auth integration tests do not send messages. Keep private smoke state across reruns; ambiguous sends stop without resending.
