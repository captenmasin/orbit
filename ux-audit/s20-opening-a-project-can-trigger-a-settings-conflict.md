# S20 — Opening a project can trigger a settings conflict

Priority: P3

Area: settings

Status: Implemented

Evidence: Source — WorkspaceController.php:81; WorkspacePreferences.php:79; Settings.vue:93

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-434)

## Problem

Last-project history writes the editable settings revision. A project visit in another window can cause Settings changed in another window even without a settings edit. Startup history is incidental metadata; it should not invalidate an unchanged settings draft or overwrite unrelated preference values.

## Implementation plan

1. Add a nullable last-project identifier column to the existing `workspace_preferences` record through a new Artisan-generated migration in `database/migrations/`. Use `database/migrations/2026_09_27_101057_create_workspace_preferences_table.php` as the schema reference; backfill existing `startup.last_project_id` without changing saved settings.
2. In `app/WorkspacePreferences.php`, add narrowly scoped read/write handling for this metadata column. Update only the identifier, without changing editable revision or replacing the preferences JSON; preserve access to backfilled startup history.
3. Update `app/Http/Controllers/WorkspaceController.php` and `app/Http/Controllers/SettingsController.php` to use that handling. Preserve Dashboard choice, archived/deleted-project fallbacks and overview resumption.
4. Keep startup history absent from safe settings responses and portable backups. Retain existing revision conflicts for actual settings edits and security-generation changes.
5. Extend `tests/Feature/WorkspacePreferencesTest.php` for history backfill, project visits that leave revision unchanged, successful pending settings saves and genuine edit conflicts.

## Acceptance criteria

- [x] Opening projects does not advance editable-settings revision.
- [x] Previously recorded startup history survives upgrading.
- [x] Settings writes cannot overwrite newer incidental history.
- [x] Real settings changes still reject stale saves.
- [x] All current startup fallbacks remain intact.

## Verification

Open Settings in one window, visit another project elsewhere and save the original draft. Confirm success and the correct last-project destination. Repeat after an actual settings edit; confirm the stale save fails. Check upgraded and fresh databases plus archived/deleted projects.

Run `php artisan test --compact tests/Feature/WorkspacePreferencesTest.php` and `vendor/bin/pint --dirty --format agent` during implementation.

## Related plans

None.

## Completion — 28 September 2026

Incidental startup history uses a separate last_project_id column without advancing editable preference revisions. Migration preserves old history; stale real settings writes still conflict.

Checks: tests/Feature/WorkspacePreferencesTest.php; tests/Feature/WorkspaceTest.php; local migration applied. These checks passed in the full suites; production build and lint of changed Vue files also passed.
