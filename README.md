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

Shared application metadata lives under `eitaa.app_info` for Eitaa methods that accept an `app_info` parameter. The `refreshToken()` helper uses these defaults and accepts per-call overrides. When calling `send()` directly, supply `['_' => 'eitaaAppInfo', ...config('eitaa.app_info')]` for that parameter.

```dotenv
EITAA_BUILD_VERSION=0
EITAA_DEVICE_MODEL=web
EITAA_SYSTEM_VERSION=v0.1
EITAA_APP_VERSION=v0.1
EITAA_LANG_CODE=fa
EITAA_SIGN=
```

The package uses its bundled TL schema by default. If you need to customize the schema:

```bash
php artisan vendor:publish --tag=eitaa-schema
```

Then set:

```dotenv
EITAA_SCHEMA_PATH=/absolute/path/to/resources/eitaa/schema.json
```

Login responses support both legacy `auth.authorization` and the observed extended form containing one opaque TL `string` after the user. This differs from the refresh response's `long` plus `string` extension. Complete gateway responses consume that extension without exposing it. Raw `fetchObject()` / `fetchEnd()` remains strict, and custom schemas defining the field remain authoritative. Malformed extensions and additional trailing bytes still fail decoding.

### Capture a failed response

To diagnose an unread-bytes or unknown-constructor failure, temporarily enable:

```dotenv
EITAA_CAPTURE_FAILED_RESPONSES=true
```

Run `php artisan config:clear` in the consuming application, then reproduce the request once. The service provider merges the diagnostic defaults into existing published configuration. Capture saves decoding failures and decoded RPC errors from successful HTTP responses. Failed HTTP requests are not captured. The client does not retry requests automatically.

Captures are binary `.bin` files in `storage/app/private/eitaa-diagnostics/`, created with owner-only permissions. Override this with `EITAA_DIAGNOSTICS_DIRECTORY=/absolute/private/path` if needed. Keep any existing directory private. The application log includes the capture filename, method, HTTP status, byte count and exception class, without response contents or request credentials. A storage or logging failure preserves the original response-processing exception.

These files may contain session tokens and personal information. Share only their local paths for private diagnosis; do not paste contents into logs, issues or chat. Disable capture and delete the files after diagnosis. Direct offline `parseResponse()` calls do not capture files.

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

## Token refresh

Refresh an existing session explicitly using its stored token and original IMEI:

```php
use Disintegrations\EitaaSerializer\EitaaGatewayClient;

$refresh = app(EitaaGatewayClient::class)->refreshToken(
    token: $session->token,
    imei: $session->imei,
    appInfo: ['app_version' => 'v0.1'], // Optional configuration overrides.
);

if ($refresh->status === 'refreshed') {
    // Persist both values before making another authenticated request.
    $session->token = $refresh->token;
    $session->expires_at = $refresh->expiresAt;
    $session->save();
}
```

The facade also supports `Eitaa::refreshToken($token, $imei, $appInfo)`. It returns `Disintegrations\EitaaSerializer\Responses\TokenRefreshResult`, which normalizes the TL refresh response constructors and exposes readonly `status`, nullable `token`, UTC `expiresAt`, `providerExpire`, and `providerDate`. Credentials are required; the helper sends one request through the ordinary endpoint, layer, and envelope configuration. It does not persist tokens, schedule refreshes, or replay requests. Coordinate refresh and credential updates in your application to avoid concurrent requests using an old token.

| Status | Meaning |
| --- | --- |
| `refreshed` | A replacement token and positive expiry were returned. Persist them before further requests. |
| `updating` | Eitaa reports token updating. Keep the stored credentials and retry later under application control. |
| `refresh_required` | Eitaa returned `eitaa_updates_expire_token`. It signals a refresh requirement, not permanent session invalidation. |
| `expired` | The token response has a nonpositive lifetime. No replacement token or expiry is exposed. |

Positive `expire` values of at least `1_000_000_000` are Unix timestamps; smaller values are durations added to a valid provider date, or current UTC time when that date is unavailable. Malformed and unexpected responses throw sanitized `RuntimeException`s. Existing HTTP, connection, and sanitized `EitaaRpcException` failures propagate without automatic retries. JSON and debug output redact the result token, and native PHP serialization is prohibited; access `$refresh->token` only when storing or using credentials. Raw `send()` responses still contain credentials and must not be logged.

