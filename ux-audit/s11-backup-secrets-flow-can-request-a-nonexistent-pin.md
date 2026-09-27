# S11 — Backup secrets flow can request a nonexistent PIN

Priority: P2

Area: settings

Status: Planned

Evidence: Source — WorkspaceBackups.vue:99,169–170; SecretVaultController.php:54

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-377)

## Problem

Include project secrets reveals a PIN field even before PIN setup. Export then fails with Set up a PIN first. Backup setup should explain the prerequisite before users enter a PIN or choose an export destination.

## Implementation plan

1. In `resources/js/pages/Settings.vue`, pass the existing `pinConfigured` state into `resources/js/components/WorkspaceBackups.vue` when rendering Backups. Treat unavailable PIN status separately from a confirmed missing PIN.
2. When Include project secrets is selected and no PIN exists, replace the PIN input with concise setup guidance and an existing-style link to S10's Security destination.
3. Enable a secret-inclusive export only when PIN setup is confirmed. Keep ordinary secret-free export available, and keep native-only restrictions visible.
4. For unavailable PIN status, show recoverable guidance rather than pretending a PIN exists. Refresh status when returning after setup, using the existing Settings/vault status flow; keep inactive `resources/js/pages/Backups.vue` compatible with the component's prop contract.
5. Extend `tests/workspace-backups.test.mjs` for missing, available and unavailable PIN states while retaining wrong-PIN and successful-unlock checks.

## Acceptance criteria

- [ ] A missing PIN produces setup guidance before any unlock request.
- [ ] Security navigation leads to the actual setup controls.
- [ ] Secret-free backups remain usable without a PIN.
- [ ] Existing PIN validation, rate limiting and clearing remain intact.

## Verification

Select Include project secrets before setup, follow Security, create a PIN and return. Confirm the PIN input becomes available and export still requires a correct PIN. Repeat with unavailable PIN status, a wrong PIN and a secret-free export.

Run `node --test tests/workspace-backups.test.mjs tests/settings-preferences.test.mjs`.

## Related plans

- [S10 — Settings navigation has no URL or history](s10-settings-navigation-has-no-url-or-history.md)
