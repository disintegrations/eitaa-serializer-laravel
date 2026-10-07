# Eitaa media protocol evidence

Compared against the public [Eitaa worker](https://web.eitaa.com/mtproto.worker-Zk-UnVZ2.js) downloaded on 2026-10-07 and the extracted modern user definition in the private handoff. These are Eitaa layouts, not substituted Telegram definitions.

The official `eitaaObject` appears as both a method and a constructor, ID 2059302893, with `token:string`, `imei:string`, `packed_data:bytes`, `layer:int`, `flags:int`. Web requests use layer 135 and flags 128. The package retains ordinary layer 133 calls and selects that modern profile explicitly for uploads. Default ordinary flags are 0; legacy envelope omission is configurable.

The upload dispatch sites mark file parts, fresh uploaded-media sends, uploadMedia, grouped sends, edits with fresh media, chat/channel photo edits and profile-photo uploads as `fileUpload`. The package exposes those via `sendUpload()` rather than automatically routing every media method. The worker chooses the upload networker; the library pins a configured endpoint and never changes it during the helper's transaction.

The worker's small and big part methods have the same IDs and wire fields as the bundled methods. The first part carries plain `Peer` and total size; conditional bits are 0 and 1. Big files start at 10,485,760 bytes. The worker computes an adaptive limit via `getLimitPart`; this package's supported helper uses the verified 65,536-byte part size. It does not claim adaptive uploads or all file-size limits. Small input files have an empty checksum. The worker also contains a separate preliminary small-part request for some big uploads; the deterministic boundary tests validate this package's specified big-part recipe, not a live 10 MiB delivery.

The constructor audit followed nested types reachable from `User`, `Updates`, `Message`, `MessageMedia`, `Photo`, `Document` and history's `messages.Messages`:

| Finding | Package handling |
| --- | --- |
| `user` -321753653 / 0xecd26dcb | Exact Eitaa order: flags, flags2, eFlags, existing fields, Eitaa mini-app/badge fields, optional bot_active_users. Decode only; old user ID 1073147056 remains the encoding choice. |
| `updateBotMenuButton` 347625491 | Added with nested BotMenuButton support. |
| `updateWebViewResultSent` 361936797 | Added exact query_id layout. |
| `botMenuButton` -944407322 | Added exact text/url layout; existing empty/default variants retained. |
| `chat`, `channel`, `message` | Existing IDs/byte fields match; added official noforwards flag booleans. |
| `chatAdminRights`, `dialogFilter` | Added official post_live/admin flag booleans. |
| `chatBannedRights` | Official embed_links uses bit 12, plus view_participants bit 13 and send_forwarded_messages bit 11. Same ID, no added wire bytes. |
| updates / updatesCombined / updateShortSentMessage, updateNewMessage / updateNewChannelMessage, messageMediaPhoto / messageMediaDocument, photo / document, messages.messages / messages.messagesSlice | Existing layouts match the worker. |
| Modern user nested photo/status/restriction types | Existing referenced types match; no substitute layouts required. |

`decode_only` is a package schema indexing hint: a definition remains addressable by ID for decoding but is excluded from predicate and bare-type encoding lookup. Other duplicate predicates retain the existing last-definition encoding behavior.

`tests/Fixtures/send-updates-layer-135.hex` is a synthetic, independently assembled little-endian binary fixture. It contains updates with a message-ID correlation, an outgoing message and a modern user with all three flag words, signed 64-bit synthetic values, status, Eitaa badge flags and bot_active_users. It contains no exported account data. Tests require complete byte consumption and keep unknown constructors as decoding failures.

The verified media combination and the package's new live smoke establish delivery; they do not prove which individual protocol change caused the historical 500 error. Application delivery accounting is outside this package.
