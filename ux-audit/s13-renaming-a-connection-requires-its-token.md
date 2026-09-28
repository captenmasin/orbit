# S13 — Renaming a connection requires its token

Priority: P2

Area: settings

Status: Implemented

Evidence: Source — ProviderConnections.vue:25,69,76–81

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-390)

## Problem

The Label field is available only through Replace token, where token is mandatory. Simple renaming requires retrieving credentials again. Renaming is metadata maintenance and should not require resubmitting, verifying or replacing the saved credential.

## Implementation plan

1. In `resources/js/components/ProviderConnections.vue`, add a clearly labeled Edit label action using the existing dialog, Input and revision-backed HTTP patterns. Keep Replace token separate.
2. Add a label-only controller action in `app/Http/Controllers/ProviderController.php` and its route in `routes/web.php`. Validate the label using existing length rules and enforce the connection revision.
3. Update only the label and revision after a successful comparison. Keep encrypted token, provider/account identity, verification timestamps, repository associations and activity snapshots intact.
4. Refresh the connection list after success. Retain the label draft and show actionable conflict/validation/network feedback on failure; cancellation changes nothing.
5. Extend `tests/Feature/ProviderCredentialTest.php` for metadata-only success, stale revision and invalid label; extend `tests/provider-connections.test.mjs` for the separate dialog/submission flow.

## Acceptance criteria

- [x] A connection can be renamed without retrieving its token.
- [x] Token replacement remains a distinct explicit action.
- [x] Renaming preserves credentials, associations and cached activity.
- [x] Stale or invalid submissions cannot overwrite newer data.

## Verification

Rename a connected account and confirm repository activity still loads without re-entering credentials. Cancel a rename, enter an invalid label, then simulate another window changing the connection before submission. Confirm rejected edits remain correctable.

Run `node --test tests/provider-connections.test.mjs` and `php artisan test --compact tests/Feature/ProviderCredentialTest.php`.

After PHP edits, run `vendor/bin/pint --dirty --format agent`.

## Related plans

None.

## Completion — 28 September 2026

A dedicated label-only route/dialog performs an atomic revision-checked rename without retrieving/replacing credentials or clearing cached activity.

Checks: tests/provider-connections.test.mjs; tests/Feature/ProviderCredentialTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
