# S10 — Settings navigation has no URL or history

Priority: P2

Area: settings

Status: Planned

Evidence: Live + source — Settings.vue:31,71,144–145; SettingsController.php:49

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-371)

## Problem

Section buttons change only a local ref. Refresh returns to the route’s original section; Back/Forward skips section changes. Deep links, the mobile picker and app history need one consistent selected-section source.

## Implementation plan

1. Use the existing `section` query parameter in `app/Http/Controllers/SettingsController.php` as the canonical Settings section destination. Preserve existing Connections/Backups entry routes in `routes/web.php`.
2. In `resources/js/pages/Settings.vue`, route both desktop section buttons and the mobile Section picker through the existing Inertia navigation pattern, creating a history entry for a real section change.
3. Synchronize the selected section with incoming props and history navigation. Resolve unsupported values to General and avoid redundant visits when selecting the current section.
4. Preserve preference form state across section navigation, applying S05's draft policy and existing Appearance preview cleanup. A direct page reload should reconstruct the section from its URL.
5. Extend `tests/settings-preferences.test.mjs` for prop synchronization and draft preservation, and `tests/Feature/WorkspacePreferencesTest.php` for direct section entry.

## Acceptance criteria

- [ ] Every selected section has a shareable, reload-safe URL.
- [ ] Back/Forward traverses section choices and updates the active label.
- [ ] Desktop navigation and the mobile picker behave identically.
- [ ] Invalid sections fall back safely; legacy entry routes still work.
- [ ] Section visits do not silently discard dirty preferences.

## Verification

Open Security directly, visit Appearance then Tools, reload and use Back/Forward. Repeat through the mobile picker, an unsupported query and the legacy Connections/Backups URLs. Verify drafts survive section transitions.

Run `node --test tests/settings-preferences.test.mjs` and `php artisan test --compact tests/Feature/WorkspacePreferencesTest.php`.

## Related plans

- [S05 — Settings sections handle drafts differently](s05-settings-sections-handle-drafts-differently.md)

