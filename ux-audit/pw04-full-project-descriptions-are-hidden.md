# PW04 — Full project descriptions are hidden

- **Priority:** P2
- **Area:** workspace
- **Status:** Implemented
- **Evidence:** Source — ProjectCard.vue:23; ProjectHeader.vue:27
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-19)

## Problem

Dashboard descriptions are clamped to two lines; the detail header description is commented out. Reading the rest requires Edit.

A project description belongs on its read view as well as its editor. Keep the existing dashboard preview compact while making the complete value accessible on the detail page.

## Implementation plan

1. Confirm current rendering in `resources/js/components/ProjectCard.vue` and the commented description in `resources/js/components/ProjectHeader.vue`; use a description longer than two lines.
2. Restore the existing conditional description paragraph below the project title/status in `ProjectHeader.vue`, retaining whitespace preservation, word wrapping and the shared muted supporting-text treatment.
3. Use the header's available width and existing responsive structure. Avoid adding a new description modal or another component for a paragraph already supported by the header.
4. Verify empty descriptions do not leave blank space and long URLs/unbroken words wrap. Preserve the title, tags, archive timestamp, status error and Edit action hierarchy.
5. Review the resulting header alongside PW14's shared page/form treatment so typography remains consistent in light and dark appearance.

## Acceptance criteria

- [x] The complete saved description is readable on the project detail page.
- [x] An empty description adds no empty panel or placeholder.
- [x] Multiline and very long descriptions wrap without overlapping status or Edit.
- [x] Dashboard previews remain compact and still lead to the full detail.

## Verification

No new automated tests are needed for this display-only change. Manually inspect an empty description, one short sentence, multiple paragraphs, a long URL and a 10,000-character value at narrow and wide widths in both themes. Confirm screen-reader reading order places the description after the project identity and before section content.

## Related plans

- [PW14 — Create and Edit use different form treatments](pw14-create-and-edit-use-different-form-treatments.md)


## Completion — 28 September 2026

Project detail headers display the complete multiline saved description, while dashboard previews remain compact.

Checks: tests/project-tab.test.mjs; tests/Feature/ProviderActivityTest.php; source/template review. These checks passed in the full suites; production build and lint of changed Vue files also passed.
