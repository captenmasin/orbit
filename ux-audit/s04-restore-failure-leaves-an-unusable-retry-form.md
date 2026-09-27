# S04 — Restore failure leaves an unusable retry form

Priority: P2

Area: settings

Status: Planned

Evidence: Source — BackupController.php:139–153; WorkspaceBackups.vue:141–145,190

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-333)

## Problem

The backend consumes the staged preview before restoring. A wrong re-entered password leaves the summary and enabled form although retry needs a new preview. The one-use staging boundary is appropriate; the UI should recover by creating a fresh preview rather than retrying an already-consumed operation.

## Implementation plan

1. Trace the consume point in `app/Http/Controllers/BackupController.php::applyRestore`. Preserve one-use staging and its workspace/file fingerprint checks.
2. In `resources/js/components/WorkspaceBackups.vue`, invalidate the active actionable preview and reset replacement consent after a failed application that cannot reuse staging. Conservative invalidation is appropriate when consumption is uncertain.
3. Show a concise failure explanation and an existing-style Preview again action that returns to the preview-password/file-selection flow. Keep password values cleared at the current boundaries.
4. Successful preview creates fresh consent; successful replacement retains the existing reload behavior. Do not automatically repeat destructive operations.
5. Update `tests/workspace-backups.test.mjs` so failures expect recovery rather than retained actionable staging; extend endpoint consumption/re-preview checks in `tests/Feature/BackupExportTest.php`.

## Acceptance criteria

- [ ] A failed consumed restore cannot offer an unusable Replace workspace retry.
- [ ] Preview again clearly starts fresh validation.
- [ ] The previous checkbox consent and application password are cleared.
- [ ] File/workspace-change protection and one-use staging remain enforced.

## Verification

In a disposable workspace, validate a backup, enter the wrong application password and follow Preview again. Repeat after changing the workspace/file and with a simulated request failure. Confirm no replacement occurs before a new successful preview and renewed consent.

Run `node --test tests/workspace-backups.test.mjs` and `php artisan test --compact tests/Feature/BackupExportTest.php`.

## Related plans

- [S01 — Restore can leave the previous backup actionable](s01-restore-can-leave-the-previous-backup-actionable.md)
