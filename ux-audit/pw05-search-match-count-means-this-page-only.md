# PW05 — Search match count means this page only

- **Priority:** P2
- **Area:** workspace
- **Status:** Implemented
- **Evidence:** Source — Dashboard.vue:56,69; WorkspaceController.php:63
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-26)

## Problem

The dashboard says “25 matches” when more matches exist on later pages. The next page can display a different count for the same query.

The catalog deliberately uses simple pagination rather than a total-count query. Clarify the current page count using the existing pagination data instead of changing backend query behavior.

## Implementation plan

1. Reproduce filtering in `resources/js/pages/Dashboard.vue` with more than 25 matching projects. Check the page data supplied by `app/Http/Controllers/WorkspaceController.php::index()`.
2. Replace the filtered 'N matches' text with 'N projects shown'. Append 'more available' when `projects.next_page_url` exists; avoid implying this is the full result count on any page.
3. Keep the overall Projects count distinct from filtered results. Preserve the existing filter state, previous/next links and no-matching-projects message.
4. Check singular, zero and exactly-one-page wording. Retain the existing role/search labeling and ensure the result text remains readable when filters wrap.
5. Keep `simplePaginate(25)` unchanged. If the implementation introduces count-formatting logic, extend the existing dashboard test only for observable 0/1/multiple/next-page wording.

## Acceptance criteria

- [x] Displayed counts describe the current page rather than all matches.
- [x] A next page is announced without claiming an unknown total.
- [x] Search, status and tag filters persist through pagination.
- [x] Zero and single-project text are grammatically correct.

## Verification

Manually check 0, 1, 25, 26 and 51 matches and move through every page. Copy-only changes need no new tests. If count formatting is moved into logic, run `node --test tests/dashboard-filters.test.mjs`; backend pagination regression is `php artisan test --compact tests/Feature/ProjectCatalogTest.php --filter=test_search_filters_and_pagination_preserve_literal_search_and_sort_by_known_commit`.

## Related plans

None.


## Completion — 28 September 2026

Filtered dashboard counts say how many projects are shown and indicate when another page is available.

Checks: tests/dashboard-filters.test.mjs; tests/Feature/ProjectCatalogTest.php; count wording review. These checks passed in the full suites; production build and lint of changed Vue files also passed.
