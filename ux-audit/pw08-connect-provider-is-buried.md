# PW08 — Connect provider is buried

- **Priority:** P2
- **Area:** sources
- **Status:** Implemented
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

- [x] Every saved repository offers a visible provider connection entry point in Sources.
- [x] The action opens settings for the correct repository, including multiple repositories.
- [x] Provider verification, disconnect wording and conflict recovery use the existing dialog.
- [x] No-connections and unavailable credentials have an actionable recovery path.

## Verification

Run `node --test tests/project-tab.test.mjs`. Manually connect one of two repositories, edit its connection, disconnect it and visit the no-connections state. If backend association changes, run `php artisan test --compact tests/Feature/ProviderActivityTest.php`. Verify the existing bottom activity disclosure still works.

## Related plans

- [PW03 — Editing a remote URL disconnects its provider](pw03-editing-a-remote-url-disconnects-its-provider.md)
- [PW12 — Sources Refresh refreshes only local folders](pw12-sources-refresh-refreshes-only-local-folders.md)
- [S12 — Connection “Current” overstates verification](s12-connection-current-overstates-verification.md)


## Completion — 28 September 2026

Saved repository rows expose Connect provider or settings actions that delegate to the existing repository-specific activity dialog.

Checks: tests/project-tab.test.mjs; tests/Feature/ProviderActivityTest.php; source/template review. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
