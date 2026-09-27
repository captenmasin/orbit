# C04 — Secret bulk actions include hidden selections

Priority: P1  
Area: assets  
Status: Planned  
Evidence: Source — ProjectSecrets.vue:111–130,299–315,653; ProjectAssets.vue:107  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-189)

## Problem

Secret selections survive changes to environment, service and search. Select all operates on filtered rows, but bulk actions use every selected ID. A user can filter to production and accidentally delete or change previously selected staging secrets that are no longer visible. Assets already clears selection on filters.

## Implementation plan

1. In `resources/js/components/ProjectSecrets.vue`, clear `selectedIds` when `filterSecrets()` accepts an actual environment, service or query change, matching `resources/js/components/ProjectAssets.vue`.
2. Keep the existing description flush before changing filters. If flush fails, leave both the filter and selection unchanged; assigning the current filter value should not unexpectedly clear selection.
3. Ensure select-all state and the bulk toolbar immediately reflect the cleared selection. Keep bulk actions scoped to IDs selected after the accepted filter change.
4. Extend `tests/project-secrets.test.mjs` for each filter, same-value updates, blocked description flush and Select all after filtering. Use existing bulk request behavior; no backend selection mechanism is needed.

## Acceptance criteria

- [ ] Accepted environment, service and search changes clear previous selections.
- [ ] Rejected filter changes preserve the current view and selection.
- [ ] Bulk actions cannot include IDs selected in an earlier filter view.
- [ ] Select all and selection counts match the current visible rows.

## Verification

Select staging rows, switch to production, select a production row and inspect the bulk edit/delete confirmation and submitted IDs. Repeat with service and search filters, then clear filters. Make a description save fail and verify the blocked filter change does not alter selection. Confirm ordinary multiselection within one view still works.

After implementation, run `node --test tests/project-secrets.test.mjs`.

## Related plans

None.
