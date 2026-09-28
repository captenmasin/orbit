# C02 — Board conflict recovery closes the draft

Priority: P1  
Area: content  
Status: Implemented
Evidence: Source — ProjectBoard.vue:61–68,256–262,391; ProjectDocuments.vue:53  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-120)

## Problem

Board conflict recovery closes the editor. Its form still exists briefly, but reopening calls reset and replaces the unsaved title, description and attachment choices with server data. The recovery action therefore makes an ordinary revision conflict become lost work, unlike Documents' keep-draft behavior.

## Implementation plan

1. Update `reload()` in `resources/js/components/ProjectBoard.vue` to keep the current editor and form values when refreshing `selectedProject`, using `resources/js/components/ProjectDocuments.vue` as the local recovery precedent.
2. After a successful refresh, update the form's project revision and clear only resolved conflict errors. Keep new File objects and removed attachment IDs in the current component; do not reset the form.
3. Reconcile the refreshed target: if the card/list still exists, allow retry; if it was deleted or its list disappeared, explain that state and retain the draft without recreating or overwriting an item automatically.
4. Keep a failed reload visibly actionable and leave quick-add recovery intact. Extend `tests/project-board-drag.test.mjs` for card, list and attachment drafts through reload.

## Acceptance criteria

- [x] Reload board retains the active editor and every unsaved field.
- [x] Pending attachment additions/removals survive successful conflict recovery.
- [x] Retrying uses the refreshed revision and saves once.
- [x] Removed targets and failed reloads retain the draft with a clear next step.

## Verification

Open a card, change its title/body and add/remove attachments; modify the same project in another window, then save and reload after the conflict. Verify the entire draft remains and a retry succeeds. Repeat for list editing and quick add. Remove the edited target in the other window to check that recovery does not silently restore it.

After implementation, run `node --test tests/project-board-drag.test.mjs` and `php artisan test --compact tests/Feature/ProjectBoardTest.php`.

## Related plans

- [C01 — Changing tabs destroys a document draft](c01-changing-tabs-destroys-a-document-draft.md)

## Completion — 28 September 2026

Board reload retains fields and pending attachment changes, refreshes the revision and exposes removed targets without closing the draft.

Checks: tests/project-board-drag.test.mjs; tests/scratchpad-action-review.test.mjs; tests/Feature/ProjectBoardTest.php; tests/Feature/TaskContentTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.
