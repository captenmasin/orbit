# PW12 — Sources Refresh refreshes only local folders

- **Priority:** P2
- **Area:** sources
- **Status:** Implemented
- **Evidence:** Live + source — ShowProject.vue:150–160,236; InspectionController.php:52; ProjectProviderActivity.vue:79
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-264)

## Problem

The generic Refresh action does not refresh remote repository activity and disappears in repository-only projects. Remote refresh lives elsewhere.

Local inspection and hosted-provider activity are separate existing operations. Make their scopes explicit while reusing the provider handler introduced as an accessible row action in PW08.

## Implementation plan

1. In `resources/js/pages/ShowProject.vue`, rename the Sources header action to 'Refresh local folders'; keep it tied to the existing local `refresh()` and scan busy state.
2. Beside each connected repository, add 'Refresh activity' that delegates to the existing `refresh(repository)` in `resources/js/components/ProjectProviderActivity.vue` through the same component ref used by PW08.
3. Explicitly expose its existing `refresh()` and `disabled(repository)` methods alongside PW08's `edit()`; use that shared reactive disabled predicate for offline, token, retry and in-flight states. Do not dispatch remote refresh through local inspection.
4. For disconnected rows show Connect provider instead of a remote refresh action. Ensure repository-only projects still expose their applicable activity action.
5. Extend the row-action wiring coverage in `tests/project-tab.test.mjs`, reusing the existing provider offline test for reconnect and cached-result behavior.

## Acceptance criteria

- [x] Refresh local folders is visibly limited to local inspection.
- [x] A repository-only project exposes remote refresh when connected.
- [x] Offline/token/retry restrictions match the existing provider activity controls.
- [x] Each repository action refreshes only its own saved hosted repository.

## Verification

Run `node --test tests/project-tab.test.mjs tests/provider-offline.test.mjs`. Manually compare local-only, connected repository-only, mixed-source and disconnected projects. Refresh offline, with a token-required connection and during an in-flight request; confirm cached activity remains available and local scan state is unaffected.

## Related plans

- [PW08 — Connect provider is buried](pw08-connect-provider-is-buried.md)
- [PW03 — Editing a remote URL disconnects its provider](pw03-editing-a-remote-url-disconnects-its-provider.md)
- [PW20 — Attention findings vanish during scanning](pw20-attention-findings-vanish-during-scanning.md)

## Completion — 28 September 2026

Sources exposes Refresh local folders plus repository-specific activity refresh with the existing connection/retry restrictions.

Checks: tests/project-tab.test.mjs; tests/Feature/ProviderActivityTest.php; source/template review. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
