# S19 — Security copy implies instant application

Priority: P3

Area: settings

Status: Planned

Evidence: Live + source — Settings.vue:163; SettingsController.php:102

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-428)

## Problem

Lock duration says Changing this locks secrets immediately, but selecting a value has no effect until Save. Previewing a selection and committing a security change are separate moments, and the helper should describe the moment that actually revokes access.

## Implementation plan

1. In `resources/js/pages/Settings.vue`, change the lock-duration description to “Elapsed time after unlocking. Saving a new duration locks secrets immediately.”
2. Keep the existing Lock secrets after unlock label, duration options and explicit Save action. Use the shared FieldDescription treatment and avoid implying an inactivity timer.
3. Read `app/Http/Controllers/SettingsController.php` alongside `app/SecretVault.php` to confirm revocation occurs only after a successful changed-duration save. Make no timing or storage changes for this wording correction.
4. Review the description with S05's unsaved indicator and S07's processing state so selecting, saving and failing to save have clear visible meanings.
5. Inspect the neighbouring clipboard description and PIN section to ensure the revised helper remains local to lock duration rather than suggesting every Security setting immediately locks secrets.

## Acceptance criteria

- [ ] The helper names Save as the moment a new duration takes effect.
- [ ] It accurately describes elapsed time from unlock, not idle time.
- [ ] Choosing a different option remains an unsaved draft.
- [ ] Existing saved-duration revocation and rejected-save behavior remain unchanged.

## Verification

Unlock secrets, select another duration without saving and confirm access remains available under the saved duration. Then save the changed duration and confirm the existing immediate lock behavior. Reject a save and verify the wording does not imply a successful change.

No automated tests are required for this copy-only correction. Existing security behavior is already covered by `tests/Feature/WorkspacePreferencesTest.php`.

## Related plans

- [S05 — Settings sections handle drafts differently](s05-settings-sections-handle-drafts-differently.md)
- [S07 — Mid-save edits can be marked saved falsely](s07-mid-save-edits-can-be-marked-saved-falsely.md)

