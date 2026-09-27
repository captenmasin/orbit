# C16 — Blank service has two labels

Priority: P3  
Area: assets  
Status: Planned  
Evidence: Source — ProjectSecrets.vue:544,574,607  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-227)

## Problem

A blank secret service appears as Unassigned in list grouping and Uncategorized in the detail panel, while the editor calls the field Service / category. These labels imply multiple concepts where the stored model has one optional service value.

## Implementation plan

1. Use Service as the visible field name and Unassigned as the empty-value label in `resources/js/components/ProjectSecrets.vue`, matching the current group heading and service filter.
2. Apply those labels consistently to details, editor, paste dialog, bulk update and accessible group labels. Replace Leave empty to clear category with equivalent service wording.
3. Review related user-facing validation in `app/Http/Controllers/SecretController.php`, especially bulk-service update errors, for the same terminology without changing keys or data rules.
4. Check populated services and optional management links: named values should remain unchanged, and Manage in [service] should continue using the actual value.

## Acceptance criteria

- [ ] Blank services have one identical label in list groups and details.
- [ ] Editing, pasting, filtering and bulk actions all call the field Service.
- [ ] Clearing a service makes the affected item appear under Unassigned.
- [ ] Existing named services and metadata are preserved.

## Verification

Open a disposable secret with no service and compare its group, details and editor. Assign a named service, filter to it, then clear it through the bulk action and inspect the resulting labels. Check empty-state wording and screen-reader group names. Verify a populated management URL still uses the actual service label. This change should require no migration or metadata transformation.

No new automated tests are needed for copy-only changes. If PHP validation wording changes, run `vendor/bin/pint --dirty --format agent` after implementation.

## Related plans

None.
