# PW15 — Link order changes when expanded

- **Priority:** P3
- **Area:** workspace
- **Status:** Planned
- **Evidence:** Source — ProjectForm.vue:142,396; ShowProject.vue:91–98,345
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-70)

## Problem

Edit saves manual up/down order. Overview initially uses that order, but expanding alphabetizes categories, moving links again.

Keep saved manual link order authoritative. This source-derived mismatch needs a reproduction with interleaved categories; do not silently reinterpret the editor's up/down actions as category ordering.

## Implementation plan

1. Create at least five links with categories interleaved in saved position order. Reproduce the change between collapsed and expanded Overview in `resources/js/pages/ShowProject.vue`.
2. Use `project.links` as the single ordering source for both states; expansion should only change the slice limit, not sort categories.
3. Render one ordered link list rather than regrouping discontiguous categories. Show a link's category as row metadata when expanded, retaining the existing details dialog for category/notes.
4. Remove the now-unused alphabetical sort/group calculations, preserving target-link highlighting, scroll-to-link and the explicit View all/Show fewer controls.
5. Update the expanded-link expectations in `tests/project-tab.test.mjs` so interleaved categories and a moved link retain identical relative order before and after expansion.

## Acceptance criteria

- [ ] Expand/collapse never changes relative link order.
- [ ] The editor's up/down result matches the full overview list.
- [ ] Categories remain accessible without overriding manual order.
- [ ] Deep-linked links still expand, highlight and scroll to the correct item.

## Verification

Run `node --test tests/project-tab.test.mjs`. Manually move a link across differently named and uncategorized links in Edit, save, and expand/collapse Overview. Check more than three links, a repeated category and a search-targeted link. Confirm opening, details and context-menu Copy URL remain functional.

## Related plans

- [PW09 — Shortcut rows imply a larger click target](pw09-shortcut-rows-imply-a-larger-click-target.md)
- [PW13 — The same objects change names across screens](pw13-the-same-objects-change-names-across-screens.md)

