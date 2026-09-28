# PW03 — Editing a remote URL disconnects its provider

- **Priority:** P2
- **Area:** sources
- **Status:** Implemented
- **Evidence:** Source — ProjectForm.vue:300; Repository.php:28–50; ProviderActivityTest.php:164
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-245)

## Problem

A URL change clears provider metadata and cached activity without warning in the editor, including spelling/format changes.

The disconnect is an existing model behavior, not a proposed data change. Reproduce it on a disposable connected repository, preserving current connection safeguards.

## Implementation plan

1. Inspect `app/Models/Repository.php::disconnectProvider()` and the existing regression in `tests/Feature/ProviderActivityTest.php`; confirm which values are cleared after a remote URL edit.
2. In the saved-repository section of `resources/js/components/ProjectForm.vue`, identify rows with a provider connection and show its connected state before the editable Remote URL.
3. Add adjacent wording: 'Changing this URL disconnects its provider and clears cached activity. You can reconnect it from Sources.' Show it when editing a connected row; do not hide the consequence behind a tooltip.
4. Coordinate the Sources reconnect entry with PW08. Use the saved repository ID and existing connection flow rather than writing credentials or provider association through the project metadata form.
5. Keep the server's disconnection behavior and revision checks intact. Verify URL format changes, failed validation and Cancel against the actual current model flow.

## Acceptance criteria

- [x] Connected repositories are distinguishable in the editor.
- [x] The disconnect/cache consequence is explained before saving a URL change.
- [x] The reconnect destination reaches the existing repository connection flow.
- [x] Cancelling or rejected saves do not disconnect a provider.

## Verification

Run `php artisan test --compact tests/Feature/ProviderActivityTest.php --filter=test_association_is_project_scoped_and_remote_edits_invalidate_snapshots` if the connection wiring changes. Manually compare an unchanged URL, a format-only change and a different remote, then reconnect from Sources. Copy-only warning changes need no new tests.

## Related plans

- [PW08 — Connect provider is buried](pw08-connect-provider-is-buried.md)
- [PW12 — Sources Refresh refreshes only local folders](pw12-sources-refresh-refreshes-only-local-folders.md)


## Completion — 28 September 2026

Connected repository rows identify their provider and explain URL-edit disconnection/cache consequences before saving, with a Sources reconnect destination.

Checks: tests/project-tab.test.mjs; tests/Feature/ProjectDetailsTest.php; tests/Feature/RepositoryCloneTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.
