# Orbit UX audit — implementation plans

Status: implemented (65/65 findings). These files turn all **65 findings** from the [Figma audit](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=4-2) into separate implementation plans. The audit is in Mason Day’s team, dated 27 September 2026.

Each plan preserves its audit ID and priority, links to the exact Figma finding, identifies the current source, and includes implementation steps, acceptance criteria and focused verification. The fixes are implemented in the application; each plan records its completion and verification limits.

## Completed verification

- PHP: `php artisan test --compact` — 500 passed, 4,024 assertions.
- Frontend: `pnpm test` — 218 passed.
- `pnpm run build`, lint of all changed Vue files and `vendor/bin/pint --dirty --format agent` passed.
- Browser checks covered project draft Stay/Discard, visible duplicate confirmation/cancellation, Settings section URLs, and rendered project/backup controls. Destructive restore/deletion and credential failures used disposable automated fixtures.
- The startup-history migration was applied locally. Existing installations must run migrations when updating.
- Native pickers/history/keychain/clipboard, desktop-only integrations and external-client connection setup still need an installed desktop smoke test; they were not exercised against user credentials or workspace backups.

## Priorities

| Priority | Findings | Meaning |
| --- | ---: | --- |
| P1 | 6 | Prevent data loss or applying the wrong restore state. |
| P2 | 35 | Fix confusing workflows, state, recovery and interaction. |
| P3 | 24 | Make wording, presentation and discoverability consistent. |

## Original implementation order

1. Address the P1 findings: [C01](c01-changing-tabs-destroys-a-document-draft.md), [C02](c02-board-conflict-recovery-closes-the-draft.md), [C03](c03-failed-env-paste-clears-the-input.md), [C04](c04-secret-bulk-actions-include-hidden-selections.md), [S01](s01-restore-can-leave-the-previous-backup-actionable.md), [S02](s02-restored-defaults-can-display-stale-values.md).
2. Coordinate related state changes: [S01](s01-restore-can-leave-the-previous-backup-actionable.md), [S02](s02-restored-defaults-can-display-stale-values.md), [S03](s03-restore-wording-understates-removed-credentials.md), [S04](s04-restore-failure-leaves-an-unusable-retry-form.md) for restore; [S02](s02-restored-defaults-can-display-stale-values.md), [S05](s05-settings-sections-handle-drafts-differently.md), [S06](s06-invisible-edits-can-disable-update-installation.md), [S07](s07-mid-save-edits-can-be-marked-saved-falsely.md) for Settings drafts and saved defaults; [S10](s10-settings-navigation-has-no-url-or-history.md), [S08](s08-set-pin-recovery-link-lands-on-general.md), [S11](s11-backup-secrets-flow-can-request-a-nonexistent-pin.md) for Settings navigation and Security setup.
3. Make scratchpad AI generation explicit in [C05](c05-writing-notes-automatically-runs-ai.md), then align error handling in [C06](c06-note-taking-can-trigger-unrelated-ai-errors.md). Coordinate unsaved-work protection across [PW01](pw01-project-forms-discard-unsaved-work.md), [C01](c01-changing-tabs-destroys-a-document-draft.md) and [S05](s05-settings-sections-handle-drafts-differently.md) using the existing patterns.
4. Work through the remaining P2 findings, then P3. Related-plan links inside each file identify changes that share components.

## Working from a plan

- Confirm the current flow before editing. **Source** findings were traced in code; **Live + source** findings were also observed in the UI. Consequential source-only failure paths still need reproduction with disposable fixtures.
- Source line references are audit pointers and may move. Follow the named files and current callers.
- Read `AGENTS.md`, applicable `.ai/rules` and the relevant skills. Reuse the existing controls, navigation guards and test setup.
- Use each plan’s verification section for the smallest useful checks. Copy and layout changes need visual checks; behavior changes need meaningful regression coverage. Test restore, deletion and secret flows with disposable data.
- Update a plan’s status only after its acceptance criteria are met, recording checks run and any remaining limits.

## Plans by feature

### Workspace & project setup

