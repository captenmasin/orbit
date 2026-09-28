# PW24 — Disabled Open folder gives no explanation

- **Priority:** P3
- **Area:** sources
- **Status:** Implemented
- **Evidence:** Source — OpenTargetButton.vue:23; ConnectedRepositoryPicker.vue:77
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-302)

## Problem

Browser users can add and inspect local paths, but Open folder is disabled without visible desktop guidance.

Keep the browser restriction intact and explain it at the shared opening boundary. Disabled controls cannot reliably receive hover/focus, so the reason must not depend solely on an inaccessible tooltip.

## Implementation plan

1. Audit callers of `resources/js/components/OpenTargetButton.vue` in `ProjectForm.vue` and `resources/js/pages/ShowProject.vue`, distinguishing folders from link/repository browser anchors.
2. For non-native folder opening, add visible help beside the disabled button: 'Open folders in the Orbit desktop app.' Reuse the desktop wording in `resources/js/components/ConnectedRepositoryPicker.vue`.
3. Ensure the help has an associated accessible description. If compact callers use a tooltip, attach it to a focusable wrapper and retain a discoverable text equivalent.
4. Leave browser links/repositories usable through their existing anchors; native folder buttons should continue using the saved target endpoint and report real open failures.
5. Avoid inventing a custom desktop deep link or an Open in desktop action that the application does not support.

## Acceptance criteria

- [x] Browser users can read why folder opening is unavailable.
- [x] The reason is accessible without focusing a disabled element.
- [x] Native folder opening and browser external-link behavior remain unchanged.
- [x] No unsupported desktop-launch URL is introduced.

## Verification

Manually compare folder actions in Edit and Overview using browser and desktop; check keyboard and screen-reader description, including compact controls if present. Copy-only helper changes need no new tests. If opening branches change, run `php artisan test --compact tests/Feature/ProjectCatalogTest.php --filter=test_native_open_actions_use_only_the_selected_saved_target`.

## Related plans

- [PW09 — Shortcut rows imply a larger click target](pw09-shortcut-rows-imply-a-larger-click-target.md)
- [PW11 — Browser Relink uses a detached input](pw11-browser-relink-uses-a-detached-input.md)


## Completion — 28 September 2026

Browser folder-opening controls have visible desktop-only guidance and an associated accessible description.

Checks: tests/project-tab.test.mjs; shared opening-target template review. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
