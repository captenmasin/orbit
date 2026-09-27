# PW10 — Archive also commits every form edit

- **Priority:** P2
- **Area:** workspace
- **Status:** Planned
- **Evidence:** Source — ProjectForm.vue:73,88–90,447; SaveProject.php:39
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-51)

## Problem

Archive changes status and submits the entire form. It can save unrelated edits or fail because an unfinished link/source is invalid.

Keep the current full-form save behavior and make its scope explicit instead of inventing a second save endpoint. Confirm the source-derived unrelated-edit consequence with a disposable project first.

## Implementation plan

1. In `resources/js/components/ProjectForm.vue`, reproduce archiving after editing a name and after adding an unfinished link. Trace the existing `archive()` call into `submit()`.
2. When the form is dirty, label the action 'Save changes and mark archived' or 'Save changes and restore', with adjacent help that all entered project changes are saved.
3. When the form is clean, use 'Mark archived' and 'Restore project', aligning archive wording with PW26 while retaining previous-status restoration.
4. Keep validation routed to the relevant tab and preserve the full draft on failure. Do not imply a status-only change succeeded when the project form was rejected.
5. Coordinate dirty-navigation protection with PW01 so the archive submit/redirect does not trigger a discard prompt; test the current payload and failed-save outcome in `tests/project-tab.test.mjs`.

## Acceptance criteria

- [ ] The dirty archive/restore action states that all pending edits are saved.
- [ ] Clean archive wording describes the status change without promising hidden projects.
- [ ] Validation failure preserves the draft and reveals the responsible section.
- [ ] Successful archive/restore bypasses the navigation-discard prompt.

## Verification

Run `node --test tests/project-tab.test.mjs`. Manually archive clean and dirty forms, fail validation with an unfinished link, correct it and retry; then restore. Run `php artisan test --compact tests/Feature/ProjectCatalogTest.php --filter=test_archiving_and_unlinking_preserve_source_folders_and_repository_files` if status handling changes.

## Related plans

- [PW01 — Project forms discard unsaved work](pw01-project-forms-discard-unsaved-work.md)
- [PW26 — Archive may not achieve expected decluttering](pw26-archive-may-not-achieve-expected-decluttering.md)

