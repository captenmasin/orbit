# C08 — Secret export cannot recover its consumed preview

Priority: P2  
Area: assets  
Status: Implemented
Evidence: Source — SecretController.php:192–204; ProjectSecrets.vue:404,644–650  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-195)

## Problem

The export endpoint consumes its staged preview before attempting the write. Failure leaves a client preview and an Export button that cannot recreate the server state. Users must cancel and restart without an explicit recovery path, even though only destination metadata and selected names need retention.

## Implementation plan

1. In `resources/js/components/ProjectSecrets.vue`, invalidate `exportPreview` and reset overwrite confirmation after an export attempt fails with a consumed or uncertain preview; retain only environment and selected-name metadata.
2. Add an explicit Preview again / Change destination action that calls the existing export-preview request and native picker. Restore the final Export button only after a fresh successful preview.
3. Keep existing checks in `app/Http/Controllers/SecretController.php` for preview ownership, destination changes, project revision and overwrite consent. Do not reuse stale server state or cache plaintext values client-side.
4. Present failure guidance beside the recovery action. Extend `tests/project-secrets.test.mjs` for write failure, consumed preview, failed picker and successful fresh-preview retry.

## Acceptance criteria

- [x] Failed export offers an explicit fresh-preview/destination step.
- [x] Environment and selected names survive recovery while plaintext is never retained.
- [x] A consumed preview cannot remain actionable as a valid final export.
- [x] A new destination requires its own overwrite confirmation.

## Verification

Choose a destination, then force a write failure or change the file between preview and export. Confirm Export is no longer available from the stale preview. Preview again, choose a valid destination and complete the export with the original metadata selection. Cancel the recovery picker and verify no write occurs and no secret values appear in page state.

After implementation, run `node --test tests/project-secrets.test.mjs` and `php artisan test --compact tests/Feature/ProjectSecretTest.php`.

## Related plans

- [C09 — Secret overwrite identifies only a filename](c09-secret-overwrite-identifies-only-a-filename.md)
- [S04 — Restore failure leaves an unusable retry form](s04-restore-failure-leaves-an-unusable-retry-form.md)

## Completion — 28 September 2026

Every export attempt invalidates its one-use preview; failed exports retain metadata choices and offer a fresh destination preview.

Checks: tests/project-secrets.test.mjs; tests/Feature/ProjectSecretTest.php; tests/Feature/SecretVaultTest.php; tests/Feature/EnvFileTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
