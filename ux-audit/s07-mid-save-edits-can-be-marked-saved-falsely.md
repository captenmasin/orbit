# S07 — Mid-save edits can be marked saved falsely

Priority: P2

Area: settings

Status: Planned

Evidence: Source — Settings.vue:80–92,153–167; installed useHttp data serialization

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-352)

## Problem

Some selects remain editable during save. Success marks current values as defaults even when they changed after the request was submitted. A success toast and clean dirty flag must describe the request that actually reached the server, including slow-network conditions.

## Implementation plan

1. In `resources/js/pages/Settings.vue`, disable every editable control belonging to a processing preference form. Cover General, Appearance, Security, column colours, add/remove/reorder/default actions and Tools browse/check controls where they affect the submitted form.
2. Prevent repeated saves and destructive draft operations during that request. Keep unrelated sections independent rather than adding a global form lock.
3. On success, use the returned section values as that form's saved baseline, including server-normalized column names. Do not mark unrelated forms or newer drafts clean.
4. Keep validation/network failures on the existing draft and dirty baseline; do not show success when the response is absent or rejected. Coordinate confirmed-restore baseline updates with S02.
5. Extend `tests/settings-preferences.test.mjs` with a delayed response, an attempted mid-request change, normalized saved values and a rejected save.

## Acceptance criteria

- [ ] A processing section cannot be edited or submitted twice.
- [ ] Confirmed values, displayed values and the clean baseline agree after success.
- [ ] Failed saves preserve the draft and unsaved indicator.
- [ ] Saving one section never cleans unrelated drafts.

## Verification

Throttle a save and try each select, colour, reorder and restore-default action before it finishes. Confirm the UI prevents changes and announces only the persisted state. Repeat with a network failure and with whitespace-normalized column names.

Run `node --test tests/settings-preferences.test.mjs`. Retain the existing checks for saving one section while preserving others.

## Related plans

- [S05 — Settings sections handle drafts differently](s05-settings-sections-handle-drafts-differently.md)
- [S02 — Restored defaults can display stale values](s02-restored-defaults-can-display-stale-values.md)
