# PW20 — Attention findings vanish during scanning

- **Priority:** P3
- **Area:** sources
- **Status:** Planned
- **Evidence:** Source — ShowProject.vue:133,141,209,301
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-295)

## Problem

Any queued or scanning location hides the whole Needs attention pill and card, including known findings from other folders.

Reproduce the source-derived disappearance with one current confirmed finding and a different folder scanning. Current results must remain visible; findings invalidated by their own changed package files must not be presented as current.

## Implementation plan

1. In `resources/js/pages/ShowProject.vue`, inspect `pending`, `needsAttention` and the attention count alongside `currentDependencyCheck()` in `resources/js/lib/dependencies.ts`.
2. Remove the blanket pending-scan veto from attention visibility. Derive the displayed count from currently valid dependency findings and current folder issues, retaining the library's stale-result checks.
3. When scans are pending and confirmed findings remain, add a small 'Updating sources…' status to the existing attention card/pill area instead of removing the card.
4. Keep existing Review sources/Open dependencies destinations functional while scanning. Do not invent a separate cached health model or freeze obsolete results.
5. Replace the test expecting all attention to hide in `tests/project-tab.test.mjs` with cases for another folder scanning, the affected root becoming stale, and the completed scan resolving its issue.

## Acceptance criteria

- [ ] A scan in one folder does not hide current findings from another.
- [ ] Updating status is visible without replacing confirmed issue counts.
- [ ] Invalidated findings are not mislabeled as current.
- [ ] Attention links remain actionable throughout scanning and completion.

## Verification

Run `node --test tests/project-tab.test.mjs tests/dependencies.test.mjs`. Manually queue local inspection with two folders, one carrying a confirmed issue. Observe the attention card during queued/scanning/current states, then change the affected lockfile and confirm stale findings are removed rather than preserved as current.

## Related plans

- [PW12 — Sources Refresh refreshes only local folders](pw12-sources-refresh-refreshes-only-local-folders.md)

