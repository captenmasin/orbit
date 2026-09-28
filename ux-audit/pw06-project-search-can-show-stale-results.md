# PW06 — Project search can show stale results

- **Priority:** P2
- **Area:** workspace
- **Status:** Implemented
- **Evidence:** Source — ContentSearch.vue:77–87,126–147
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-32)

## Problem

Global search updates as users type. Compact project search waits for Enter, but keeps previous results beneath a changed query.

Retain compact project search's intentional manual submission. The source-derived stale-result risk should be reproduced by completing one search, changing its query, and interacting before pressing Enter again.

## Implementation plan

1. In `resources/js/components/ContentSearch.vue`, separate query invalidation from the launcher-only live-search debounce. Any query change should abort the pending request and clear previous results, counts, errors and searched state.
2. Keep automatic 200ms submission limited to the workspace launcher. Compact project search should remain idle until Enter or its existing arrow action is used.
3. Add a short visible 'Press Enter to search' hint in the compact result area when the input contains a pending query; keep focus and Escape/close behavior unchanged.
4. Ensure an older request cannot repopulate results after the query changed. Reuse the existing AbortController identity checks instead of adding a second request controller.
5. Extend the compact-search test in `tests/content-search.test.mjs` for changed query, cleared input, an older deferred response and subsequent Enter submission.

## Acceptance criteria

- [x] Typing after a completed project search clears its previous results immediately.
- [x] A changed query does not trigger automatic compact searching.
- [x] Late responses from the prior query cannot appear under new text.
- [x] Enter/arrow, Escape and project-scoped navigation continue to work.

## Verification

Run `node --test tests/content-search.test.mjs`. Manually search once, edit the query without submitting, clear it, and repeat on a slow connection; verify no old result remains actionable. Compare workspace search to confirm its existing live behavior and keyboard result navigation remain intact.

## Related plans

- [PW21 — Global search omits visible match context](pw21-global-search-omits-visible-match-context.md)
- [PW22 — Workspace search scope is unclear](pw22-workspace-search-scope-is-unclear.md)


## Completion — 28 September 2026

Changing compact-search queries aborts and invalidates old results immediately; Enter starts the new manual search with visible guidance.

Checks: tests/content-search.test.mjs; tests/Feature/ContentSearchTest.php; launcher template review. These checks passed in the full suites; production build and lint of changed Vue files also passed.
