# PW25 — Duplicate is hidden and its scope is undisclosed

- **Priority:** P3
- **Area:** workspace
- **Status:** Implemented
- **Evidence:** Source — WorkspaceSidebar.vue:112; DuplicateProject.php:20–93
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-95)

## Problem

Duplicate appears only in a sidebar context menu. It copies documents, cards, files and secrets but keeps links to the same local source folders.

The original audit cited a sidebar-only menu. Current source has `ProjectContextMenu.vue` shared by sidebar and cards, so preserve that user work: the remaining gap is visible discovery and an accurate duplication summary.

## Implementation plan

1. Recheck `resources/js/components/ProjectContextMenu.vue`, `ProjectCard.vue` and `WorkspaceSidebar.vue`; confirm duplication still delegates to the existing project duplicate route.
2. Add an explicit, keyboard-accessible project actions trigger using the existing Reka dropdown pattern from `ProjectAssets.vue` or `ProjectSecrets.vue`. Keep action handling inside `ProjectContextMenu.vue` rather than duplicating route logic.
3. Expose that visible trigger from the card/header placement that fits the current shared menu structure; retain right-click access and existing archive/delete protections.
4. Before Duplicate, show a short shared Dialog summary based on `app/Actions/DuplicateProject.php`: copied documents, board cards, assets and secrets; repository/local source links are retained, not cloned.
5. Move the current inline duplicate POST into one component-local handler used by both menus and confirmation. Respect `disabled`, prevent repeat requests and retain the existing route/redirect and file-copy failure handling.

## Acceptance criteria

- [x] Duplicate is available through a visible keyboard-accessible action.
- [x] The summary accurately distinguishes copied Orbit content from shared source paths.
- [x] The existing right-click menu and other project actions remain available.
- [x] One activation creates one duplicate; cancellation creates none.

## Verification

Run `php artisan test --compact tests/Feature/ProjectDuplicationTest.php` if duplication wiring changes. Use the existing Vue script harness in `tests/project-sidebar.test.mjs` for shared-menu confirmation and one-request behavior; run `node --test tests/project-sidebar.test.mjs`. Manually duplicate a populated disposable project, compare copied content/source paths, then cancel.

## Related plans

- [PW26 — Archive may not achieve expected decluttering](pw26-archive-may-not-achieve-expected-decluttering.md)

## Completion — 28 September 2026

Project cards expose a visible keyboard-accessible action menu. Duplicate confirmation explains copied content/shared source paths and prevents repeated requests.

Checks: tests/project-sidebar.test.mjs; tests/project-context-menu.test.mjs; tests/Feature/ProjectDuplicationTest.php; dashboard menu/confirmation browser check. These checks passed in the full suites; production build and lint of changed Vue files also passed.
