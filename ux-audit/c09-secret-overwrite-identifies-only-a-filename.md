# C09 — Secret overwrite identifies only a filename

Priority: P2  
Area: assets  
Status: Planned  
Evidence: Source — SecretController.php:185; ProjectSecrets.vue:648  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-202)

## Problem

The final export preview returns only basename, commonly .env. At the overwrite decision, identical filenames from different directories are indistinguishable. The user needs the selected path to verify which existing file will be replaced before granting overwrite consent.

## Implementation plan

1. In `app/Http/Controllers/SecretController.php`, return the selected export destination path, or an unambiguous directory plus filename, in the existing preview response. Keep the server's staged path and file-state checks authoritative.
2. Update `resources/js/components/ProjectSecrets.vue` to render that destination in the confirmation with wrapping for long paths, adjacent to the existing-file notice and replacement checkbox.
3. Preserve the plaintext-export explanation and explicit overwrite requirement. Do not expose file contents or add a separate path-selection mechanism.
4. Update relevant response expectations in `tests/Feature/ProjectSecretTest.php` and export-preview fixtures in `tests/project-secrets.test.mjs`, covering two equal filenames under different directories.

## Acceptance criteria

- [ ] Final confirmation identifies the destination directory and filename.
- [ ] Two selected paths ending in .env remain distinguishable.
- [ ] Long paths remain readable without pushing confirmation controls off screen.
- [ ] Existing-file replacement still requires explicit consent and server validation.

## Verification

In the desktop app, preview exports to two different test folders containing .env and confirm each exact destination is visible. Check a long path at a narrow window width. Verify unconfirmed overwrite is blocked and that changing the existing file after preview still rejects export; the clearer label must not weaken destination integrity checks.

After implementation, run `node --test tests/project-secrets.test.mjs` and `php artisan test --compact tests/Feature/ProjectSecretTest.php`.

## Related plans

- [C08 — Secret export cannot recover its consumed preview](c08-secret-export-cannot-recover-its-consumed-preview.md)
