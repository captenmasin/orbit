# C01 — Changing tabs destroys a document draft

Priority: P1  
Area: content  
Status: Implemented
Evidence: Live + source — ShowProject.vue:80,390; ProjectDocuments.vue:21,26; Reka TabsRoot.vue:67  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-114)

## Problem

The live audit lost an unsaved document's title/body when changing tabs because Documents unmounts. Leaving the project is also unprotected. Retain drafts until saved or explicitly discarded.

## Implementation plan

1. In `resources/js/pages/ShowProject.vue`, keep Documents mounted but hidden when inactive, following Overview's treatment.
2. In `resources/js/components/ProjectDocuments.vue`, expose dirty/processing state and retain selection, editor, title/body and preview mode across tabs.
3. Reuse `resources/js/components/ProjectScratchpad.vue` guard/cleanup for controllable in-app departures. Offer Save document, Discard draft and Keep editing through shared dialogs. Resume after successful save/discard; protect refresh/close with beforeunload.
4. Inertia v3 cannot cancel native Back/Forward. Recover nonsecret title/body/editor state using project/document-scoped remembered state with a separate new-document key. Exclude secrets/attachments; clear on save/discard.
5. Reload `selectedProject` after history restoration before comparing recovered base revisions; cached history props may be stale. Preserve text with conflict guidance; require explicit create-new recovery for removed targets. Never silently overwrite or recreate documents.
6. Extend `tests/project-documents.test.mjs` and `tests/project-tab.test.mjs` for tab retention, navigation decisions, history recovery, revision/removed-target handling and cleanup.

## Acceptance criteria

- [x] Switching among Documents, Board and Overview preserves the complete document draft.
- [x] Controllable in-app departures offer save, discard and stay choices.
- [x] A failed save preserves the draft and does not navigate.
- [x] Saved or explicitly discarded documents do not prompt again.
- [x] Native history recovers drafts only for their project/document and exposes stale or removed targets safely.

## Verification

Exercise new/existing drafts through tabs, sidebar, breadcrumbs, refresh/close and native history. Reject saves and change/remove targets before recovery. Confirm text survives, conflicts require explicit recovery, save/discard clears remembered drafts, and project IDs stay isolated.

After implementation, run `node --test tests/project-documents.test.mjs tests/project-tab.test.mjs`.

## Related plans

- [C02 — Board conflict recovery closes the draft](c02-board-conflict-recovery-closes-the-draft.md)
- [PW01 — Project forms discard unsaved work](pw01-project-forms-discard-unsaved-work.md)
- [S05 — Settings sections handle drafts differently](s05-settings-sections-handle-drafts-differently.md)

## Completion — 28 September 2026

Document editors stay mounted across tabs; project/document-scoped nonsecret drafts survive history recovery and expose stale or removed targets. Departure offers save, discard or keep editing.

Checks: tests/project-documents.test.mjs; tests/Feature/ProjectDocumentTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
