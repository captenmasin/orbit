# PW08 — Connect provider is buried

- **Priority:** P2
- **Area:** sources
- **Status:** Planned
- **Evidence:** Live + source — ProjectForm.vue:310; ShowProject.vue:240,381; ProjectProviderActivity.vue:79
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-251)

## Problem

Existing-project Edit has no provider picker. Connecting a repository requires finding and expanding “Show provider activity” below Overview.

Expose the existing connection dialog from the Sources rows. Do not duplicate provider verification, add credentials to the project form, or introduce a second repository association endpoint.

## Implementation plan

1. In `resources/js/pages/ShowProject.vue`, locate each repository's corresponding `activity` record and show a 'Connect provider' or 'Connection settings' action beside Open repository.
2. Expose the existing `edit(repository)` method from `resources/js/components/ProjectProviderActivity.vue` through its component ref. Keep the current dialog, errors, revision checks and save handler as the single connection workflow.
3. Use the row action to reveal provider activity and open that repository's dialog immediately; do not require the user to discover the lower disclosure first.
4. Preserve the existing no-connections recovery to Settings Connections, disconnected choice, native credential limitations and disabled/error states.
5. Add a focused wiring case to `tests/project-tab.test.mjs` for a source-row action selecting the right activity record; exercise the existing provider regression suite only if association behavior changes.

## Acceptance criteria

- [ ] Every saved repository offers a visible provider connection entry point in Sources.
- [ ] The action opens settings for the correct repository, including multiple repositories.
- [ ] Provider verification, disconnect wording and conflict recovery use the existing dialog.
- [ ] No-connections and unavailable credentials have an actionable recovery path.

## Verification

Run `node --test tests/project-tab.test.mjs`. Manually connect one of two repositories, edit its connection, disconnect it and visit the no-connections state. If backend association changes, run `php artisan test --compact tests/Feature/ProviderActivityTest.php`. Verify the existing bottom activity disclosure still works.

## Related plans

- [PW03 — Editing a remote URL disconnects its provider](pw03-editing-a-remote-url-disconnects-its-provider.md)
- [PW12 — Sources Refresh refreshes only local folders](pw12-sources-refresh-refreshes-only-local-folders.md)
- [S12 — Connection “Current” overstates verification](s12-connection-current-overstates-verification.md)

