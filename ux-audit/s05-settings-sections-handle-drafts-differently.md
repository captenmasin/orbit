# S05 — Settings sections handle drafts differently

Priority: P2

Area: settings

Status: Implemented

Evidence: Source — Settings.vue:35,71–75,133,157

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-339)

## Problem

Leaving Appearance resets its draft; other sections retain hidden edits. Leaving Settings loses those drafts without a guard. Recovery must account for native Back/Forward, which cannot be cancelled.

## Implementation plan

1. In `resources/js/pages/Settings.vue`, show dirty-section indicators and local Discard actions, including launch-at-login. Retain main preference drafts across section switches; roll back Appearance's visual preview while closed without deleting its draft.
2. In `resources/js/components/WorkspaceBackups.vue`, report preferred-folder dirty state. Resolve Save/Discard/Stay before controllable departure; retain the draft after save failure.
3. Use Inertia remembering for only nonsecret preferences/folder drafts, scoped to workspace/section with saved baseline and submitted revision. On native history recovery, refresh the Settings snapshot; compare against current persisted values, not cached history props. Surface stale drafts for review rather than silently rebasing them.
4. Guard controllable replacing visits using `resources/js/components/ProjectScratchpad.vue` patterns; allow own saves and confirmed restores. Clean up navigation/unload listeners on unmount.
5. Clear only the affected section's remembered draft after save/discard; confirmed restore clears portable board-default drafts only, as S02 specifies. Always clear PIN/password/token/API-key forms on unmount; never remember sensitive inputs or keep secret forms alive to retain a folder.
6. Update `tests/settings-preferences.test.mjs` and `tests/workspace-backups.test.mjs` for transitions, recovery/conflicts, folder decisions and cleanup; explain Save/Discard and preview scope.

## Acceptance criteria

- [x] Main drafts survive section switches; controllable folder departure is explicit.
- [x] Native history recovers nonsecret drafts against current saved baselines.
- [x] Failed saves retain drafts; save/discard clears only the affected section's remembered state.
- [x] Sensitive inputs clear on unmount and never enter remembered state.

## Verification

Exercise section changes, sidebar exits and native Back/Forward with dirty preferences/folder. Change settings elsewhere, return and verify conflict review. Confirm cancelled/failed saves retain work and credential values clear on unmount.

Run `node --test tests/settings-preferences.test.mjs tests/workspace-backups.test.mjs tests/settings.test.mjs`.

Inertia v3 guidance: [remembering state](https://inertiajs.com/docs/v3/data-props/remembering-state).

## Related plans

- [S10 — Settings navigation has no URL or history](s10-settings-navigation-has-no-url-or-history.md)
- [S02 — Restored defaults can display stale values](s02-restored-defaults-can-display-stale-values.md)
- [S07 — Mid-save edits can be marked saved falsely](s07-mid-save-edits-can-be-marked-saved-falsely.md)
- [S06 — Invisible edits can disable update installation](s06-invisible-edits-can-disable-update-installation.md)

## Completion — 28 September 2026

Nonsecret preference drafts persist across sections/history with fresh-baseline reconciliation. Folder departure has explicit decisions and stale-folder review; sensitive forms still clear on unmount.

Checks: tests/settings-preferences.test.mjs; tests/settings.test.mjs; tests/workspace-backups.test.mjs; tests/Feature/WorkspacePreferencesTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
