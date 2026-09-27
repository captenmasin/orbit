# PW07 — Overview assumes fixed workflow names

- **Priority:** P2
- **Area:** workspace
- **Status:** Planned
- **Evidence:** Source — ShowProject.vue:57–65,207–229
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-38)

## Problem

To do and Backlog counters match specific names. Open tasks exclude only “done”, so “Completed” or “Done ” remains open after customization.

Avoid adding completion flags or migrating boards for this overview-only fix. Neutral summaries fit every customizable workflow and do not infer business meaning from user-controlled list names.

## Implementation plan

1. Reproduce the source-derived issue with default lists, renamed 'Completed', whitespace around 'Done', and entirely custom lists. Inspect `resources/js/pages/ShowProject.vue` and its overview tests.
2. Replace the To do and Backlog summary pills with one 'Cards' pill using the existing total `taskCount`; keep its existing navigation to the board and align visible vocabulary with C15.
3. Make the three-card preview workflow-independent: include cards without name-based exclusion and describe it as a board preview, not open or unfinished work. Retain the current list labels and saved traversal order.
4. Remove the now-unused name-based counters and completion-inference comment. Use 'No cards yet' for an empty board rather than 'No open tasks'; keep internal task IDs and types intact.
5. Update the name-dependent overview expectations in `tests/project-tab.test.mjs`, covering default, renamed and custom lists plus an empty board.

## Acceptance criteria

- [ ] The overview total includes every saved card regardless of list name.
- [ ] Renamed or whitespace-padded completed lists cannot produce a false open-work claim.
- [ ] The preview and empty state use neutral Card/List wording consistent with C15.
- [ ] The summary pill and preview cards still navigate to their correct board destinations.

## Verification

Run `node --test tests/project-tab.test.mjs`. Manually create cards in default and custom lists, rename those lists and revisit Overview. Confirm total counts and up-to-three preview behavior stay stable, and follow both the Cards pill and a targeted preview card.

## Related plans

- [C15 — Board vocabulary alternates mid-flow](c15-board-vocabulary-alternates-mid-flow.md)
- [PW13 — The same objects change names across screens](pw13-the-same-objects-change-names-across-screens.md)
