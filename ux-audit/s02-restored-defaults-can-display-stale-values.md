# S02 — Restored defaults can display stale values

Priority: P1

Area: settings

Status: Implemented

Evidence: Source — WorkspaceBackups.vue:144; Settings.vue:35–58; WorkspaceRestore.php:294

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-320)

## Problem

Restore reloads props but preserves Settings form state. Incoming board defaults may not appear; saving the old visible defaults can overwrite the restored ones. Current portable restore changes project-default columns, while ordinary reloads should preserve unrelated unsaved settings.

## Implementation plan

1. In `resources/js/components/WorkspaceBackups.vue`, distinguish a confirmed restore from an ordinary prop refresh and report completion to its Settings parent after fresh preferences arrive.
2. In `resources/js/pages/Settings.vue`, copy confirmed restored `project_defaults.columns` into the board form and saved defaults, clear its remembered draft, then synchronize revision. Clone columns as initialization does.
3. Refresh only portable defaults during that explicit restore path. Keep General, Appearance, Security and Tools drafts on ordinary reloads; retain the existing saved appearance preview state.
4. Resolve dirty board defaults through S05's Save/Discard before beginning preview staging; finish Save before previewing. Any preference save after staging invalidates the active preview and requires fresh validation before replacement.
5. Extend `tests/settings-preferences.test.mjs` and `tests/workspace-backups.test.mjs` to distinguish successful restore, failed restore and normal reloads with unrelated drafts.

## Acceptance criteria

- [x] Incoming board defaults appear immediately after successful restore.
- [x] Their baseline is clean; Save or history recovery cannot silently reapply pre-restore columns.
- [x] Ordinary reloads preserve unrelated drafts and their dirty state.
- [x] Failed or cancelled restores do not reset existing settings.
- [x] Draft decisions precede staging; saving preferences afterward requires a new preview.

## Verification

Resolve a dirty board-default draft, then preview/restore different columns and inspect their saved baseline. Save preferences after staging and confirm a fresh preview is required. Repeat with an unsaved Tools draft, failed restore and older backup that preserves current defaults.

Run `node --test tests/settings-preferences.test.mjs tests/workspace-backups.test.mjs` and `php artisan test --compact tests/Feature/WorkspaceRestoreTest.php`.

## Related plans

- [S05 — Settings sections handle drafts differently](s05-settings-sections-handle-drafts-differently.md)
- [S07 — Mid-save edits can be marked saved falsely](s07-mid-save-edits-can-be-marked-saved-falsely.md)

## Completion — 28 September 2026

Confirmed restore reloads fresh preferences before resetting only portable board defaults. Draft decisions precede staging; revision changes invalidate previews.

Checks: tests/workspace-backups.test.mjs; tests/settings-preferences.test.mjs; tests/Feature/BackupExportTest.php; tests/Feature/WorkspaceRestoreTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
