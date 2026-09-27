# PW18 — Dependency “target” is ambiguous

- **Priority:** P3
- **Area:** sources
- **Status:** Planned
- **Evidence:** Live + source — ProjectDependencies.vue:37,119,129
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-283)

## Problem

Installed → target can show the latest release rather than the advisory’s fixed version. Users cannot tell latest available from the smallest security fix.

A registry's newest stable version is not a promised advisory fix. Use distinct factual labels and preserve every advisory's fixed-version list rather than calculating a universal or smallest safe upgrade.

## Implementation plan

1. Inspect `resources/js/components/ProjectDependencies.vue`'s `targetVersion` calculation and current latest/fixed-version data in `resources/js/lib/dependencies.ts` before changing presentation.
2. Replace the combined 'Installed → target' cell with clearly labeled Installed and Latest available values. When latest is absent, show 'Not reported' rather than substituting a security-fixed version.
3. Keep fixed versions in the existing Review issue dialog, labeling them as advisory fixed versions. Continue showing the existing explicit message when an advisory supplies no fix.
4. Remove the unused target fallback calculation. Keep severity, package location, direct/transitive identity and scope intact; do not change dependency-check APIs or infer compatibility.
5. Check packages that have only an update, only a security issue, both, multiple advisories and no fixed version; coordinate the review footer action wording with PW19.

## Acceptance criteria

- [ ] Latest available and advisory fixed versions are visually distinct.
- [ ] Missing latest information never becomes a misleading target version.
- [ ] All supplied fixed versions remain visible per advisory.
- [ ] No text promises that a newest version is compatible or fixes every advisory.

## Verification

No new tests are required for label/layout-only changes. Manually inspect update-only, security-only, combined and no-fix findings, including a package with fixes on multiple release lines. If data merging is touched, run `node --test tests/dependencies.test.mjs`; otherwise preserve the existing merging logic. Check table scrolling and review dialog readability at narrow widths.

## Related plans

- [PW19 — View release destinations differ](pw19-view-release-destinations-differ.md)
- [PW16 — Package location controls have several names](pw16-package-location-controls-have-several-names.md)

