# S15 — Runtime results expose jargon without next steps

Priority: P2

Area: settings

Status: Implemented

Evidence: Live + source — Settings.vue:171; ProbeRuntimes.php:20–154

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-402)

## Problem

A single Current / Missing executable · No version · path · source string mixes status and detail. Package-root / underlying executable wording lacks practical guidance. A runtime result should identify what was found and how a user can recover, without requiring interpretation of internal source labels.

## Implementation plan

1. In `resources/js/pages/Settings.vue`, render each existing runtime result as labeled Status, Version, Executable path and Source details beside that runtime's input. Use current typography and FieldDescription styling.
2. Translate automatic/global source labels to Automatic detection and Workspace default. Explain that a per-project package-location override takes priority over this page.
3. Add short actionable guidance for missing executables and rejected wrappers. Tell users to choose an installed executable/package-manager entry file, using the existing Browse and Check paths controls.
4. Derive supported guidance from `app/Actions/ProbeRuntimes.php`; preserve its exclusions for project/bundled executables and wrapper validation. Do not execute rejected candidates or introduce detection fallbacks.
5. Coordinate S09's field-specific failures so the failing runtime is identified once. Retain stale-probe suppression when edited paths differ from submitted paths.

## Acceptance criteria

- [x] Each result separates status, version, path and effective source.
- [x] Missing/rejected results name the runtime and offer a next step.
- [x] Workspace versus project precedence is understandable.
- [x] Existing executable restrictions and unsaved probe behavior remain intact.

## Verification

Inspect automatic detection, an explicit workspace path, a missing path and an unsupported wrapper. Edit a path during a delayed probe and confirm old results stay hidden. Check long paths at narrow widths.

No new tests are required for presentation-only changes. Run `node --test tests/settings-preferences.test.mjs` if the result rendering changes existing probe-state handling.

## Related plans

- [S09 — Validation is repeated or far from its field](s09-validation-is-repeated-or-far-from-its-field.md)


## Completion — 28 September 2026

Runtime probes separate status, version, executable path and effective source, with detection/override precedence and executable-selection guidance.

Checks: tests/settings-preferences.test.mjs; tests/settings.test.mjs; tests/workspace-backups.test.mjs; tests/Feature/WorkspacePreferencesTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
