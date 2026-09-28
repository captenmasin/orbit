# S03 — Restore wording understates removed credentials

Priority: P2

Area: settings

Status: Implemented

Evidence: Source — WorkspaceBackups.vue:169,184,189; WorkspaceBackup.php:37–83; WorkspaceRestore.php:302–305

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-326)

## Problem

“Replaces provider connections” can imply incoming connections replace existing ones, but backups never include them and restore deletes all current connections. Users also need an explicit warning when the incoming backup excludes secrets: replacement removes existing secrets rather than preserving them.

## Implementation plan

1. Check the current portable contents in `app/WorkspaceBackup.php` and deletion/replacement flow in `app/WorkspaceRestore.php`. Use those actual effects as the wording boundary.
2. In `resources/js/components/WorkspaceBackups.vue`, state before confirmation that all provider connections will be removed and must be reconnected afterward. Explain that provider tokens are not included in backups.
3. Make the destructive preview warning depend on the existing `includes_secrets` summary: a backup without secrets removes current secrets; an inclusive backup replaces them with the incoming secrets.
4. Keep the existing explicit checkbox and destructive Replace workspace action. Describe board defaults separately using the existing incoming-defaults or older-backup summary.
5. Check the warning near the replacement controls at desktop and narrow widths, using shared Alert and FieldDescription styling.

## Acceptance criteria

- [x] The user sees that provider connections are removed, not imported.
- [x] Both inclusive and secret-free backups explain the fate of current secrets.
- [x] Warnings are visible before consent and remain associated with the selected backup.
- [x] Copy does not promise restoration of excluded credentials or preferences.

## Verification

Manually preview one backup including secrets, one excluding secrets and one older backup without portable defaults. Read each warning before the checkbox and confirm its description matches the existing restore behavior. Do not perform a destructive restore merely to inspect wording.

No automated tests are required for this copy-only change. Existing backup/restore behavior is outside this plan's modification scope.

## Related plans

- [S01 — Restore can leave the previous backup actionable](s01-restore-can-leave-the-previous-backup-actionable.md)


## Completion — 28 September 2026

Restore consent explains removed provider connections/reconnection and the fate of current secrets for both inclusive and secret-free backups.

Checks: tests/workspace-backups.test.mjs; tests/settings-preferences.test.mjs; tests/Feature/BackupExportTest.php; tests/Feature/WorkspaceRestoreTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