| ID | Priority | Plan |
| --- | --- | --- |
| PW01 | P2 | [Project forms discard unsaved work](pw01-project-forms-discard-unsaved-work.md) |
| PW02 | P2 | [Create is available while cloning](pw02-create-is-available-while-cloning.md) |
| PW04 | P2 | [Full project descriptions are hidden](pw04-full-project-descriptions-are-hidden.md) |
| PW05 | P2 | [Search match count means this page only](pw05-search-match-count-means-this-page-only.md) |
| PW06 | P2 | [Project search can show stale results](pw06-project-search-can-show-stale-results.md) |
| PW07 | P2 | [Overview assumes fixed workflow names](pw07-overview-assumes-fixed-workflow-names.md) |
| PW09 | P2 | [Shortcut rows imply a larger click target](pw09-shortcut-rows-imply-a-larger-click-target.md) |
| PW10 | P2 | [Archive also commits every form edit](pw10-archive-also-commits-every-form-edit.md) |
| PW13 | P3 | [The same objects change names across screens](pw13-the-same-objects-change-names-across-screens.md) |
| PW14 | P3 | [Create and Edit use different form treatments](pw14-create-and-edit-use-different-form-treatments.md) |
| PW15 | P3 | [Link order changes when expanded](pw15-link-order-changes-when-expanded.md) |
| PW21 | P3 | [Global search omits visible match context](pw21-global-search-omits-visible-match-context.md) |
| PW22 | P3 | [Workspace search scope is unclear](pw22-workspace-search-scope-is-unclear.md) |
| PW23 | P3 | [Project card date has no meaning label](pw23-project-card-date-has-no-meaning-label.md) |
| PW25 | P3 | [Duplicate is hidden and its scope is undisclosed](pw25-duplicate-is-hidden-and-its-scope-is-undisclosed.md) |
| PW26 | P3 | [Archive may not achieve expected decluttering](pw26-archive-may-not-achieve-expected-decluttering.md) |

### Board, documents & scratchpad

| ID | Priority | Plan |
| --- | --- | --- |
| C01 | P1 | [Changing tabs destroys a document draft](c01-changing-tabs-destroys-a-document-draft.md) |
| C02 | P1 | [Board conflict recovery closes the draft](c02-board-conflict-recovery-closes-the-draft.md) |
| C05 | P2 | [Writing notes automatically runs AI](c05-writing-notes-automatically-runs-ai.md) |
| C06 | P2 | [Note-taking can trigger unrelated AI errors](c06-note-taking-can-trigger-unrelated-ai-errors.md) |
| C07 | P2 | [Autosave lacks persistent saved/failed status](c07-autosave-lacks-persistent-saved-failed-status.md) |
| C13 | P2 | [Board ordering has no keyboard equivalent](c13-board-ordering-has-no-keyboard-equivalent.md) |
| C14 | P2 | [Board search counts hidden cards](c14-board-search-counts-hidden-cards.md) |
| C15 | P3 | [Board vocabulary alternates mid-flow](c15-board-vocabulary-alternates-mid-flow.md) |
| C17 | P3 | [New card says Save changes](c17-new-card-says-save-changes.md) |
| C18 | P3 | [Empty document preview is blank](c18-empty-document-preview-is-blank.md) |

### Assets & secrets

| ID | Priority | Plan |
| --- | --- | --- |
| C03 | P1 | [Failed .env paste clears the input](c03-failed-env-paste-clears-the-input.md) |
| C04 | P1 | [Secret bulk actions include hidden selections](c04-secret-bulk-actions-include-hidden-selections.md) |
| C08 | P2 | [Secret export cannot recover its consumed preview](c08-secret-export-cannot-recover-its-consumed-preview.md) |
| C09 | P2 | [Secret overwrite identifies only a filename](c09-secret-overwrite-identifies-only-a-filename.md) |
| C10 | P2 | [Vault expiry can discard a value draft silently](c10-vault-expiry-can-discard-a-value-draft-silently.md) |
| C11 | P2 | [Folder parents are ambiguous or invalid](c11-folder-parents-are-ambiguous-or-invalid.md) |
| C12 | P2 | [Upload limits and folder-reading state are hidden](c12-upload-limits-and-folder-reading-state-are-hidden.md) |
| C16 | P3 | [Blank service has two labels](c16-blank-service-has-two-labels.md) |
| C19 | P3 | [Remove means different outcomes for assets](c19-remove-means-different-outcomes-for-assets.md) |

