# S16 — Backup password rules appear only on failure

Priority: P3

Area: settings

Status: Planned

Evidence: Live + source — WorkspaceBackups.vue:171–188; BackupController.php:169

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-409)

## Problem

The 12-character minimum is enforced without visible help or a reminder that the password is needed for restore. The export form asks users to choose a password without telling them what makes it valid or why they must retain it.

## Implementation plan

1. In `resources/js/components/WorkspaceBackups.vue`, add visible FieldDescription text beside Backup password: “At least 12 characters. Keep this password; it is required to restore this backup.”
2. Associate the description with the existing password input using the current field/accessibility conventions. Keep confirmation separately labeled and preserve native validation.
3. Give restore and replacement-password inputs concise contextual guidance: use the password for the selected backup, not the Secrets PIN. Reuse the source-file identity established by S01 when available.
4. Check the wording against `app/Http/Controllers/BackupController.php::takePassword` so the stated minimum matches actual validation. Keep PIN requirements, password confirmation and clearing behavior intact.
5. Inspect placement around error messages and long paths at narrow widths, using existing supporting-text styling rather than introducing another help component.

## Acceptance criteria

- [ ] The minimum length is visible before export submission.
- [ ] Users are told to retain the password for restoring this backup.
- [ ] Restore instructions distinguish backup password from Secrets PIN.
- [ ] Existing validation, confirmation and password clearing remain unchanged.

## Verification

Inspect export with secrets both included and excluded. Try a short password and mismatched confirmation; check that errors remain readable beside the guidance. Inspect restore and replacement steps and confirm the required password is unambiguous.

No automated tests are required for this copy-only change. Preserve the existing input limits and backend validation instead of adding tests that repeat the wording.

## Related plans

- [S01 — Restore can leave the previous backup actionable](s01-restore-can-leave-the-previous-backup-actionable.md)

