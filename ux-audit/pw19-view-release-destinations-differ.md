# PW19 — View release destinations differ

- **Priority:** P3
- **Area:** sources
- **Status:** Implemented
- **Evidence:** Live + source — ProjectDependencies.vue:119,129; lib/dependencies.ts:128
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-289)

## Problem

npm opens a specific version; Composer opens the general package page despite the action’s version-specific name.

Use truthful action wording for the links already available. Avoid guessing Composer release URLs or adding a registry integration solely to make the two ecosystems look identical.

## Implementation plan

1. Inspect `dependencyReleaseUrl()` in `resources/js/lib/dependencies.ts` to confirm Composer goes to a Packagist package page and npm goes to its version page.
2. In `resources/js/components/ProjectDependencies.vue`, label Composer actions 'View package' in both the findings table and review footer; retain 'View release' and the latest version for npm.
3. Update accessible names to include the package and, when actually applicable, its release version. Keep destination validation and existing new-tab attributes.
4. Coordinate the footer wording with PW18's separate Latest available and advisory fixed-version information, so a package-page action cannot imply it opens the recommended fix.
5. Leave `dependencyReleaseUrl()` intact unless a verified destination defect is found during implementation; this plan is action-copy alignment.

## Acceptance criteria

- [x] Composer links are named View package wherever they appear.
- [x] npm version links remain named as release actions.
- [x] Accessible names identify the destination package and correct scope.
- [x] Existing safe-link rules and tab behavior are preserved.

## Verification

No new automated tests are needed for copy-only changes. Manually open a Composer update, npm update and each ecosystem's security-review footer; confirm the actual page matches the action name. Test a package with no latest version so no version-specific promise appears. If URL generation changes, run `node --test tests/dependencies.test.mjs` rather than adding a new external-network check.

## Related plans

- [PW18 — Dependency “target” is ambiguous](pw18-dependency-target-is-ambiguous.md)


## Completion — 28 September 2026

Composer actions read View package; npm actions read View release, matching their existing destinations.

Checks: tests/dependencies.test.mjs; tests/Feature/RuntimeProbeTest.php; tests/Feature/DependencyUpdatesTest.php; template review. These checks passed in the full suites; production build and lint of changed Vue files also passed.
