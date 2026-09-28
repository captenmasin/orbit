# PW11 — Browser Relink uses a detached input

- **Priority:** P2
- **Area:** sources
- **Status:** Implemented
- **Evidence:** Source — ProjectForm.vue:100–104,134,319–324,367
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-257)

## Problem

A folder row’s Relink button uses the shared Folder path input above the list. The input is cleared after inspection and is not prefilled for that row.

Reproduce the browser path on a disposable linked folder before implementation. Relinking is a row-level replacement operation; it should not borrow the unrelated Add folder input.

## Implementation plan

1. In `resources/js/components/ProjectForm.vue`, keep the existing native folder-picker route but open a shared-styled relink Dialog for browser mode, storing only the selected folder ID and replacement path.
2. Prefill that path from the selected saved folder and label it 'Replacement folder path'. Explain that this changes the project's link and does not move or delete files.
3. Submit the replacement through the existing `/folders/inspect` request and `useFolder(result.folder, selectedId)` flow. Keep the Add folder input separate from this dialog's draft.
4. Show inspection errors beside the replacement input, retain the dialog/path on failure, and close only after a valid selection is applied. Cancellation must leave the original folder unchanged.
5. Extend the relink/cancellation case in `tests/project-tab.test.mjs`; keep existing server path readability, duplicate-folder and package-root invalidation checks.

## Acceptance criteria

- [x] Each browser Relink action opens the current row's path for replacement.
- [x] Relinking does not read or clear the independent Add folder draft.
- [x] Failed inspection retains the replacement draft and original linked folder.
- [x] Success replaces only the selected folder; cancellation changes nothing.

## Verification

Run `node --test tests/project-tab.test.mjs` and `php artisan test --compact tests/Feature/LocalInspectionTest.php --filter=test_relinking_preserves_relative_roots_and_invalidates_old_results_and_jobs`. Manually relink the second of two folders in browser mode, try an unreadable/duplicate path and cancel; confirm the native picker remains unchanged.

## Related plans

- [PW01 — Project forms discard unsaved work](pw01-project-forms-discard-unsaved-work.md)
- [PW16 — Package location controls have several names](pw16-package-location-controls-have-several-names.md)
- [PW24 — Disabled Open folder gives no explanation](pw24-disabled-open-folder-gives-no-explanation.md)


## Completion — 28 September 2026

Browser Relink opens a dedicated prefilled replacement-path draft for the chosen row; the independent Add folder input is preserved.

Checks: tests/project-tab.test.mjs; tests/Feature/ProjectDetailsTest.php; tests/Feature/RepositoryCloneTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
