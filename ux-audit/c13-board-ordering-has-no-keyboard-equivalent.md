# C13 — Board ordering has no keyboard equivalent

Priority: P2  
Area: content  
Status: Implemented
Evidence: Source — ProjectBoard.vue:294–313,336–345  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-145)

## Problem

Visual ordering requires dragging. Existing card context menus can move between lists but cannot change order within a list, and list menus have no ordering action. Keyboard users cannot complete the same organization task despite being able to open cards and menus.

## Implementation plan

1. In `resources/js/components/ProjectBoard.vue`, add Move list left/right to the existing list menu, disabled at the first/last position and while busy.
2. Add Move card up/down to the existing card context menu, disabled at list boundaries. Keep Move to list for cross-list movement.
3. Route both actions through the existing form/submit flow and `column.move` or `task.move` operations supported by `app/Actions/SaveBoard.php`; use the current revision and requested position rather than adding endpoints.
4. Keep focus on the moved item's control after successful updates and announce the result or failed/conflicting move using existing accessible status/error patterns.
5. Extend `tests/project-board-drag.test.mjs` for boundaries, requested positions, busy behavior and conflicts; retain existing endpoint ordering coverage.

## Acceptance criteria

- [x] Every list and card can be reordered without dragging.
- [x] Boundary and in-flight ordering actions are disabled appropriately.
- [x] Keyboard moves persist the same order as drag moves.
- [x] Focus and error feedback remain usable after success or failure.

## Verification

Using only the keyboard, open list menus and card context menus with ContextMenu or Shift+F10, then move items to each boundary. Verify persisted order after reload. Trigger a revision conflict and confirm no false success or focus loss. Check drag reordering and cross-list movement still work.

After implementation, run `node --test tests/project-board-drag.test.mjs` and `php artisan test --compact tests/Feature/ProjectBoardTest.php`.

## Related plans

- [C15 — Board vocabulary alternates mid-flow](c15-board-vocabulary-alternates-mid-flow.md)

## Completion — 28 September 2026

List and card menus expose keyboard ordering through the existing move endpoints, with boundary guards, focus restoration and announcements.

Checks: tests/project-board-drag.test.mjs; tests/scratchpad-action-review.test.mjs; tests/Feature/ProjectBoardTest.php; tests/Feature/TaskContentTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.
