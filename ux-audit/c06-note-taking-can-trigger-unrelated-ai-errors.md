# C06 — Note-taking can trigger unrelated AI errors

Priority: P2  
Area: content  
Status: Planned  
Evidence: Source — ProjectScratchpad.vue:37–42,167; WorkspaceController.php:200  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-133)

## Problem

Background generation currently displays configuration and no-action errors during normal note-taking. Users can receive a request to configure AI despite never choosing an AI action. C05 removes the background trigger; this plan makes setup and failure feedback belong to explicit generation requests.

## Implementation plan

1. Apply C05's button-only generation flow in `resources/js/components/ProjectScratchpad.vue`; do not add a second background mechanism or request solely to check configuration.
2. Review `generateActions()` feedback paths so setup errors, generation failures and no-clear-actions results are shown only following the user's Generate actions request. Keep note autosave errors separate and specific to saving notes.
3. Preserve `app/Http/Controllers/WorkspaceController.php` bare-URL behavior: an explicitly requested valid URL can become a link without an AI connection. Keep the existing setup message for explicit non-URL generation.
4. Update `tests/project-tab.test.mjs` to cover quiet typing with no AI, explicit setup feedback, explicit provider failure and the URL shortcut. Reuse existing backend scratchpad coverage instead of changing generation APIs.

## Acceptance criteria

- [ ] Ordinary note-taking produces no AI setup or generation-error notification.
- [ ] Explicit generation without a connection gives a useful Settings/Connections next step.
- [ ] Explicit generation failure keeps notes and permits another attempt.
- [ ] Explicit bare-URL conversion still works without an AI connection.

## Verification

Remove the test AI connection, type a plain reminder and wait beyond autosave and the former generation delay; verify there is no AI error. Press Generate and check the setup guidance. Configure a deliberately unavailable provider for a failure check, then retry. Test a plain URL separately and ensure success is not blocked by missing AI setup.

After implementation, run `node --test tests/project-tab.test.mjs` and `php artisan test --compact tests/Feature/ProjectScratchpadTest.php`.

## Related plans

- [C05 — Writing notes automatically runs AI](c05-writing-notes-automatically-runs-ai.md)
