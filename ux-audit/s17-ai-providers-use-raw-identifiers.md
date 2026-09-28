# S17 — AI providers use raw identifiers

Priority: P3

Area: settings

Status: Implemented

Evidence: Source — ScratchpadAiSettings.vue:49–54; ScratchpadAi.php:13

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-415)

## Problem

Setup displays openai / anthropic / gemini and asks for Model ID, unlike branded Git provider labels. Familiar provider branding and a clearly presented default model can make setup understandable without changing the stored configuration.

## Implementation plan

1. In `resources/js/components/ScratchpadAiSettings.vue`, map existing provider values to display labels OpenAI, Anthropic and Google Gemini using the options format already supported by `resources/js/components/ChoiceSelect.vue`.
2. Use the same display labels in the connected-status sentence. Preserve raw provider values sent to `app/Http/Controllers/ScratchpadAiController.php` and the allowlist in `app/ScratchpadAi.php`.
3. Explain that the prefilled model is the provider's default, and that a custom value must be an exact model identifier offered by that provider. Preserve a configured custom model when initially opening the form.
4. Provide one short guidance link per provider only after checking its current official model documentation. Keep this helper alongside the existing model field rather than adding a model catalogue or new request.
5. Retain the existing provider-change behavior that fills its default model, verification request and key-clearing boundaries.

## Acceptance criteria

- [x] Provider labels consistently use recognizable branding.
- [x] Display changes do not alter stored or submitted provider identifiers.
- [x] The default model and requirements for a custom identifier are clear.
- [x] Existing configured models and verification behavior are preserved.

## Verification

Inspect initial setup, switch among all three providers and open replacement for a configured custom model. Confirm the status label, selected provider and default/custom model remain correct. Verify helper links against official documentation before shipping them.

No new automated tests are required for wording/label changes. Run `node --test tests/scratchpad-ai-settings.test.mjs` if option rendering affects the existing provider selection behavior.

## Related plans

None.


## Completion — 28 September 2026

AI provider labels use OpenAI, Anthropic and Google Gemini while preserving submitted identifiers/custom models; exact-model documentation links are supplied.

Checks: tests/scratchpad-ai-settings.test.mjs; tests/Feature/ScratchpadAiSettingsTest.php; branding/template review. These checks passed in the full suites; production build and lint of changed Vue files also passed.
