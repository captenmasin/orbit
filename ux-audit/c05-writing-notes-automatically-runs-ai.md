# C05 — Writing notes automatically runs AI

Priority: P2  
Area: content  
Status: Planned  
Evidence: Source — ProjectScratchpad.vue:37–42,122,188; WorkspaceController.php:211–234  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-126)

## Problem

Writing and saving notes automatically schedules AI generation. The scratchpad's ordinary notes placeholder does not explain that paused typing sends notes and limited project context to the configured provider. The smallest clear flow is to generate suggestions only when the user presses the existing action button.

## Implementation plan

1. In `resources/js/components/ProjectScratchpad.vue`, remove background preview scheduling after note changes and successful saves, including preview timers and their now-unused bookkeeping. Keep the autosave timer and navigation protection.
2. Keep `generateActions()` reachable from the existing button. If notes are dirty, save them first and continue that explicit request after the successful save using the existing pending-action mechanism.
3. Retain cached suggestions for unchanged notes and invalidate them when notes change. Keep generation disabled while saving/generating and avoid duplicate requests from repeated clicks.
4. Check `resources/js/components/ScratchpadAiSettings.vue` disclosure against the explicit flow. Update `tests/project-tab.test.mjs` so typing/autosave produces no preview request, while a deliberate button request generates and opens review.

## Acceptance criteria

- [ ] Typing, autosaving, opening and switching project tabs never generate AI actions.
- [ ] Pressing Generate actions saves pending notes, then requests suggestions once.
- [ ] Save failure prevents generation and preserves notes.
- [ ] Review and cached-suggestion behavior remain available for unchanged notes.

## Verification

With AI configured, type notes and wait longer than the former preview delay; confirm only the note-save request occurs. Press Generate, verify the correct saved text is submitted, then edit while saving and repeat. Test a save failure and repeated clicks. With no AI configured, verify ordinary notes remain usable; explicit setup feedback is handled in C06.

After implementation, run `node --test tests/project-tab.test.mjs` and `php artisan test --compact tests/Feature/ProjectScratchpadTest.php`.

## Related plans

- [C06 — Note-taking can trigger unrelated AI errors](c06-note-taking-can-trigger-unrelated-ai-errors.md)
- [C07 — Autosave lacks persistent saved/failed status](c07-autosave-lacks-persistent-saved-failed-status.md)
