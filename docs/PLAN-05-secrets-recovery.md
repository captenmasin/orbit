# Milestone 5 — Secrets and portable recovery

Status: planned. Written 15 September 2026. Depends on completed provider activity and its native credential-encryption foundation. This is an implementation plan, not a claim of a working vault or backup format.

Parent: [Product plan](../PLAN.md). Previous: [GitHub/GitLab](PLAN-04-provider-activity.md). Next: [Public beta](PLAN-06-public-beta.md).

## Outcome

A developer can store project secrets by environment, reveal or copy a selected value, import/export a supported `.env` file deliberately, and recover their workspace on another macOS account or machine from a password-protected backup.

The exit demonstration exports an encrypted workspace containing a multiline secret, restores it under a different macOS login, reconnects provider accounts, relinks moved folders, and verifies project data, task ordering, icons, and secret values. Wrong passwords, corrupt input, and interrupted restore leave the current workspace usable.

## Scope and assumptions

- Environment grouping and manual `.env` import/export remain the parent plan's provisional defaults. Start with a `Default` environment and allow user-defined names; do not force a development/staging/production taxonomy.
- Values are UTF-8 text, including empty strings and multiline PEM content. Binary attachments, secret generation, cloud sync, sharing, automatic `.env` synchronization, and a password-manager extension are excluded.
- Backup/restore is explicitly initiated by the user. Restore replaces the local workspace; merging two workspaces is outside v1.
- Provider credentials stay protected by the milestone 4 foundation and are never exported in portable backups. Restored provider associations require reconnection.
- Keep Laravel, SQLite, NativePHP, Inertia/Vue, sidebar-07, and default shadcn styles. Recheck installed APIs, bundled PHP capabilities, and applicable repository guidance before coding.

## Protection model

Use NativePHP's existing `System::canEncrypt/encrypt/decrypt` operations for values stored on the current machine. The installed implementation delegates to Electron `safeStorage`; do not introduce a second native bridge or use a shared packaged `APP_KEY` as the vault key.

At-rest native encryption and password-based portable backup encryption solve different problems. Copying machine-bound ciphertext to another account is not a recovery strategy. On restore, decrypt the backup with its password and encrypt each secret again using the destination account's native encryption. Keychain availability must be established before applying an import containing secrets. [Electron safeStorage](https://www.electronjs.org/docs/latest/api/safe-storage).

Do not promise protection against malicious code already running as the logged-in user, or guaranteed erasure of every memory copy. Keep plaintext lifetimes short and out of persistent channels that Orbit controls. No telemetry contains project data or secret values.

## Storage changes

| Record | Proposed schema/behavior |
| --- | --- |
| `project_secrets` | UUID, project FK with cascade, environment, name, ciphertext, revision, timestamps; unique project/environment/name |
| Access events | Extend milestone 4's metadata-only events with secret operations and outcomes; no value, import content, backup password, or raw exception |
| Restore state | One small durable recovery record identifying staged assets/rollback snapshot and the apply phase; no plaintext password |

Use case-sensitive secret names. For `.env` compatibility, start names at `[A-Za-z_][A-Za-z0-9_]*`, maximum 255 characters. Environment names are trimmed nonempty strings, maximum 100 characters, with a documented case policy. Start text values at a one-megabyte UTF-8 limit and return a useful error for oversized/binary input.

Use independent secret revisions for value edits/deletions. Secret metadata changes also participate in project removal protection: a stale project delete must not erase newly saved secrets. Derived provider/local refreshes remain outside user-edit revision checks.

Hide ciphertext from model serialization and select only metadata for lists. Never implement an automatic decrypting accessor/cast, because an ordinary model serialization must not reveal values. Keep private files and database directories restricted to the current user.

Use one bounded access-event stream across credential and secret operations. Proposed retention is the newest 1,000 events for the workspace. Record operation, record ID, time, and a small outcome code; descriptions, names, paths, and values are unnecessary unless a concrete UI need justifies them.

## Secret operations and interface

Add Secrets to the project tabs. List name, environment, and update time. Support environment filtering, metadata search within this tab, Add, Edit, Delete, Reveal, and Copy. Do not add secret names or values to global project search.

### Create/edit/delete

