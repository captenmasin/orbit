# PW26 — Archive may not achieve expected decluttering

- **Priority:** P3
- **Area:** workspace
- **Status:** Planned
- **Evidence:** Source — WorkspaceController.php:44–60; HandleInertiaRequests.php:18
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-102)

## Problem

Archived projects stay in default dashboard results and sidebar groups. A user trying to clear active work can see no browsing change.

Keep the current intentional browsing model: Archived is a status and remains discoverable. This plan changes the promise made by actions, not default filters, ordering or data visibility.

## Implementation plan

1. Confirm `app/Http/Controllers/WorkspaceController.php::index()` and `app/Http/Middleware/HandleInertiaRequests.php` include archived projects; reproduce marking a disposable project archived.
2. Rename clean archive actions to 'Mark archived' in `resources/js/components/ProjectForm.vue` and the current shared `resources/js/components/ProjectContextMenu.vue`.
3. Add concise help where the action is reviewed: 'Archived projects remain visible in Dashboard and the Archived sidebar group. Use the Status filter to narrow the list.'
4. Coordinate dirty action wording with PW10: 'Save changes and mark archived' must still explain that it submits all draft changes. Keep 'Restore project' and previous-status restoration intact.
5. Review the new visible project actions entry from PW25 so context and dropdown menus use the same wording. Do not change archived filtering or startup-project eligibility.

## Acceptance criteria

- [ ] Archive action wording describes a status change, not removal from browsing.
- [ ] Users are told where archived projects remain visible and how to filter them.
- [ ] Dirty form archive wording agrees with PW10.
- [ ] Restore behavior, archived grouping and current dashboard data remain unchanged.

## Verification

No new tests are needed for action/help copy. Manually mark a project archived from Edit and a project menu, find it in Dashboard/Archived, apply the Status filter and restore it. Confirm startup behavior is unchanged. If status behavior is altered unintentionally, stop and use `php artisan test --compact tests/Feature/ProjectCatalogTest.php --filter=test_archiving_and_unlinking_preserve_source_folders_and_repository_files`.

## Related plans

- [PW10 — Archive also commits every form edit](pw10-archive-also-commits-every-form-edit.md)
- [PW25 — Duplicate is hidden and its scope is undisclosed](pw25-duplicate-is-hidden-and-its-scope-is-undisclosed.md)

