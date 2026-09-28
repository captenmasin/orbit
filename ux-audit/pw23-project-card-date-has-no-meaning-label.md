# PW23 — Project card date has no meaning label

- **Priority:** P3
- **Area:** workspace
- **Status:** Implemented
- **Evidence:** Live + source — ProjectCard.vue:28; Project.php:73
- **Audit:** [Exact Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-89)

## Problem

The card shows a bare date or an em dash. It means latest commit, rather than latest project edit, which is easy to misread.

The card's timestamp is the latest known local or remote commit, not Orbit's updated_at. Name the existing value without replacing it with a different metric.

## Implementation plan

1. Trace `last_commit_at` from `app/Models/Project.php::scopeWithLatestCommit()` into `resources/js/components/ProjectCard.vue` and compare projects with no Git sources.
2. Add visible 'Last commit' text beside the date; replace the em dash with 'No commit data'. Keep the existing semantic time element for dated values.
3. Use the shared muted footer typography and current wrapping behavior, allowing the metadata text and Edit action to fit without making the date a new primary heading.
4. Preserve locale-based date formatting and local/remote latest-commit selection. Do not update the timestamp when only project details change.
5. Check the wording beside PW05's current-page result count so both dashboard numbers have a clear meaning.

## Acceptance criteria

- [x] A dated card explicitly identifies its timestamp as Last commit.
- [x] Cards without commit data show readable text rather than an unexplained dash.
- [x] Project edits do not imply a newer commit.
- [x] Footer text wraps without obscuring source counts or Edit.

## Verification

No new tests are needed for this copy/display change. Manually inspect local-commit, remote-only, missing-commit and non-Git projects, then edit a description and confirm the commit date's meaning stays unchanged. Check long project names, narrow cards and locale date formats. Backend latest-commit behavior should remain untouched.

## Related plans

None.


## Completion — 28 September 2026

Project cards identify timestamps as Last commit and show No commit data when no commit is available.

Checks: tests/project-sidebar.test.mjs; tests/project-context-menu.test.mjs; tests/Feature/ProjectDuplicationTest.php; dashboard menu/confirmation browser check. These checks passed in the full suites; production build and lint of changed Vue files also passed.
