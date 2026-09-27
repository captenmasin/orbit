# C07 — Autosave lacks persistent saved/failed status

Priority: P2  
Area: content  
Status: Planned  
Evidence: Live + source — ProjectScratchpad.vue:123–128,190–192; SecretDescription.vue:31,152  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-139)

## Problem

Scratchpad shows only transient Saving text, then becomes blank; network failure is a disappearing toast without an inline retry. Secret descriptions autosave without any visible save status. Users cannot reliably distinguish a saved note from an unsaved or failed draft.

## Implementation plan

1. In `resources/js/components/ProjectScratchpad.vue`, render persistent Saved, Saving and Unsaved status from the existing dirty/processing state. Record save failure inline without removing the user's notes.
2. Add a Retry save button for recoverable scratchpad failures. Keep revision conflicts on their existing reload path, and never claim Saved until the submitted content is confirmed and no newer draft remains.
3. In `resources/js/components/SecretDescription.vue`, show the same status vocabulary using existing dirty/saving/error state and retain its current Retry save and keep-draft conflict recovery.
4. Use existing text/button styles and accessible status/alert patterns directly in both components. Avoid introducing a shared autosave framework for two short status rows.
5. Extend `tests/project-tab.test.mjs` and `tests/secret-description.test.mjs` for failure/retry and edits made during a save.

## Acceptance criteria

- [ ] Both autosave surfaces distinguish Saved, Saving and Unsaved visibly.
- [ ] Failed saves retain the draft and an actionable inline error.
- [ ] New edits during a request remain Unsaved until their own save succeeds.
- [ ] Retry/reload states never imply that a failed draft was saved.

## Verification

Edit each surface, observe debounce and save completion, then type again while a request is in flight. Simulate network failure and a revision conflict; confirm persistent feedback and successful recovery. Check status announcements with keyboard/screen-reader navigation and verify secret description controls become read-only when locked.

After implementation, run `node --test tests/project-tab.test.mjs tests/secret-description.test.mjs`.

## Related plans

- [C05 — Writing notes automatically runs AI](c05-writing-notes-automatically-runs-ai.md)
- [C10 — Vault expiry can discard a value draft silently](c10-vault-expiry-can-discard-a-value-draft-silently.md)