### Sources & dependencies

| ID | Priority | Plan |
| --- | --- | --- |
| PW03 | P2 | [Editing a remote URL disconnects its provider](pw03-editing-a-remote-url-disconnects-its-provider.md) |
| PW08 | P2 | [Connect provider is buried](pw08-connect-provider-is-buried.md) |
| PW11 | P2 | [Browser Relink uses a detached input](pw11-browser-relink-uses-a-detached-input.md) |
| PW12 | P2 | [Sources Refresh refreshes only local folders](pw12-sources-refresh-refreshes-only-local-folders.md) |
| PW16 | P3 | [Package location controls have several names](pw16-package-location-controls-have-several-names.md) |
| PW17 | P3 | [Basic location setup is dominated by overrides](pw17-basic-location-setup-is-dominated-by-overrides.md) |
| PW18 | P3 | [Dependency “target” is ambiguous](pw18-dependency-target-is-ambiguous.md) |
| PW19 | P3 | [View release destinations differ](pw19-view-release-destinations-differ.md) |
| PW20 | P3 | [Attention findings vanish during scanning](pw20-attention-findings-vanish-during-scanning.md) |
| PW24 | P3 | [Disabled Open folder gives no explanation](pw24-disabled-open-folder-gives-no-explanation.md) |

### Settings, connections & backups

| ID | Priority | Plan |
| --- | --- | --- |
| S01 | P1 | [Restore can leave the previous backup actionable](s01-restore-can-leave-the-previous-backup-actionable.md) |
| S02 | P1 | [Restored defaults can display stale values](s02-restored-defaults-can-display-stale-values.md) |
| S03 | P2 | [Restore wording understates removed credentials](s03-restore-wording-understates-removed-credentials.md) |
| S04 | P2 | [Restore failure leaves an unusable retry form](s04-restore-failure-leaves-an-unusable-retry-form.md) |
| S05 | P2 | [Settings sections handle drafts differently](s05-settings-sections-handle-drafts-differently.md) |
| S06 | P2 | [Invisible edits can disable update installation](s06-invisible-edits-can-disable-update-installation.md) |
| S07 | P2 | [Mid-save edits can be marked saved falsely](s07-mid-save-edits-can-be-marked-saved-falsely.md) |
| S08 | P2 | [Set PIN recovery link lands on General](s08-set-pin-recovery-link-lands-on-general.md) |
| S09 | P2 | [Validation is repeated or far from its field](s09-validation-is-repeated-or-far-from-its-field.md) |
| S10 | P2 | [Settings navigation has no URL or history](s10-settings-navigation-has-no-url-or-history.md) |
| S11 | P2 | [Backup secrets flow can request a nonexistent PIN](s11-backup-secrets-flow-can-request-a-nonexistent-pin.md) |
| S12 | P2 | [Connection “Current” overstates verification](s12-connection-current-overstates-verification.md) |
| S13 | P2 | [Renaming a connection requires its token](s13-renaming-a-connection-requires-its-token.md) |
| S14 | P2 | [AI removal differs from provider removal](s14-ai-removal-differs-from-provider-removal.md) |
| S15 | P2 | [Runtime results expose jargon without next steps](s15-runtime-results-expose-jargon-without-next-steps.md) |
| S16 | P3 | [Backup password rules appear only on failure](s16-backup-password-rules-appear-only-on-failure.md) |
| S17 | P3 | [AI providers use raw identifiers](s17-ai-providers-use-raw-identifiers.md) |
| S18 | P3 | [AI client configuration lacks the final setup step](s18-ai-client-configuration-lacks-the-final-setup-step.md) |
| S19 | P3 | [Security copy implies instant application](s19-security-copy-implies-instant-application.md) |
| S20 | P3 | [Opening a project can trigger a settings conflict](s20-opening-a-project-can-trigger-a-settings-conflict.md) |
