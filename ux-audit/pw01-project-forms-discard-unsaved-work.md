# PW01 — Project forms discard unsaved work

- **Priority:** P2
- **Area:** workspace
- **Status:** Implemented
- **Evidence:** Source — ProjectForm.vue:48,72,443; ProjectScratchpad.vue:44
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-7)

## Problem

Cancel, View project, breadcrumbs, sidebar and history can discard typed details, links and source changes. Scratchpad already protects unsaved edits.

Reproduce this source-derived loss risk on a disposable project. Navigation must not silently create or update metadata.

## Implementation plan

1. Reproduce dirty Create/Edit departures through Cancel, View project, sidebar and history. Compare guard lifecycle and cleanup in `resources/js/components/ProjectScratchpad.vue`.
2. In `resources/js/components/ProjectForm.vue`, reuse its `router.on('before')` and `beforeunload` pattern for dirty forms. Use the existing Dialog components for Stay / Discard changes; do not automatically save project creation on navigation.
3. Allow this form's save/archive/delete submissions, successful redirects and same-page section changes. Guard other replacing visits, retain failed-save drafts/errors, and unregister listeners on unmount.
4. Native Back/Forward cannot be cancelled. Use per-project remembered form state for text/source drafts, excluding `icon_file`; explain image re-selection. Compare the recovered draft's saved revision with current props before saving, retaining conflict recovery instead of overwriting newer data.
5. Update the existing `tests/project-tab.test.mjs` harness to exercise stay/discard, successful save, save failure and cleanup. Keep history recovery isolated between create and each project.

## Acceptance criteria

- [x] Dirty in-app departures require an explicit choice; Stay preserves every field.
- [x] Successful saving does not trigger the discard prompt.
- [x] Reload/close warns, and history restoration recovers non-file drafts without crossing project IDs.
- [x] Discard clears remembered drafts; a selected image is never falsely represented as recoverable.

## Verification

Run `node --test tests/project-tab.test.mjs`. Manually test dirty text, sources and an image through sidebar, Cancel, reload, browser Back/Forward and native history. Confirm invalid saves preserve drafts. Inertia v3 behavior was checked through Boost; native history cannot be cancelled.

## Related plans

- [PW02 — Create is available while cloning](pw02-create-is-available-while-cloning.md)
- [PW10 — Archive also commits every form edit](pw10-archive-also-commits-every-form-edit.md)
- [C01 — Changing tabs destroys a document draft](c01-changing-tabs-destroys-a-document-draft.md)
- [S05 — Settings sections handle drafts differently](s05-settings-sections-handle-drafts-differently.md)

## Completion — 28 September 2026

Project forms guard dirty departures and remember per-project non-file drafts, with image re-selection and stale-draft review. Discard clears the dirty baseline before resuming navigation.

Checks: tests/project-tab.test.mjs; tests/Feature/ProjectDetailsTest.php; tests/Feature/RepositoryCloneTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
