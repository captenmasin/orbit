# C10 — Vault expiry can discard a value draft silently

Priority: P2  
Area: assets  
Status: Implemented
Evidence: Source — ProjectSecrets.vue:143–170,505–515  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-208)

## Problem

The vault locks at its deadline and clears secret-value/paste drafts, closing their dialogs. This necessary clearing occurs without a visible deadline or advance notice, so users can lose an edit without understanding why. Feedback must improve while the existing security boundary stays intact.

## Implementation plan

1. In `resources/js/components/ProjectSecrets.vue`, display the existing `unlockedUntil` deadline in the unlocked header, using a readable Locks at time label.
2. Schedule one advance warning shortly before that deadline using the existing timer/cleanup pattern. Refresh or cancel warning timing whenever the session deadline changes, the vault locks or the component unmounts.
3. On expiry, show a non-sensitive explanation that the vault locked and unsaved secret-value/paste input was cleared. Distinguish expiry from the user's explicit Lock action.
4. Preserve `clearVault()` and late-response guards: clear plaintext on lock, Cancel and unmount; keep sensitive drafts only in transient unlocked memory. Never extend the unlock or persist a draft to avoid loss.
5. Extend `tests/project-secrets.test.mjs` with controlled time around warning, expiry, deadline refresh and delayed responses.

## Acceptance criteria

- [x] The unlocked screen shows when the vault will lock.
- [x] An advance warning reaches users editing or pasting values.
- [x] Expiry explains draft clearing without revealing entered content.
- [x] Lock still clears plaintext immediately and late responses cannot restore it.

## Verification

Use disposable values and a short configured lock duration. Open the editor and paste dialog in separate checks, wait through warning and expiry, then unlock and verify cleared input. Refresh focus/status before expiry to check rescheduling. Repeat manual Lock and a delayed value response to confirm the feedback change never keeps sensitive values alive longer.

After implementation, run `node --test tests/project-secrets.test.mjs` and `php artisan test --compact tests/Feature/SecretVaultTest.php`.

## Related plans

- [C03 — Failed .env paste clears the input](c03-failed-env-paste-clears-the-input.md)
- [C07 — Autosave lacks persistent saved/failed status](c07-autosave-lacks-persistent-saved-failed-status.md)

## Completion — 28 September 2026

The vault displays its locking time, warns one minute before expiry and explains plaintext draft clearing afterward. Lock boundaries remain enforced.

Checks: tests/project-secrets.test.mjs; tests/Feature/ProjectSecretTest.php; tests/Feature/SecretVaultTest.php; tests/Feature/EnvFileTest.php. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
