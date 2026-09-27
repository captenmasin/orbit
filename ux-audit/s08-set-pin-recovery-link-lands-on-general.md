# S08 — Set PIN recovery link lands on General

Priority: P2

Area: settings

Status: Planned

Evidence: Source — ProjectSecrets.vue:521; Settings.vue:31,161

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-358)

## Problem

Secrets’ Set PIN in settings CTA opens /settings, whose default is General. The required PIN controls are in Security. The CTA promises a specific setup task, so the destination should reveal those controls immediately rather than requiring another navigation decision.

## Implementation plan

1. Complete S10's URL-backed section handling so the Security destination works on fresh loads, same-component visits and history navigation.
2. In `resources/js/components/ProjectSecrets.vue`, change the existing Set PIN in settings link to the canonical Security section destination supported by `resources/js/pages/Settings.vue`.
3. Keep the existing native-only, no-PIN visibility conditions. Use the existing Link/Button treatment and retain the clear task-specific wording.
4. Confirm the return path through app/browser Back restores the project Secrets view. Preserve PIN-status refresh and credential cleanup rather than passing PIN values through navigation.
5. Add a focused destination/visibility regression to `tests/project-secrets.test.mjs`; cover direct Security entry with `tests/settings-preferences.test.mjs`.

## Acceptance criteria

- [ ] The setup CTA lands directly on the Secrets PIN controls.
- [ ] It works from both a fresh Settings visit and an existing Settings history entry.
- [ ] Browser-only and already-configured PIN states retain their current guidance.
- [ ] Returning to the project does not expose or carry PIN values.

## Verification

In the desktop app with no PIN, open a project's Secrets tab and follow the CTA. Confirm Security is selected and setup is visible, then use Back. Also inspect browser-only guidance and a workspace with an existing PIN.

Run `node --test tests/project-secrets.test.mjs tests/settings-preferences.test.mjs`. No changes to PIN validation or storage are required.

## Related plans

- [S10 — Settings navigation has no URL or history](s10-settings-navigation-has-no-url-or-history.md)

