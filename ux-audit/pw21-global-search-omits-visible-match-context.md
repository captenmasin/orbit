# PW21 — Global search omits visible match context

- **Priority:** P3
- **Area:** workspace
- **Status:** Planned
- **Evidence:** Source — SearchController.php:56–70; ContentSearch.vue:107–114,146
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-76)

## Problem

At audit time, the backend supplied body-match excerpts but global results hid them in a title attribute, while project results displayed them.

Current working source already renders `result.excerpt` visibly in the global launcher, with plain-text highlighting. Verify this existing change before adding anything. Keep the original finding as an acceptance target; if it passes in the current app, record it as resolved without duplicating the excerpt row.

## Implementation plan

1. Reproduce a body-only match in the current global launcher using `resources/js/components/ContentSearch.vue`; confirm its existing excerpt row is visible in the running app and built frontend.
2. Preserve the existing Result shape, plain Vue text interpolation and text-only highlight segments. Do not replace them with `v-html` or rendered Markdown.
3. If any acceptance check fails, adjust only that excerpt row's layout/line clamp so icon, title, project name and type remain readable; omit the excerpt cleanly for title-only matches.
4. Keep the whole result selectable through pointer and arrow/Enter navigation, preserving current loading, error, limit and highlighted-item behavior.
5. Review the visible coverage hint from PW22 in the same launcher so context and search scope help complement rather than crowd the result list.

## Acceptance criteria

- [ ] Body/description matches show a readable excerpt in global results.
- [ ] Title-only matches do not leave blank excerpt space.
- [ ] User-supplied markup is displayed as plain text.
- [ ] Keyboard selection, project identity and type labels remain clear.

## Verification

No new automated tests are required for this display-only change. Manually search text found only inside a document body, task description and secret metadata description; compare title-only matches and long excerpts. Check keyboard navigation, narrow layouts and both themes. If excerpt generation itself changes, run `php artisan test --compact tests/Feature/ContentSearchTest.php`.

## Related plans

- [PW06 — Project search can show stale results](pw06-project-search-can-show-stale-results.md)
- [PW22 — Workspace search scope is unclear](pw22-workspace-search-scope-is-unclear.md)
- [PW04 — Full project descriptions are hidden](pw04-full-project-descriptions-are-hidden.md)
