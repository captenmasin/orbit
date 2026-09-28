# PW22 — Workspace search scope is unclear

- **Priority:** P3
- **Area:** workspace
- **Status:** Implemented
- **Evidence:** Source — ContentSearch.vue:98; WorkspaceLayout.vue:112; SearchController.php:26–32
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-83)

## Problem

The visible placeholder sounds universal. Scratchpad text, asset names, repositories and folders are not searched; the scope help is screen-reader only.

Clarify the search that exists instead of broadening its data scope. In particular, do not imply secret values or file contents are searched, and do not add those fields for this copy fix.

## Implementation plan

1. Confirm the source types and fields in `app/Http/Controllers/SearchController.php` against `resources/js/components/WorkspaceLayout.vue`'s current screen-reader dialog description.
2. In `resources/js/components/ContentSearch.vue`, show visible supporting text beneath the launcher input: 'Search projects, documents, cards, links and secret metadata.' Use C15's visible Card/List vocabulary while retaining internal task identifiers and supported query tokens.
3. Add concise scope clarification where space permits: 'Scratchpad notes, assets and source folders are not included.' Keep the primary placeholder short.
4. Align the accessible dialog description in `WorkspaceLayout.vue` with actual metadata matching while explicitly avoiding any claim that stored secret values are searched.
5. Preserve the current backend sources, response cap and project-scoped search behavior; coordinate result-excerpt spacing with PW21.

## Acceptance criteria

- [x] The supported search types are visible before a query is entered.
- [x] Secret metadata is clearly distinguished from secret values.
- [x] The omitted scratchpad/assets/source scope is not implied as searchable.
- [x] Screen-reader and visible descriptions communicate the same coverage.

## Verification

No new tests are required for this copy-only change. Manually compare searches for document body text, task descriptions, link URLs and secret service/environment against scratchpad-only text and asset filenames. Confirm help remains legible when results, errors and loading states appear. Inspect narrow launcher width and keyboard reading/focus order.

## Related plans

- [PW21 — Global search omits visible match context](pw21-global-search-omits-visible-match-context.md)
- [PW06 — Project search can show stale results](pw06-project-search-can-show-stale-results.md)
- [C15 — Board vocabulary alternates mid-flow](c15-board-vocabulary-alternates-mid-flow.md)

## Completion — 28 September 2026

Visible launcher guidance and the accessible description identify supported types, secret metadata and excluded notes/assets/sources.

Checks: tests/content-search.test.mjs; tests/Feature/ContentSearchTest.php; launcher template review. These checks passed in the full suites; production build and lint of changed Vue files also passed.
