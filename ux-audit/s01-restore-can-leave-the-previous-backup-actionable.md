# S01 — Restore can leave the previous backup actionable

Priority: P1

Area: settings

Status: Planned

Evidence: Source — WorkspaceBackups.vue:121–127,184–191; BackupController.php:120

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-314)

## Problem

After previewing A, trying B unsuccessfully keeps A’s preview and Replace workspace form. The summary does not identify its source file. The destructive form must refer to one validated candidate, including after cancellation, invalid passwords, damaged files or delayed responses.

## Implementation plan

1. In `resources/js/components/WorkspaceBackups.vue`, clear the active preview, replacement consent, password and previous application errors as soon as a new preview begins. Keep replacement unavailable during validation.
2. In `app/Http/Controllers/BackupController.php`, discard older restore staging when a new preview begins. Return the selected filename and path alongside the existing successful summary; keep passwords and decrypted content out of that response.
3. Extend the existing preview type and render the validated file identity beside its date and counts in `WorkspaceBackups.vue`. Cancellation or failure must leave no actionable preview.
4. Extend `tests/workspace-backups.test.mjs` for A-success/B-failure, B-cancellation and pending validation; extend `tests/Feature/BackupExportTest.php` for response identity and discarded old staging at the restore-preview endpoint.

## Acceptance criteria

- [ ] A validated preview visibly identifies its source file.
- [ ] Starting another preview immediately removes the earlier replacement action.
- [ ] Cancellation, incorrect passwords and invalid files cannot restore an earlier candidate.
- [ ] Consent is specific to the current validated preview; sensitive response boundaries remain intact.

## Verification

Manually preview A, then try B with a wrong password, cancel B's picker and validate B successfully. Check that the replacement action never identifies A as B and remains unavailable until B validates. Confirm a cancelled selection leaves the workspace unchanged.

Run `node --test tests/workspace-backups.test.mjs` and `php artisan test --compact tests/Feature/BackupExportTest.php`.

After PHP edits, run `vendor/bin/pint --dirty --format agent`.

## Related plans

- [S04 — Restore failure leaves an unusable retry form](s04-restore-failure-leaves-an-unusable-retry-form.md)
- [S03 — Restore wording understates removed credentials](s03-restore-wording-understates-removed-credentials.md)