1. Show a default shadcn dialog with name, environment, and a multiline value field.
2. Submit through a transient request. Exclude sensitive fields from Laravel old-input flashing, Inertia remembered state, browser history, client storage, validation responses, request capture, and logs.
3. Preserve the value's bytes: disable `TrimStrings` and empty-string-to-null conversion for the secret value only. Validate UTF-8 and length without changing leading/trailing whitespace or line endings.
4. Encrypt before committing a create/update. In one transaction, verify expected revisions, persist ciphertext, and record the operation metadata. On failure, retain the old value and revision.
5. Metadata editing need not fetch the value. Offer a separate explicit “Replace value” action. If editing an existing value requires decrypting it, treat that as an explicit reveal and record it.
6. Deleting requires an explicit confirmation naming the secret/environment, followed by the revision-checked write. Cascades on project removal are intentional and covered by the project's confirmation.

### Reveal/copy

Reveal fetches only the selected secret through a dedicated no-store response after a user action. Keep the result in transient component memory. Clear it on close, navigation, app/window deactivation, and after a short display timeout; never place it in shared page props or remembered form state.

Copy decrypts server-side and uses the NativePHP clipboard operation when possible, so it need not also expose plaintext in the renderer. Proposed clipboard lifetime is 30 seconds: clear only if the clipboard still contains the value Orbit copied. Do not erase a newer user clipboard value or claim control over third-party clipboard history. A failed clipboard write must not report success.

An unavailable Keychain/bridge yields an actionable error. It must not produce an empty successful reveal, overwrite ciphertext, or fall back to plaintext storage. Use dummy values for development/test verification.

## Manual `.env` import/export

### Import

- Use the native file picker, read a bounded local file, and show the source name, entry names, environment, and collisions before saving. Values remain concealed unless individually revealed.
- Start with a one-megabyte file limit and 1,000 entries. Reject unreadable files, unsupported encodings, NUL bytes, invalid names, malformed quoting, and unsupported syntax with line numbers that do not include secret text.
- Inspect the installed dotenv parser before adopting it. Use parsing only: no loading into the process environment, variable resolution, command execution, or `eval`.
- Define a supported literal grammar: optional `export`, assignments, comments outside quoted values, empty values, quoted/unquoted strings, and multiline quoted values. `${NAME}` and command-like text must remain literal. Document escape rules and verify round trips with the selected parser; reject ambiguous syntax rather than guessing.
- Show duplicate names within the file as explicit conflicts. Do not silently select the first or last occurrence. For existing secrets, default to Skip; the user can choose Replace for selected entries.
- Bind the preview to file content and the target project's revisions. Re-read/recheck before applying if the file is not retained solely in transient memory. A changed file or edited secret requires a fresh preview.
- Encrypt every selected value first. Apply all validated changes and event metadata in one transaction. A failure partway through encryption or persistence saves none of the import.

### Export

- Select one environment and explicit secret names, then choose a destination with the native save dialog. Explain that `.env` export writes plaintext.
- Confirm replacement when the destination exists. Recheck its identity before writing; an intervening change must not be silently overwritten.
- Serialize using the supported literal grammar and verify that importing the result preserves empty strings, whitespace, quotes, dollar signs, backslashes, and multiline values. Do not copy internal ciphertext into the file.
- Write a private temporary file in the destination directory, flush it, and atomically replace the intended destination only after the full write succeeds. Preserve the old destination on failure. Remove temporary plaintext on cancellation/failure and protect it with mode 0600 while it exists.
- Never choose a repository's live `.env` automatically or create an ongoing link to it. Record export metadata without recording the exported values.

## Portable backup format decision

The starting proposal is one password-encrypted, versioned `.orbitbackup` container for every backup, including those that omit secrets. This avoids maintaining separate plaintext and encrypted restore paths. The user chooses whether project secrets are included; default that choice to off.

