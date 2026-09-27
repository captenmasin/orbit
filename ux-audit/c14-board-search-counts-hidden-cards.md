# C14 — Board search counts hidden cards

Priority: P2  
Area: content  
Status: Planned  
Evidence: Source — ProjectBoard.vue:301,320,380  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-152)

## Problem

Search hides nonmatching cards while list badges continue displaying the total card count. A list may show 12 beside only two visible cards or no visible cards. The badge needs to communicate both the filtered result and the original total.

## Implementation plan

1. In `resources/js/components/ProjectBoard.vue`, use the existing `matchingTaskIds` and trimmed-query state to render matched / total in each list badge while search is active.
2. Preserve the current total-only count when the trimmed query is empty. Count the same title, description and attachment-name matches used to show cards; do not introduce a second search algorithm.
3. Give the badge an accessible explanation such as N matching cards of M so the slash is understandable outside visual context. Keep the existing all-board no-results message.
4. Extend `tests/project-board-drag.test.mjs` around filtered counts, whitespace-only queries, zero-match lists and description/attachment matches.

## Acceptance criteria

- [ ] Active-search badges match the cards actually shown in each list.
- [ ] Each badge includes its total, including lists with zero matches.
- [ ] Clearing search restores the familiar total-only count.
- [ ] Counts use the same matching criteria as visible cards.

## Verification

Prepare cards whose matches occur separately in title, description and attachment filename. Search each term and compare every list badge to its visible cards. Test a term with no matches, an empty list and whitespace-only search. Clear the query and confirm all cards and original counts return; dragging remains disabled during filtering as before.

After implementation, run `node --test tests/project-board-drag.test.mjs`.

## Related plans

None.
