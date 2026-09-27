# C19 — Remove means different outcomes for assets

Priority: P3  
Area: assets  
Status: Planned  
Evidence: Source — ProjectAssets.vue:439,450,496; ProjectAssetController.php:258–271  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-233)

## Problem

Assets' Remove action has two outcomes: selected files are deleted, while removed folders' surviving contents move to the nearest remaining parent. One generic label can sound like unlinking and does not immediately show which named items will be deleted or reorganized.

## Implementation plan

1. In `resources/js/components/ProjectAssets.vue`, tailor the existing selection confirmation to file-only, folder-only and mixed selections. Use Delete files for files and Remove folders, keep contents for folders; mixed selections must describe both outcomes.
2. Resolve and list the selected names from existing `selectedItems`, assets and folders in the dialog. Use a bounded scroll area for long selections, preserving readable wrapping and the item count.
3. Clarify that explicitly selected files are deleted even when a selected folder also contains them, while other contents survive and move to the nearest remaining parent.
4. Update visible menu/toolbar action wording where the selection type is known, retaining one existing confirmation and the current endpoints. Preserve `app/Http/Controllers/ProjectAssetController.php` deletion, reparenting and conflict behavior.

## Acceptance criteria

- [ ] Confirmation identifies every selected file/folder by name.
- [ ] File deletion and folder removal are clearly distinguished.
- [ ] Mixed-selection copy describes deleted files and surviving contents accurately.
- [ ] Cancel changes nothing; confirmation preserves existing server behavior.

## Verification

Use disposable files and nested folders. Check file-only, folder-only and mixed selections from toolbar, menu and Delete key. Confirm a folder-only operation keeps its contents, while explicitly selected files are deleted. Check name-conflict errors when reparenting subfolders and verify the dialog does not promise success prematurely. Inspect long selections and filenames at a narrow window width.

No new automated tests are needed for confirmation copy/layout. Existing deletion and reparenting semantics remain unchanged.

## Related plans

None.