Use the PHP sodium extension if it is present in the actual NativePHP bundled binary. Use Argon2id password derivation and libsodium's XChaCha20-Poly1305 secretstream construction; do not implement ciphers, MACs, nonce generation, or a password hash as an encryption key by hand. Sodium's PHP API includes the required password and stream primitives. [PHP sodium](https://www.php.net/manual/en/book.sodium.php), [libsodium password derivation](https://libsodium.gitbook.io/doc/password_hashing/default_phf), [authenticated secret streams](https://doc.libsodium.org/secret-key_cryptography/secretstream).

Before shipping a writer, complete a format prototype and freeze a documented version-one specification:

| Part | Required contract |
| --- | --- |
| Public header | Magic/version, algorithm/profile identifiers, salt, concrete KDF parameters, secretstream header; small fixed maximum size |
| Authentication | The exact public-header bytes are authenticated with the encrypted stream; unknown algorithms/profiles are rejected |
| Payload | Ordered, typed JSON records for metadata and application records; icon bytes encoded with explicit size/type information |
| Framing | Bounded length-prefixed encrypted records; bounds checked before allocation or decoding |
| Completion | A required authenticated final record, consistent record counts, and end-of-file; missing final data or trailing material is rejected |
| Compatibility | Explicit backup schema version independent of app version; tested readers for supported versions, clear rejection of newer unsupported versions |

Use fresh cryptographic randomness for salt and stream initialization. Store the concrete KDF parameters used; never assume future library constants reproduce an older file. Benchmark named parameter profiles on the lowest supported machine and allowlist bounded profiles on read before deriving a key, preventing hostile headers from requesting excessive memory/CPU. Final profile values and resource limits are a prerequisite for freezing the format, not silently chosen by the decoder.

Start prototype bounds at 256 MB total decoded data, 100,000 records, and 4 MB per record, with existing icon limits still enforced. Report limits clearly and revise them from measured fixtures before release. Keep the format simple: no arbitrary archive extraction, executable payloads, or filesystem paths supplied as write destinations.

The format is an Orbit data container using established cryptographic operations. Have its framing, authentication, failure handling, and cross-profile behavior reviewed before calling portable recovery complete. If bundled sodium support is absent, resolve a maintained supported library/binary and its packaging deliberately; do not substitute weaker protection.

## Backup contents and consistency

Include projects and archive state; tags and associations; boards, columns, tasks and ordering; repository/folder records; configured package roots; links and ordering; icons; and optional project secret values. Include provider association metadata only as disconnected hints. Omit provider tokens, native ciphertext, source files, caches, queued/failed jobs, sessions, logs, and historical access events.

Treat local paths and executable overrides as portable metadata that requires revalidation. After restore, missing paths prompt relinking, executable overrides are inactive until reviewed, and background scans/provider calls do not start automatically from imported configuration.

Read a consistent SQLite snapshot using the supported backup/snapshot mechanism, including any WAL state. Do not copy a live database file blindly. Coordinate icon reads with catalog changes so an icon cannot disappear halfway through export. SQLite provides an online backup mechanism for consistent copies. [SQLite backup API](https://www.sqlite.org/backup.html).

Keep any local snapshot private; its stored secret columns remain machine-encrypted. Decrypt individual selected secrets only while streaming them into the password-encrypted output. Never write a plaintext secret dump or serialize a password/value into a queue job. Passphrases remain transient, are confirmed on export, are never stored, and are cleared on cancellation/completion.

Write the encrypted output to a sibling temporary file and atomically publish it when complete. A cancelled export, full disk, or crypto failure leaves the prior destination intact. Report only a fully finalized file as a successful backup.

## Restore workflow and crash safety

1. **Choose and unlock.** Select a backup and enter its password. Validate format, size, KDF profile, and framing limits before expensive work. Wrong password/corruption produces a safe failure without altering the workspace.
2. **Authenticate and stage.** Verify the complete stream and final marker. Validate every record's types, IDs, uniqueness, relationships, ordering, URL schemes, icon content, and supported schema version. Use private staging storage and a fresh staging database; encrypt imported secret values immediately with the destination's native encryption.
3. **Preview.** Show backup date, project/task/secret counts, whether secrets are included, and the fact that applying replaces the current workspace. Show provider reconnection and missing-folder requirements. A backup without secrets also replaces/removes current secrets; do not conceal that consequence.
4. **Quiesce.** After explicit confirmation, obtain a workspace maintenance lock. Every write path and background result commit must honor it. Stop/drain scan/provider workers and reject new writes before applying. Compare the current user-record IDs and revisions with the set captured for the preview; any changed, added, or removed record requires a fresh preview.
5. **Preserve rollback data.** Create a verified private local database snapshot and retain current icon assets. Refuse to proceed if there is insufficient space or rollback preservation fails. This local safety copy contains machine-encrypted credentials and is distinct from a portable backup.
6. **Prepare assets.** Promote validated new icons to a new private directory with application-generated paths, leaving old icons in place. Do not use incoming absolute paths, traversal segments, or symlinks as extraction destinations.
7. **Apply one database transaction.** Replace allowlisted application records in FK-safe order, referring to the prepared assets. Do not replace SQLite system tables, app sessions, jobs, migrations, or arbitrary imported SQL. Avoid model creation hooks that would duplicate default board columns during import. Run relation/integrity checks before commit.
8. **Invalidate old work.** Renew revisions/generations so a pending form or job from the pre-restore workspace cannot overwrite restored data. Clear derived snapshots and queued old work, then reload the application state without retaining secret-bearing history.
9. **Finish and recover.** Use a small durable restore-state record to distinguish prepared, applied, and verified phases. On a crash before database commit, keep the old workspace; after commit, finish verification/cleanup against the new workspace. Retain rollback data until the restored workspace passes a normal relaunch. Interrupted cleanup must not remove assets referenced by the active database.

Atomic database replacement and prewritten immutable asset directories avoid pretending that several filesystem renames form one transaction. Reuse existing application paths; do not replace NativePHP's database locator. Resume workers only after restored roots/credentials are safe to use.

## Implementation sequence

1. **Close the encryption foundation.** Inspect the milestone 4 credential operation and redaction rules. Prove exact text round trips and failure preservation with dummy values in a package.
2. **Add secret storage and commands.** Implement scoped metadata lists, independent revision guards, create/replace/delete, explicit reveal/copy, bounded events, and sensitive-input exclusions.
3. **Build the Secrets tab.** Use default components and transient value state; test keyboard flow, focus return, deactivation clearing, and clipboard behavior.
4. **Implement literal `.env` parsing and writing.** Specify the supported grammar with fixtures, then add preview/collision handling and atomic destination writes.
5. **Prototype portable encryption.** Verify bundled sodium, freeze the format and KDF profiles, and establish cross-account round trips before exposing restore.
6. **Export consistent workspaces.** Add snapshot/asset coordination, selected-secret inclusion, bounded streaming, native destinations, and cancellation cleanup.
7. **Stage and apply restore.** Add validation, preview, write maintenance, rollback preservation, FK-safe import, asset handling, and crash-phase recovery.
8. **Verify cross-profile recovery.** Restore a real packaged backup under another macOS login, reconnect providers, relink folders, and compare all retained records and values. Update the existing integration record and parent milestone only with observed evidence.

## Verification matrix

| Boundary | Required cases |
| --- | --- |
| Values | Empty, whitespace-only, Unicode, quotes, literal interpolation text, multiline PEM, size limit, invalid encoding |
| Encryption | Unavailable bridge/Keychain, null result, corrupted ciphertext, failure preserves existing value |
| Writes | Cross-project IDs, stale value edits/deletes, project deletion after a newer secret write, duplicate names |
| Disclosure | No plaintext in props, history, sessions, logs, validation, jobs, events, or normal database fields |
| Reveal/copy | Explicit action only, no-store response, clear on close/deactivation, clipboard changed by another app |
| `.env` | Comments, quoting, CRLF/LF, empty and duplicate entries, literal `${...}`/command text, collision choices, changed source/destination |
| Backup integrity | Wrong password, bit flip, truncated/reordered/duplicate/missing records, missing final marker, trailing data, future version, hostile KDF/size fields |
| Restore validation | Bad FKs, duplicate IDs, invalid order, unsafe URLs, hostile paths, bad icons, unsupported executable overrides |
| Failure recovery | Disk full, encryption failure, worker race, crash before/after commit, cleanup interruption; old or new complete workspace remains |
| Portability | Different account/home/Keychain; local ciphertext re-encrypted; provider tokens absent; folder relinking preserves data |

Use focused PHPUnit tests with native facade/HTTP doubles for controllable failures and real cryptography for format round trips. Packaged tests are mandatory for native encryption, clipboard, file dialogs, permissions, restart, and cross-profile recovery. Never use production secrets in fixtures or screenshots.

## Completion checklist

- [ ] Secrets preserve exact values and use native at-rest encryption with no plaintext fallback.
- [ ] Explicit reveal/copy and redaction behavior pass automated and desktop checks.
- [ ] `.env` preview/collisions/export round trips work without evaluating input.
- [ ] The backup format, KDF profiles, limits, and compatibility policy are documented and reviewed.
- [ ] Optional-secret backups contain all intended project/board metadata and no provider tokens/source files.
- [ ] Wrong passwords, malformed backups, and partial failures never replace a good workspace.
- [ ] Restore succeeds under a different macOS account and survives normal restart.
- [ ] Catalog/board ordering, icons, links, roots, and relinking survive recovery.
- [ ] Formatting, focused tests, production build, and packaged evidence are recorded.

Proceed to public beta only after the recovery demonstration passes. If another macOS profile or required packaging capability is unavailable, keep that check open rather than treating a mock or empty app-data directory as equivalent.
