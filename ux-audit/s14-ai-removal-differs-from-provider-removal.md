# S14 — AI removal differs from provider removal

Priority: P2

Area: settings

Status: Implemented

Evidence: Source — ScratchpadAiSettings.vue:31–51; ProviderConnections.vue:87

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-396)

## Problem

AI Remove immediately discards its saved credential, while provider removal has a confirmation dialog. Both actions discard saved credentials, so they should offer the same explicit destructive decision while describing their different effects.

## Implementation plan

1. In `resources/js/components/ScratchpadAiSettings.vue`, open a confirmation dialog from Remove using the shared Dialog components and the existing provider-removal layout in `resources/js/components/ProviderConnections.vue`.
2. State that removal disables AI-generated suggestions until reconnection. Notes and explicit bare-URL conversion to links remain available without AI. Identify the configured provider/model without displaying its key.
3. Keep Cancel as a safe dismissal and Remove AI connection as the destructive action. Disable duplicate submission while the existing removal request is processing.
4. Call the existing `remove` flow only after confirmation. Keep the dialog recoverable on failure, and close it only after a confirmed success; maintain current key cleanup and revision checks.
5. Extend `tests/scratchpad-ai-settings.test.mjs` for cancel, confirmed removal, rejected removal and processing state without changing credential storage.

## Acceptance criteria

- [x] Opening Remove never deletes the credential immediately.
- [x] Confirmation distinguishes AI suggestions from retained notes and non-AI URL conversion.
- [x] Cancel performs no removal request.
- [x] Failed removal preserves the working connection and permits recovery.

## Verification

Open/cancel removal, then confirm it in a disposable configuration. Repeat with network/revision failure and keyboard interaction. Verify notes remain, AI suggestions require setup, and explicit bare-URL conversion still produces links without AI.

Run `node --test tests/scratchpad-ai-settings.test.mjs`. The existing removal endpoint and native-only authorization remain unchanged.

## Related plans

None.

## Completion — 28 September 2026

AI credential removal requires confirmation, explains retained notes/non-AI conversion and preserves the dialog on failure.

Checks: tests/scratchpad-ai-settings.test.mjs; tests/Feature/ScratchpadAiSettingsTest.php; branding/template review. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
