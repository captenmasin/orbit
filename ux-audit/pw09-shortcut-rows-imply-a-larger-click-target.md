# PW09 — Shortcut rows imply a larger click target

- **Priority:** P2
- **Area:** workspace
- **Status:** Planned
- **Evidence:** Source — ShowProject.vue:349–364; contrast 223,329
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-45)

## Problem

Labels and URLs have hover treatment but only the small external-link icon opens them; nearby document/card rows are whole-row links.

Enlarge the existing open target without nesting interactive controls. The row must retain its details action, context menu, target-link highlight and native versus browser opening behavior.

## Implementation plan

1. Inspect `resources/js/pages/ShowProject.vue` shortcut rows and `resources/js/components/OpenTargetButton.vue` to trace desktop opening and browser anchors before changing the target.
2. Make the label and URL area one clear open action. Reuse OpenTargetButton's opening behavior with an optional text-content slot if necessary, keeping its current icon-only callers unchanged.
3. Keep the details/info button and context-menu trigger as siblings of the open action. Avoid wrapping an entire row containing buttons in an anchor.
4. Add a visible focus treatment to the enlarged action and keep long labels/URLs truncated visually with their accessible names intact.
5. Manually confirm right-click Copy URL/Open link and search-deep-link highlighting still reach the same saved link; add logic coverage only if the shared opener's behavior changes.

## Acceptance criteria

- [ ] Clicking a shortcut label or URL opens that saved destination.
- [ ] Details opens its dialog independently and does not also open the link.
- [ ] Keyboard users can identify and activate separate Open and Details controls.
- [ ] Desktop opening, browser new-tab behavior and existing compact callers remain correct.

## Verification

Manually inspect multiple links with and without notes, long labels, categorized entries and a highlighted search result in web and desktop. Test keyboard activation and right-click copying. Layout-only changes need no new tests; if shared opening logic changes, run `php artisan test --compact tests/Feature/ProjectCatalogTest.php --filter=test_native_open_actions_use_only_the_selected_saved_target`.

## Related plans

- [PW13 — The same objects change names across screens](pw13-the-same-objects-change-names-across-screens.md)
- [PW15 — Link order changes when expanded](pw15-link-order-changes-when-expanded.md)
- [PW24 — Disabled Open folder gives no explanation](pw24-disabled-open-folder-gives-no-explanation.md)

