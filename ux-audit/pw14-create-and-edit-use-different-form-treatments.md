# PW14 — Create and Edit use different form treatments

- **Priority:** P3
- **Area:** workspace
- **Status:** Implemented
- **Evidence:** Live + source — CreateProject.vue:9; EditProject.vue:11; ProjectForm.vue:194–224
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-64)

## Problem

The same workflow changes heading size, card framing, input height, radius, spacing and page width between New project and Edit.

Use Orbit's existing shared controls as the baseline. Unify the presentation in the existing ProjectForm instead of adding separate create/edit form components or a new design system.

## Implementation plan

1. Compare `resources/js/pages/CreateProject.vue`, `resources/js/pages/EditProject.vue` and the conditional markup in `resources/js/components/ProjectForm.vue` at the same viewport.
2. Give Edit the same page heading size, width cap, vertical spacing and single content wrapper as New project. Keep its project-specific title and View project action.
3. Render common details/source/link sections with the same existing Card/CardHeader/CardContent framing; retain the create-only Start from folder/repository aside because it is a distinct action.
4. Remove create-only height, radius and background overrides from shared Input, Textarea, ChoiceSelect and button usages. Follow their existing variants/sizes and `.ai/rules/resources.md`.
5. Keep the same field ordering, required/optional copy, labels, error associations and responsive source layout. Do not change validation or submission during this visual cleanup.

## Acceptance criteria

- [x] Identical fields have matching control height, radius and spacing in Create and Edit.
- [x] Both pages use consistent heading/container and section framing.
- [x] Create-specific starting actions remain distinct without changing shared fields.
- [x] Labels, errors, focus states and narrow-screen wrapping remain accessible.

## Verification

No new tests are required for this styling/layout change. Manually compare empty and populated Create/Edit at narrow and wide widths, in both themes, including repositories, folders, link details and validation errors. Check image/emoji fields and all footer actions. A frontend preview requires the existing build/dev workflow; do not start another server.

## Related plans

- [PW13 — The same objects change names across screens](pw13-the-same-objects-change-names-across-screens.md)
- [PW04 — Full project descriptions are hidden](pw04-full-project-descriptions-are-hidden.md)


## Completion — 28 September 2026

Create/Edit reuse the same section framing, page heading treatment and shared field/button styles.

Checks: tests/project-tab.test.mjs; tests/Feature/ProjectDetailsTest.php; tests/Feature/RepositoryCloneTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.
