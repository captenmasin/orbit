# PW13 — The same objects change names across screens

- **Priority:** P3
- **Area:** workspace
- **Status:** Planned
- **Evidence:** Live + source — ProjectForm.vue:190–192,315; ShowProject.vue:235,340
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-57)

## Problem

Create uses Details / Sources / Links. Edit uses Overview / Repositories / Links despite containing local folders. Overview calls Links “Shortcuts”.

Standardize visible terminology while preserving internal tab values and existing deep links. This copy change should not invalidate session-stored edit tabs or inbound links.

## Implementation plan

1. Use 'Details', 'Sources' and 'Links' consistently for the project metadata editor in `resources/js/components/ProjectForm.vue`; retain internal values `overview`, `repositories` and `links`.
2. Rename the overview 'Shortcuts' heading to 'Links' in `resources/js/pages/ShowProject.vue`, keeping the count and link management behavior.
3. Use 'Local folders' in both create and edit source sections. Preserve 'Repositories' for the repository subsection rather than as the whole combined Sources tab.
4. Search affected components for guidance, aria labels and actions such as 'View in Sources' and 'Link a folder', aligning their wording without rewriting unrelated backend messages.
5. Check create/edit, the project Sources/Links sections and direct `?tab=repositories` / `?tab=links` routes after the text changes.

## Acceptance criteria

- [ ] The metadata editor uses the same section labels in Create and Edit.
- [ ] Repositories and Local folders are clearly subsections of Sources.
- [ ] Saved project Links use the same name as their editor.
- [ ] Existing tab URLs, session restoration and accessible labels still work.

## Verification

No new automated tests are needed for copy-only changes. Manually move through Create, Edit and Overview, including empty/populated Sources and Links. Open the existing Link a folder/Add links shortcuts and restore a previously selected edit tab. Check narrow layouts for label wrapping and compare wording with the linked package-location plan.

## Related plans

- [PW14 — Create and Edit use different form treatments](pw14-create-and-edit-use-different-form-treatments.md)
- [PW16 — Package location controls have several names](pw16-package-location-controls-have-several-names.md)
- [PW09 — Shortcut rows imply a larger click target](pw09-shortcut-rows-imply-a-larger-click-target.md)