The helper uses shared `eitaa.app_info` configuration. Per-call fields override those defaults; the `eitaaAppInfo` constructor is supplied by the helper.

Gateway decoding supports both the original token response and the verified extended response containing an additional TL `long` and `string`. Those opaque fields are consumed and discarded with the bundled schema. `send()`, `sendPackedData()`, and `parseResponse()` share this compatibility, including packed, RPC, and gzip wrappers. Custom schemas remain authoritative; `TlDeserializer::fetchObject()` plus `fetchEnd()` retains strict raw decoding. `fetchResponse()` provides complete gateway-response decoding.

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

## Uploads and media migration

Ordinary `send()` calls stay on `eitaa.endpoint` at layer 133. Requests now include the official final envelope `flags:int` exactly once (default 0). `EITAA_LEGACY_ENVELOPE=true` explicitly restores the old four-field envelope for ordinary calls. The media profile is selected by `sendUpload()` or `sendFile()`, with independent defaults:

```dotenv
EITAA_ENVELOPE_FLAGS=0
EITAA_LEGACY_ENVELOPE=false
EITAA_UPLOAD_ENDPOINT=https://alzheimer.eitaa.com/eitaa/
EITAA_UPLOAD_LAYER=135
EITAA_UPLOAD_ENVELOPE_FLAGS=128
```

Other official upload endpoints observed in the web client are `https://fateme.eitaa.com/eitaa/`, `https://ali.eitaa.com/eitaa/` and `https://meysam.eitaa.com/eitaa/`. Select one endpoint for the whole upload transaction; the package does not fail over. The first endpoint above is the verified route. The known working combination includes upload routing, layer 135, envelope flags 128 and current response decoding; the evidence does not isolate each protocol change as a separate cause.

Replace a subclass that appends `pack('V', 128)` with the supported API. Remove that override entirely: appending flags would now duplicate them. Resolve the client through the Laravel container or facade so package configuration is retained:

```php
use Disintegrations\EitaaSerializer\EitaaGatewayClient;
use Disintegrations\EitaaSerializer\EitaaSentMessage;

$client = app(EitaaGatewayClient::class);
// Persist this decimal string with the outgoing message BEFORE the first send.
$randomId = (string) random_int(1, PHP_INT_MAX);
$peer = ['_' => 'inputPeerUser', 'user_id' => '123', 'access_hash' => '456'];
$response = $client->sendFile(
    path: $localPath,
    peer: $peer,
    randomId: $randomId,
    filename: 'photo.jpeg',
    mimeType: 'image/jpeg',
    caption: 'Attachment caption',
    photo: true,
    token: $token,
    imei: $imei,
);
$messageId = EitaaSentMessage::id($response, $randomId);
if ($messageId === null) {
    // Unconfirmed acknowledgement: inspect recipient history before considering retry.
    throw new RuntimeException('Send did not expose a recognized sent message ID.');
}
```

For a document, use `photo: false`, the original filename and the correct MIME (for example `text/plain`). The helper streams a nonempty local regular file in 65,536-byte parts and always closes it. The first part uses flags 3, a plain `peerUser`, `peerChat` or `peerChannel` without an access hash, and `totalFileSize`. Later parts use flags 0. Sending retains the original input peer and access hash. IDs, access hashes and `randomId` must be decimal strings. Below 10 MiB the helper uses `upload.saveFilePart` and `inputFile` with an empty `md5_checksum`; at or above 10 MiB it uses `upload.saveBigFilePart`, `file_total_parts` and `inputFileBig`. Only native `true` or TL `boolTrue` accepts a part. A rejected part raises `EitaaUploadException` and prevents the final send.

Photos use `inputMediaUploadedPhoto` with flags 0. Documents use `inputMediaUploadedDocument` with flags 16 (`force_file`), MIME and `documentAttributeFilename`. Both finish with `messages.sendMedia` on the same pinned media profile. The helper returns the raw updates for compatibility; it does not record application delivery status. `EitaaSentMessage::id()` accepts recognized send/update acknowledgements and never treats a nested user ID or any nonempty array as a sent message.

