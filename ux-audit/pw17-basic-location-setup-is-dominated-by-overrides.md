# PW17 — Basic location setup is dominated by overrides

- **Priority:** P3
- **Area:** sources
- **Status:** Implemented
- **Evidence:** Live + source — ProjectDependencies.vue:67,132–140; ProbeRuntimes.php:18–66
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-276)

## Problem

Six executable fields appear before Save for a simple package location. Inherited automatic detection is not shown.

Location selection is the first task. Overrides are optional corrections; display actual scan data instead of guessing detected machine paths.

## Implementation plan

1. In `resources/js/components/ProjectDependencies.vue`, keep Linked folder and Package location path above the footer and move the six executable override fields into the existing Collapsible pattern used by `resources/js/components/ProjectForm.vue`.
2. Label the disclosure 'Advanced: executable overrides' and explain that blank fields inherit app detection/settings. Preserve entered overrides when the disclosure closes.
3. For existing locations, derive the selected root from `edit.id` and show its `snapshot.runtimes` version/path/source/state as last checked information. New or unscanned locations should say detection is not available yet.
4. Do not confuse saved probe results with changed override drafts: label results as last checked and make a selected manual override clear. Link to existing runtime settings for global configuration.
5. Open Advanced automatically when an executable field has a validation error or existing override requiring review; retain the current root save payload and backend validation.

## Acceptance criteria

- [x] A basic location can be added without scrolling through executable fields.
- [x] Advanced disclosure preserves values and reveals override errors.
- [x] Detected values use recorded data with their source and check state.
- [x] Blank inheritance and manual overrides are distinguishable.

## Verification

Manually add a fresh location, edit one with automatic probes, edit one with overrides and submit an invalid executable path. Check keyboard disclosure use and both themes. Extend the existing dependency component case if error-driven opening adds logic; run `node --test tests/dependencies.test.mjs`. Probe semantics remain covered by `php artisan test --compact tests/Feature/RuntimeProbeTest.php` if changed.

## Related plans

- [PW16 — Package location controls have several names](pw16-package-location-controls-have-several-names.md)
- [S15 — Runtime results expose jargon without next steps](s15-runtime-results-expose-jargon-without-next-steps.md)


## Completion — 28 September 2026

Executable overrides live in an advanced disclosure that opens for existing overrides/errors; recorded runtime state, path and source remain visible.

Checks: tests/dependencies.test.mjs; tests/Feature/RuntimeProbeTest.php; tests/Feature/DependencyUpdatesTest.php; template review. These checks passed in the full suites; production build and lint of changed Vue files also passed.
