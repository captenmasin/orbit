# C17 — New card says Save changes

Priority: P3  
Area: content  
Status: Implemented
Evidence: Source — ProjectBoard.vue:49,471  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-164)

## Problem

A dialog titled New card ends with Save changes, the same action used for an existing card. Other entry points say Add card and Create selected cards. The label should describe the creation being performed so users can distinguish a new record from editing an existing one.

## Implementation plan

1. In `resources/js/components/ProjectBoard.vue`, change the task-editor submit label to Create card when there is no form ID, while retaining Save changes for an existing card.
2. Preserve the existing higher-priority loading and destructive branches: submitting continues to show Saving…, and Delete card/Delete list confirmations retain their current action labels.
3. Verify the New card and Card details titles align with the corresponding action, including newly opened cards reached through all available entry points.
4. Coordinate the broader Card/List vocabulary with C15. Keep this change confined to labels; there is no need to change endpoints, form construction, quick-add behavior or stored actions.

## Acceptance criteria

- [x] New-card editors say Create card.
- [x] Existing-card editors continue to say Save changes.
- [x] Loading and delete confirmation labels remain accurate.
- [x] Submission behavior and validation remain unchanged.

## Verification

Open a new-card editor where available, then an existing card and compare the primary buttons. Submit each with a valid title and inspect the loading label. Trigger an empty-title error and confirm the correct label remains after failure. Open card and list deletion confirmations to ensure their destructive wording did not inherit the creation label. Compare against Paste ideas' Create selected cards wording.

No new automated tests are needed for this copy-only change; verify the conditional labels manually.

## Related plans

- [C15 — Board vocabulary alternates mid-flow](c15-board-vocabulary-alternates-mid-flow.md)

## Completion — 28 September 2026

New-card submission reads Create card; existing-card submission reads Save changes.

Checks: tests/project-board-drag.test.mjs; tests/scratchpad-action-review.test.mjs; tests/Feature/ProjectBoardTest.php; tests/Feature/TaskContentTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.
