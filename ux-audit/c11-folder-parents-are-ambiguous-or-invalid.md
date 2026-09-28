# C11 — Folder parents are ambiguous or invalid

Priority: P2  
Area: assets  
Status: Implemented
Evidence: Source — ProjectAssets.vue:93–103,477,490; ProjectAssetController.php:330  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-214)

## Problem

Folder editing shows parent options by bare names and permits descendants. Equal names under different branches are ambiguous, and selecting a descendant fails only after Save. The Move flow already builds full folder paths and excludes invalid destinations; the editor should use those same facts.

## Implementation plan

1. In `resources/js/components/ProjectAssets.vue`, derive editor parent choices from the existing `folderTree` path entries instead of the flat `folders` names.
2. For an existing folder, exclude that folder and all its descendants based on `editingFolder.id`, not the unrelated current selection. For a new folder, include all valid existing parents plus Assets.
3. Keep the current parent selected, use the existing ChoiceSelect, and retain backend cycle/name validation in `app/Http/Controllers/ProjectAssetController.php` for concurrent or malformed submissions.
4. Extend `tests/project-assets.test.mjs` to cover duplicate leaf names, nested descendants, new-folder options and changing the editor independently of selection.

## Acceptance criteria

- [x] Parent options show paths that distinguish equal leaf names.
- [x] A folder and its descendants cannot be selected as its parent.
- [x] Valid ancestors, siblings and Assets remain available.
- [x] Backend checks still reject invalid or conflicting concurrent changes.

## Verification

Create Brand/Images and Marketing/Images, then rename/reparent another folder and verify the options are distinguishable. Edit Brand and confirm neither Brand nor its descendants are choices, while Marketing and Assets remain valid. Change selection before opening the editor to ensure exclusions follow the edited folder. Check successful reparenting and a server-side conflict.

After implementation, run `node --test tests/project-assets.test.mjs` and `php artisan test --compact tests/Feature/ProjectAssetSelectionTest.php`.

## Related plans

None.

## Completion — 28 September 2026

Asset parent options display full paths and exclude the edited folder and its descendants while keeping valid parents available.

Checks: tests/project-assets.test.mjs; tests/Feature/ProjectAssetSelectionTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.