For low-level workflows, snapshot `$upload = $client->forUpload()` and call `$upload->sendUpload()` for **every part and the final uploaded-media operation**. Its dispatch list also supports `messages.uploadMedia`, `messages.sendMultiMedia`, `messages.editMessage`, `messages.editChatPhoto`, `channels.editPhoto` and `photos.uploadProfilePhoto`, matching the official worker's upload routing. Select that profile when these operations reference newly uploaded media. `send()` deliberately retains ordinary routing, including when called with a media method; mixing the two profiles is the caller's responsibility in low-level code.

Published old schemas are normalized locally for envelope serialization, whether they represent `eitaaObject` as a method or constructor. Unexpected envelope layouts fail explicitly. Update a customized response schema from the bundled schema to obtain modern decoding. The layer 135 Eitaa `user` (`0xecd26dcb`) uses `flags`, `flags2`, `eFlags` and Eitaa's exact field order. It is marked `decode_only` so legacy `user` encoding and bare-type preference remain unchanged; both IDs decode. The bundled updates graph includes the missing bot-menu/web-view constructors and audited flag-only layout corrections from the official worker. Unknown constructors or unread bytes still fail decoding.

## RPC and transport failures

`send()`, `sendUpload()`, `sendFile()` and `parseResponse()` raise `Disintegrations\EitaaSerializer\Exceptions\EitaaRpcException` for native `error` and `rpc_error`, including packed, gzip, RPC-result wrappers and vector-returning methods. This intentionally changes the old behavior that returned error arrays. Use `$exception->rpcCode`, `$exception->constructor` and `$exception->classification()` (`session` for 401/403, `rate_limit` for 420/429, `transient` for 5xx, otherwise `rpc`). Provider error text is omitted because it can contain private paths or sensitive values. Consumers needing raw TL error objects can use `TlDeserializer` directly.

An exact `FLOOD_WAIT_<seconds>` provider signal is classified as `rate_limit` even when the RPC code is 400. Its positive, bounded numeric delay is exposed as `$exception->retryAfterSeconds`; other errors leave this property `null`. Raw provider text is never retained in the exception. Respect that delay before another request; the client does not sleep or retry automatically.

HTTP failures remain Laravel `RequestException` with the HTTP status, but its response body and headers are removed from the exception to avoid leaking diagnostics. Connection failures remain Laravel connection exceptions; TL/schema failures remain distinct decoding/serialization exceptions. Timeouts are unchanged. Do not log credentials, peer access hashes, upload bytes or raw responses. The package does not implement application success accounting. In particular, a decoding failure after a final send can occur after delivery: retain the same `random_id`, inspect history and stop if ambiguous.

## Authorized delivery smoke test

Existing `EITAA_RUN_INTEGRATION=1 composer test:integration` still only enables the no-auth tests. Sending requires **both** opt-ins and a private handoff-style fixture outside the repository:

```bash
EITAA_RUN_INTEGRATION=1 EITAA_LIVE_SEND=1 \
EITAA_LIVE_FIXTURE=/private/handoff/credentials.private.json \
vendor/bin/pest --group=authenticated-media
```

The fixture supplies `expires_at_utc`, `token`, `imei`, the authorized `recipient.input_peer` / `recipient.contact_id`, `authorization.recipient_contact_id`, client/upload endpoints, legacy-text/verified-media layers and media envelope flags, plus photo/document relative paths, original filenames, MIME types and SHA-256 hashes. The test checks expiry and attachment hashes before network activity. It sends one labeled text, one photo and one text document to that recipient only. It stores random IDs and atomic private state (0600) beside the fixture under an exclusive lock, retains IDs across reruns and checks history after sends. Ambiguous acknowledgements stop the run without resending; bounded retries only repeat history reads. Keep the same state file across retries and refreshed exports; do not delete it to retry a send. An expired session requires an owner refresh/re-export, never automatic login. Output contains only sanitized API/history outcomes and message IDs. Provider acknowledgement and history confirmation do not establish human receipt.
