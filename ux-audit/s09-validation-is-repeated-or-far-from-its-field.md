# S09 — Validation is repeated or far from its field

Priority: P2

Area: settings

Status: Implemented

Evidence: Source — Settings.vue:153–176; ProbeRuntimes.php:85; WorkspacePreferences.php:24

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-364)

## Problem

General/Appearance/Security repeat inline errors in a global loop. Column/runtime errors appear below the whole card and may not identify the failing field. A user should see the affected control and one actionable message, including nested validation paths.

## Implementation plan

1. In `resources/js/pages/Settings.vue`, keep General, Appearance and Security field errors beside their controls and remove their duplicates from the trailing all-errors loop.
2. Add field-level errors for each default column's name and colour using its submitted index. Keep array-level column errors beside the Add/Save controls.
3. Place `paths.[tool]` errors from both runtime probes and preference saves beside the corresponding executable input. Keep a contextual summary only for non-field failures such as revisions.
4. Reuse existing Field, FieldError and invalid-state conventions. Associate messages with controls so keyboard/screen-reader users can identify the failing field.
5. Keep backend validation in `app/WorkspacePreferences.php` and `app/Actions/ProbeRuntimes.php` intact; extend `tests/settings-preferences.test.mjs` for nested failures and single rendering.

## Acceptance criteria

- [x] Each field error appears once beside the responsible control.
- [x] Invalid column/runtime inputs are identifiable without interpreting raw indexes.
- [x] Non-field conflict/network feedback remains visible.
- [x] Fixing and resubmitting a field removes obsolete error feedback.

## Verification

Submit a blank default-column name, invalid colour and missing PHP executable; trigger a stale revision and inspect its separate summary. Check focus, invalid styling and error associations at narrow and desktop widths. Repeat a corrected submission.

Run `node --test tests/settings-preferences.test.mjs` and `php artisan test --compact tests/Feature/WorkspacePreferencesTest.php --filter=test_invalid_section_values_leave_saved_preferences_intact`.

## Related plans

- [S07 — Mid-save edits can be marked saved falsely](s07-mid-save-edits-can-be-marked-saved-falsely.md)
- [S15 — Runtime results expose jargon without next steps](s15-runtime-results-expose-jargon-without-next-steps.md)


## Completion — 28 September 2026

Field validation is shown beside its specific column/runtime/control; conflict and network feedback remain separate.

Checks: tests/settings-preferences.test.mjs; tests/settings.test.mjs; tests/workspace-backups.test.mjs; tests/Feature/WorkspacePreferencesTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.
