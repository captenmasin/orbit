# C18 — Empty document preview is blank

Priority: P3  
Area: content  
Status: Planned  
Evidence: Source — ProjectDocuments.vue:99; ProjectBoard.vue:411  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-171)

## Problem

Documents can render a completely blank successful Markdown preview, while Board says Nothing to preview for the equivalent state. Without explanation, users cannot tell an empty body from failed rendering or unfinished loading. The Documents preview should provide the same clear empty-state feedback.

## Implementation plan

1. In `resources/js/components/ProjectDocuments.vue`, retain the existing loading and body-validation-error branches before checking preview output.
2. Render MarkdownContent only when the successful preview contains content; otherwise show the existing Board wording, Nothing to preview, using the same muted text treatment from `resources/js/components/ProjectBoard.vue`.
3. Keep the Write/Preview toggle and draft content intact. Do not add client Markdown parsing or change `app/Http/Controllers/ProjectDocumentController.php`'s renderer contract.
4. Check both a genuinely empty body and input that renders no visible content, then compare a normal document with headings/code to ensure heading navigation and copy controls still appear correctly.

## Acceptance criteria

- [ ] Successful empty previews show Nothing to preview.
- [ ] Loading and preview errors remain distinguishable from an empty result.
- [ ] Returning to Write preserves the unchanged body draft.
- [ ] Nonempty previews retain Markdown formatting and navigation.

## Verification

Create a document with an empty body, open Preview and confirm the message after loading completes. Repeat with whitespace input. Add headings and a code block and verify their rendered content, heading links and Copy code control. Simulate a preview error and ensure the error is shown instead of the empty message; switch back to Write and confirm the text remains.

No new automated tests are needed for this presentation-only empty-state change; the existing Markdown renderer and save behavior are unchanged.

## Related plans

- [C01 — Changing tabs destroys a document draft](c01-changing-tabs-destroys-a-document-draft.md)
