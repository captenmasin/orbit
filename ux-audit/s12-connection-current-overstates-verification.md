# S12 — Connection “Current” overstates verification

Priority: P2

Area: settings

Status: Planned

Evidence: Live + source — ProviderConnections.vue:64–83; ProviderController.php:45; ReadProvider.php:14

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-383)

## Problem

Saving verifies account identity, then shows Current. Repository permissions, organization approval and activity access are checked only when used. The account verification outcome is useful, but users should not infer that every repository permission or cached resource is current.

## Implementation plan

1. Verify the identity-only save boundary in `app/Http/Controllers/ProviderController.php` and `app/Actions/ReadProvider.php`. Keep its stored state and credential verification behavior intact.
2. In `resources/js/components/ProviderConnections.vue`, display the healthy connection label as Account verified, leaving existing Token required and Rate limited states distinct.
3. Add concise setup/list guidance: account identity is verified when saving; repository permissions and organization approval are checked when accessing repository activity.
4. Explain the existing recovery actions beside non-healthy states: replace expired/invalid tokens, or wait until the shown retry time for rate limits. Reuse current buttons rather than adding another network probe.
5. Check wording against `app/Actions/ProviderHttp.php` so it matches actual failures and does not promise verified scopes the app has never queried.

## Acceptance criteria

- [ ] Healthy connection wording describes account verification accurately.
- [ ] Repository-access limits are visible before users expect activity to load.
- [ ] Token and rate-limit states point to the appropriate existing action.
- [ ] Provider identity, stored states and request behavior remain unchanged.

## Verification

Manually inspect a newly verified connection, an insufficient-permission repository, a Token required connection and a rate-limited connection. Confirm the list never presents identity verification as complete repository access, and that retry guidance matches the displayed time.

No new automated tests are required for presentation-only wording. If implementation changes verification or provider request behavior, that exceeds this plan and requires the existing provider feature coverage.

## Related plans

None.

