# PW02 — Create is available while cloning

- **Priority:** P2
- **Area:** workspace
- **Status:** Planned
- **Evidence:** Source — ProjectForm.vue:166–177,275,445
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-13)

## Problem

Create project does not wait for cloning.processing. Submitting before completion can save a project without the repository and folder the user just selected.

The race is inferred from separate clone and form requests; confirm it with a deliberately slow clone before changing the UI. A clone must finish adding its source data before Create can send that data.

## Implementation plan

1. In `resources/js/components/ProjectForm.vue`, reproduce a slow connected clone after entering a valid project name. Attempt both the Create button and Enter submission before the clone resolves.
2. Extend the existing submit-disabled condition to include `cloning.processing`; add the same early busy check in `submit()` so keyboard or programmatic submission cannot bypass the button.
3. Keep the existing clone status text visible while the action is disabled. Continue using `cloneRepository()` and `useFolder()` to populate the repository, folder and inferred details on success.
4. After cancellation or failure, release the busy state and preserve the user's current fields. Do not add clone retries, a new background service, or an automatic project save.
5. Extend the delayed-request case in `tests/project-tab.test.mjs` to prove no project submission occurs during cloning and the eventual payload contains the selected repository/folder.

## Acceptance criteria

- [ ] Create cannot dispatch while cloning is pending, including Enter submission.
- [ ] A successful clone is linked in the subsequently created project.
- [ ] Cancelled or failed cloning restores Create availability without losing entered details.
- [ ] Existing inspection/save busy conditions continue to apply.

## Verification

Run `node --test tests/project-tab.test.mjs`. In Orbit desktop, clone a disposable repository slowly, attempt Create while the clone status is visible, then create after completion. Repeat picker cancellation and clone failure. Inspect the resulting Sources section and confirm no unexpected project was created during either failure.

## Related plans

- [PW01 — Project forms discard unsaved work](pw01-project-forms-discard-unsaved-work.md)

