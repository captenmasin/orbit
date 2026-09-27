# C12 — Upload limits and folder-reading state are hidden

Priority: P2  
Area: assets  
Status: Planned  
Evidence: Source — ProjectAssetController.php:23,59,74; ProjectAssets.vue:105,325–347,416,472  
Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-221)

## Problem

Assets accepts files only up to 10 MB and caps a project at 100 files and 100 folders, but upload controls do not disclose those limits. Recursive folder preparation disables controls while the interface still says Upload files, which can appear stalled before upload progress begins.

## Implementation plan

1. In `resources/js/components/ProjectAssets.vue`, add compact help beside Upload files stating 10 MB per file and the 100-file/100-folder project limits, including the empty state where users first upload.
2. During `readingDrop`, show Reading folder… in the action/status area; switch to the existing Uploading percentage only when the actual upload starts.
3. Clear preparation feedback on successful reading, cancellation/error and unmount. Keep the existing busy protection and error handling; do not claim an estimated percentage while recursively reading.
4. Check wording against authoritative limits in `app/Http/Controllers/ProjectAssetController.php`; keep server validation unchanged. Extend `tests/project-assets.test.mjs` only for the reading/upload/failure state transitions.

## Acceptance criteria

- [ ] File-size and project-capacity limits are visible before upload.
- [ ] Folder reading has an explicit accessible status.
- [ ] Upload percentage begins only once an upload is actually running.
- [ ] Reading/upload failures restore actionable controls with useful feedback.

## Verification

Check both empty and populated Assets views at narrow and wide widths. Drop a nested folder and confirm Reading folder… precedes upload progress. Simulate a directory-read failure, then retry a valid folder. Upload a file above 10 MB and test a project near capacity using disposable data; errors must remain clear and no false success state appears.

After implementation, run `node --test tests/project-assets.test.mjs`. No new test is needed for the helper copy itself.

## Related plans

None.
