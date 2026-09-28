# PW16 — Package location controls have several names

- **Priority:** P3
- **Area:** sources
- **Status:** Implemented
- **Evidence:** Live + source — ProjectDependencies.vue:105–140
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-270)

## Problem

Manage folders opens Dependency locations, then Root settings, Add package root, Package root path and Save root. Parent folders and scanned subfolders blur together.

Reserve Linked folder for the saved source parent, and Package location for the directory whose manifest is scanned. This is a visible-language change; database/model identifiers can remain roots.

## Implementation plan

1. In `resources/js/components/ProjectDependencies.vue`, replace 'Manage folders' with 'Manage package locations' and title its dialog 'Package locations'.
2. Rename 'Root settings' to 'Location settings', 'Add package root' to 'Add package location', 'Package root path' to 'Package location path', and Save/Remove root to Save/Remove location.
3. Align button labels, empty states, help and accessible names around the same vocabulary. Keep the parent selector labeled 'Linked folder'.
4. Explain that a location is the linked folder itself or a directory inside it containing `composer.json` or `package.json`; use the current containment validation rather than a new directory model.
5. Coordinate the Advanced executable wording with PW17 and review Add, edit, remove and validation states together.

## Acceptance criteria

- [x] Every user-facing dependency location action uses the same noun.
- [x] Linked folder remains visibly distinct from its package locations.
- [x] Help explains allowed locations without exposing internal model names.
- [x] Existing validation, save/delete payloads and folder containment behavior are unchanged.

## Verification

No new tests are needed for copy-only edits. Manually open Dependencies with no folders, an unconfigured folder and multiple locations; add/edit/remove a location and trigger an outside-parent validation error. Confirm button text fits narrow dialogs and server errors are understandable in context. If validation messages are also changed, run the affected existing LocalInspectionTest case.

## Related plans

- [PW17 — Basic location setup is dominated by overrides](pw17-basic-location-setup-is-dominated-by-overrides.md)
- [PW11 — Browser Relink uses a detached input](pw11-browser-relink-uses-a-detached-input.md)
- [PW13 — The same objects change names across screens](pw13-the-same-objects-change-names-across-screens.md)


## Completion — 28 September 2026

Dependency controls consistently use Package location and distinguish it from Linked folder.

Checks: tests/dependencies.test.mjs; tests/Feature/RuntimeProbeTest.php; tests/Feature/DependencyUpdatesTest.php; template review. These checks passed in the full suites; production build and lint of changed Vue files also passed.
