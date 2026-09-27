# C03 — Failed .env paste clears the input

Priority: P1  
Area: assets  
Status: Planned  
Evidence: Source — ProjectSecrets.vue:343–356  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-183)

## Problem

The .env paste handler clears `pasteForm.entries` in finally regardless of the result. A duplicate name, invalid entry, network failure or credential-storage failure leaves the dialog open but removes the source text needed for correction and retry. Sensitive input must remain recoverable only while the vault is unlocked.

## Implementation plan

1. In `resources/js/components/ProjectSecrets.vue`, move paste-input clearing out of unconditional failure cleanup. Keep the text and selected environment/service when a submission is rejected.
2. Continue using `closePaste()` on successful save and Cancel, and preserve `clearVault()` plus unmount cleanup as unconditional sensitive-data clearing boundaries.
3. Ensure an in-flight response cannot repopulate entries after lock, dialog dismissal or unmount. Keep plaintext only in transient unlocked component memory; do not use remembered forms, browser storage, persistent drafts or logs.
4. Keep field errors visible so users can fix the preserved entries. Extend `tests/project-secrets.test.mjs` to submit nonempty text under validation, duplicate, network and storage failures, then exercise retry and clearing boundaries.

## Acceptance criteria

- [ ] Rejected paste submissions preserve their original text while unlocked.
- [ ] Correcting the entries and retrying creates the intended secrets once.
- [ ] Successful save, Cancel, lock and unmount clear plaintext input.
- [ ] Late responses after lock cannot restore sensitive text.

## Verification

Use disposable test credentials. Paste a malformed entry and a name already present in the chosen environment; verify errors preserve editable source. Simulate a network/storage failure, retry successfully, then verify Cancel and automatic/manual lock clear input. Never copy real secret values into test fixtures or audit artifacts.

After implementation, run `node --test tests/project-secrets.test.mjs` and `php artisan test --compact tests/Feature/ProjectSecretTest.php`.

## Related plans

- [C10 — Vault expiry can discard a value draft silently](c10-vault-expiry-can-discard-a-value-draft-silently.md)
