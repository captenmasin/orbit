# S06 — Invisible edits can disable update installation

Priority: P2

Area: settings

Status: Implemented

Evidence: Source — Settings.vue:40,126,173; settings-preferences.test.mjs:258

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-345)

## Problem

Restart and install checks all settings drafts, but About gives no explanation or route to the section with unsaved changes. The current guard prevents unsafe restarts, but the disabled action leaves users with no visible way to resolve the blocker.

## Implementation plan

1. In `resources/js/pages/Settings.vue`, derive the dirty section names from the existing form registry and section labels used by navigation. Reuse S05's authoritative dirty-state policy rather than creating a second draft tracker.
2. Under the downloaded-update status, show “Save or discard changes in [section] before restarting.” Render each affected section as an accessible action using S10's section navigation.
3. Distinguish dirty drafts from actively saving requests. Display a short waiting message for in-flight saves instead of incorrectly describing them as discarded work.
4. Keep the existing install guard, downloaded-state requirement and restart confirmation. Clearing the final blocker should make the existing button available immediately.
5. Extend `tests/settings-preferences.test.mjs` for multiple dirty sections, navigation to the blocker, discard/save resolution and failed saves.

## Acceptance criteria

- [x] Every dirty-preference blocker is named beside the disabled installation action.
- [x] A user can navigate directly to the responsible section.
- [x] Save or deliberate discard re-enables installation when no blocker remains.
- [x] Failed saves and active requests do not allow premature installation.

## Verification

With an update downloaded, change General and Tools without saving, open About and follow each named section. Save one and discard the other; then repeat with a rejected save. Confirm restart still requires confirmation after the blocker is resolved.

Run `node --test tests/settings-preferences.test.mjs`. Existing backend restart locking is unchanged and needs no expanded suite for explanatory UI.

## Related plans

- [S05 — Settings sections handle drafts differently](s05-settings-sections-handle-drafts-differently.md)
- [S07 — Mid-save edits can be marked saved falsely](s07-mid-save-edits-can-be-marked-saved-falsely.md)
- [S10 — Settings navigation has no URL or history](s10-settings-navigation-has-no-url-or-history.md)


## Completion — 28 September 2026

Update installation lists dirty/saving section blockers with direct navigation and save/discard recovery.

Checks: tests/settings-preferences.test.mjs; tests/settings.test.mjs; tests/workspace-backups.test.mjs; tests/Feature/WorkspacePreferencesTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
