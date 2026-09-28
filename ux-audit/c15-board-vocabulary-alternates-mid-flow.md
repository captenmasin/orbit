# C15 — Board vocabulary alternates mid-flow

Priority: P3  
Area: content  
Status: Implemented
Evidence: Live + source — ProjectBoard.vue:92,310,461; BulkIdeas.vue:50; ScratchpadActionsReview.vue:95  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-158)

## Problem

Board objects use cards/lists in primary controls but tasks/columns in deletion, attachments, bulk paste, search and suggestion review. Adopt Card and List consistently so users can recognize the same objects.

## Implementation plan

1. Review user-facing text in `resources/js/components/ProjectBoard.vue`, `resources/js/components/BulkIdeas.vue` and `resources/js/components/ScratchpadActionsReview.vue`; replace task/column labels with card/list where they refer to Board objects.
2. Include attachment validation, destinations, dropdown prompts, suggestion summaries and accessibility labels. Preserve IDs, internal action names, types and payload keys.
3. Review Board-facing validation text in `app/Actions/SaveBoard.php` and scratchpad action errors in `app/Http/Controllers/WorkspaceController.php` so server errors use the same vocabulary.
4. Check the Overview Board summary in `resources/js/pages/ShowProject.vue` for the same labels. Coordinate new-card action wording with C17 and keyboard menu wording with C13.
5. In `resources/js/components/ContentSearch.vue`, display `task` results as Card in launcher/recent rows and inline badges, and change the search placeholder to cards. Explain the existing `type:task` token as the card filter in help/active-filter labels. Preserve parser/API values, query syntax and result keys.

## Acceptance criteria

- [x] A Board object is consistently called a card or a list across visible flows.
- [x] Screen-reader labels and validation messages use the same vocabulary.
- [x] Internal identifiers, requests and stored data remain unchanged.
- [x] Suggestions still clearly distinguish cards from documents, links and project details.
- [x] Search labels say Card; `type:task` remains the working, clearly explained card filter.

## Verification

Walk through quick add, card details, attachment errors, list deletion, Paste ideas and suggestion review. Inspect server validation copy, Overview and keyboard menus. Search for a card globally/within a project; verify recent/badge labels and `type:task` filtering. Persistence and validation semantics must stay unchanged.

No new tests are needed for wording. If PHP changes, run `vendor/bin/pint --dirty --format agent`.

## Related plans

- [C13 — Board ordering has no keyboard equivalent](c13-board-ordering-has-no-keyboard-equivalent.md)
- [C17 — New card says Save changes](c17-new-card-says-save-changes.md)
- [PW22 — Workspace search scope is unclear](pw22-workspace-search-scope-is-unclear.md)

## Completion — 28 September 2026

Visible board, scratchpad, search and validation language consistently uses Card/List; internal action identifiers remain compatible.

Checks: tests/project-board-drag.test.mjs; tests/scratchpad-action-review.test.mjs; tests/Feature/ProjectBoardTest.php; tests/Feature/TaskContentTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.
