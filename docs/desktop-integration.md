# Milestone 0 — integration record

The current verification results are below. The later sections preserve the initial 0.1.0 spike on 14 September 2026, including the MCP clients and desktop diagnostic tools that were subsequently removed. The second macOS profile check is still open.

## Provider exit checks — 19 September 2026

- In the signed 0.5.1 app, created Provider verification with public `laravel/framework`. Initial reads loaded 12 issues, 30 pull requests, nine Actions runs and three commit statuses. Load more reached 29 issues and 40 unique pull requests; the selected tab and results survived Command-R.
- Disconnected the public association in Orbit. Its snapshots and remote commit date cleared; repository name/URL, project revision and four board columns remained. The original Novogamer entry retained its five snapshots and association. Reconnecting through the working account loaded all resources again.
- The user created a disposable read-only token and saved it directly in Orbit as Revocation test. A second verification repository loaded private Novogamer data successfully. The user revoked only that token at GitHub. Orbit then reported Token required, disabled refresh, and preserved all cached payload hashes and success/check timestamps. The test connection’s access-event count stayed at eight over multiple polling intervals, while the original connection remained healthy. This covers two independent credentials for one account, not two distinct accounts.
- DevTools Offline emulation for the Orbit renderer retained cached data and disabled refresh controls. Restoring No throttling re-enabled the working connection while the revoked connection stayed disabled. Developer tools were closed afterward. This verifies frontend offline behavior; PHP’s outbound network and an entirely offline app restart were not tested by renderer emulation.
- Added a short offline notice and a runnable regression covering request suppression, unchanged cached data and refresh on reconnect. All six JavaScript tests and production type checking/bundling pass. The signed 0.5.2 package displayed the offline notice and retained the revoked connection’s blocked state across upgrade/restart. Restored No throttling, closed developer tools, and left the app on the original Novogamer Repositories tab. App signature and DMG verification passed; SQLite integrity was ok with no foreign-key errors. The revoked connection remained at eight credential-access events. Notarization is still unavailable without Apple credentials. Normal pre-build quit left no Orbit processes this time.

Final acceptance: the user deferred GitLab and a second distinct account, then reported that a full offline restart passed: cached activity, the offline notice and disabled refresh controls all worked after reopening Orbit with the Mac disconnected. This is user-reported evidence; the earlier renderer emulation was agent-observed. After reconnection the agent observed all five Novogamer resources Current with refresh enabled. Final regressions passed: 94 PHP tests (1,008 assertions) and six JavaScript tests. Milestone 4 is complete for this agreed GitHub scope. Rate-limit failures use deterministic HTTP fixtures. Provider verification and its revoked test connection remain available as evidence; the original token was never revoked or replaced.

## GitHub Actions correction — 0.5.1, 19 September 2026

- Replaced GitHub Checks reads with Actions workflow runs after the user confirmed Checks was unavailable in fine-grained token settings and enabled Actions read permission. The panel now says GitHub Actions; guidance and permission errors name Actions read access. GitLab pipelines are unchanged.
- Built and launched signed 0.5.1 against the existing packaged workspace. Saved credentials and associations remained usable. Manual refresh at `15:02:10 UTC` loaded one workflow run for `master` / `e6bb2436`, with GitHub’s reported conclusion `failure`. The panel became Current and the access error cleared; other resources remained Current.
- All 23 provider tests passed (157 assertions) and all five JavaScript tests passed. Coverage includes SHA/branch matching, workflow conclusions and running states, pagination/deduplication, old Checks ETag invalidation, and retained data on permission failure. Pint, Vue type checking, production bundling, app signature verification and DMG verification passed. Notarization remains unavailable without Apple ID credentials.
- During the pre-build quit, an inspection worker remained orphaned after the app exited. Terminated that identified worker before launch; retain this as a NativePHP lifecycle follow-up rather than claiming quit cleanup is universally resolved.

## Provider activity package — 0.5.0, 19 September 2026

The provider implementation is packaged; private GitHub access and credential/cache persistence across restart passed for `captenmasin/novogamer`. No GitLab test account was supplied. Remaining live failure-case checks are open; fixture coverage is not live provider verification.

- Built the signed Apple Silicon 0.5.0 app, then rebuilt after fixing transient token-form validation/default retention. Both production bundling and Vue type checking passed. `codesign --verify --deep --strict` and `hdiutil verify` passed for the final app/DMG. Notarization did not complete because Apple ID credentials are not configured.
- Launched the signed package against its existing app-data directory. The additive provider migration ran successfully. Existing project data was unchanged. Backed up and migrated the native and web development databases too; original catalog/board rows in all three databases matched the backups, with SQLite integrity `ok` and no foreign-key errors.
- Exercised the packaged `ProtectCredential` operation through the authenticated native bridge using a dummy value. Encryption/decryption succeeded; after a normal Command-Q/relaunch the same stored ciphertext still decrypted successfully. No diagnostic UI or plaintext fallback was added.
- In the final packaged Connections dialog, entered an intentionally invalid dummy GitHub token. The real GitHub request returned `Token required`; the dialog remained open and its password field was empty. No connection was saved. This also checked packaged HTTPS/API access without sending a real credential.
- The user entered and saved their real token directly in Orbit. Created Novogamer in the packaged workspace and associated its remote with the saved account. Default branch `master`, tip `e6bb2436`, and committer date `2026-08-29 20:09:43 UTC` appeared in both repository activity and the project summary. Issues/pull requests loaded empty lists, and commit statuses showed no results for that SHA. Checks alone showed Access unavailable, with the other panels and connection remaining Current.
- Normal Command-Q/relaunch retained the connection, association, every snapshot payload hash and original success timestamp. A manual issues refresh then succeeded with the saved credential; checked time advanced from `14:42:45` to `14:43:49 UTC`, while the original success time and payload stayed unchanged. SQLite integrity was `ok`, no foreign-key errors were reported, and the final app signature still verified. The package remains open on Novogamer’s Repositories tab.
- Populated lists/pagination, multiple accounts, offline mode, actual revocation/rate limiting, and clean disconnect have automated coverage but were not exercised live. No token was revoked or broader permissions requested. GitLab remains fixture-verified only.
- All 93 PHP tests passed (995 assertions), and all five JavaScript regressions passed. The new frontend regression covers validation failures, successful submissions, unmount clearing, and removal of tokens from the HTTP helper’s saved defaults. The existing dirty-form regression now includes provider updates.
- A temporary ciphertext-only native restart fixture remains at `/tmp/orbit-provider-ciphercheck`; automatic command review blocked its optional deletion. It contains no real account token. Database backups are under the temporary `orbit-before-providers-ghzw603v` directory.

See [the provider milestone](PLAN-04-provider-activity.md) for endpoint permissions, bounded refresh behavior, and remaining live checks.

## Local inspection package — 0.4.0, 19 September 2026

Verified the signed Apple Silicon package on the current macOS profile. Milestone 3 is complete; a real second macOS profile remains untested. Notarization was skipped/failed because the Apple ID credentials are not configured; this is an internal signed preview, not a notarized release.

- Built with `php artisan native:build mac arm64 --no-interaction`. The first build inherited `public/hot` from a running Vite session and showed a blank window when Vite stopped. Added `public/hot` to NativePHP’s cleanup exclusions and rebuilt. Confirmed the marker is absent and the rebuilt package opens with Vite stopped.
- Upgraded the existing packaged workspace from 0.1.1, including catalog, boards, attachments, and inspection migrations. Original project fields were unchanged. Development and packaged SQLite backups were taken before the checks.
- Created a temporary project through the packaged UI with two checkouts and a nested `packages/web` root. Composer showed Laravel required `^13` and locked `v13.31.0`; npm showed Vue locked at both `3.5.42` and nested `3.4.0`, with separate locations and scopes. Changing the manifest outside Orbit updated required `^3.5` to `^3.6` without changing locked values. Changing the checkout branch outside Orbit updated the visible source to `updated`; the second checkout retained `preview`. The commit readout used the distinct committer date, not the author date.
- Native root selection worked after normal GUI launch. Both the CLI-launched package and normal GUI-launched package resolved external PHP 8.5.8, Node 22.22.2, Composer 2.10.2, npm 10.9.7 and pnpm 10.12.1. Yarn was correctly marked missing. Detected paths pointed to Herd/Homebrew installations, not bundled Orbit tools.
- Saved two controlled slow executable overrides, started a scan, confirmed `Scanning` in SQLite, then used Command-Q. Electron, PHP, the queue worker and probe children exited without orphans. Relaunch preserved the root, overrides and previous results; the abandoned job became `Stale / Inspection interrupted` after the queue visibility budget. Manual retry completed with bounded `Timed out` probe states.
- Moved the nested root away. Refresh showed `Missing folder. Previous results are retained.` Restoring the root and clearing the test overrides returned it to Current with detected host runtimes. The UI remained usable while targets were queued and scanned.
- All 71 PHP tests passed (851 assertions); all four JavaScript regressions passed. Vue type checking, production bundling and Pint passed. Temporary test storage is outside the source repository so Git cannot accidentally discover Orbit’s containing checkout in non-Git fixtures.
- Removed only the temporary verification project and its interrupted-job record. All original catalog/board records in the packaged and native development databases matched their backups. SQLite integrity returned `ok` and foreign-key checks were empty.
- `codesign --verify --deep --strict` passed after the final clean quit. `hdiutil verify` passed for `Orbit-0.4.0-arm64.dmg`.

One initial prolonged native-picker interaction ended with a generic inspection error and the PHP server no longer running; no diagnostic crash report was found. Retrying with captured process output succeeded, as did a subsequent normal GUI launch, picker selection, background scans, and clean quit. This observation is not considered a fixed defect: retain it for reproduction if native picker failures recur.

## Board development app — 0.3.0, 15 September 2026

- Backed up both development databases and applied the board migration. Existing projects received the four default columns without changing their catalog fields.
- In the desktop app, created a temporary project and two tasks, entered a description, reordered tasks through keyboard controls, added and renamed a column, and reordered columns with arrow buttons. A catalog save after a board change succeeded without a false revision conflict.
- In a separate browser view of the same NativePHP server, dragged a task between columns and reordered columns by dragging their handles. Native UI automation did not successfully initiate HTML drag gestures; these gestures were verified through browser automation.
- Submitted an edit from the stale desktop view after a change in the browser. The dialog kept its unsaved text and showed a revision conflict. Reloading the board displayed the newer task placement.
- Quit with Command-Q and relaunched through `composer dev`. All five columns, two tasks, descriptions, and positions matched the pre-restart snapshot and appeared in the desktop app.
- Checked the board and keyboard move dialog at the minimum 600 × 600 window size, then restored the window.
- All 40 application tests passed (524 assertions), including column deletion with task reassignment, project isolation, stale saves/removals, invalid positions, and rollback after a simulated failure partway through a move. Vue type checking and the production build passed.
- Removed only the temporary project and its board. The original project, repositories, folders, links, tags, and tag associations matched the backup. SQLite integrity and foreign-key checks passed.

These checks used the development app; a 0.3.0 package has not been built or signed.

## Catalog development app — 0.2.0, 15 September 2026

- Applied the catalog migration to the development databases after backing up the native database. The original project's existing fields were unchanged.
- Selected a temporary Git folder through the native picker and reviewed its manifest name/description, remote, branch, and HEAD committer date before saving. Added a second repository, a non-Git folder, tags, an emoji icon, and a categorized link.
- Quit with Command-Q and relaunched through `composer dev`. The two repositories, two folders, tags, icon and link persisted. Search by tag found the project.
- Moved the temporary non-Git folder outside Orbit. The app displayed “Missing folder”; the native relink flow restored availability and preserved the other project data and folder identity.
- Uploaded a raster icon through the real file picker, saved it through Inertia, and verified it after reload. Archive and restore preserved the project. The catalog and editor remained usable at the minimum 600 × 600 window size.
- All 22 application tests passed (311 assertions), including stale edits/removals, atomic catalog persistence, URL and image validation, ownership constraints, ordering, and preservation of source files. Vue type checking and the production build passed.
- Removed only the disposable project, its unused tags, and its uploaded icon. Original project fields still matched the backup. SQLite integrity and foreign-key checks passed; the temporary source files remained intact.

These checks used the desktop development app. A new 0.2.0 package has not been built or signed; the 0.1.1 package evidence below remains separate.

## Current package — 0.1.1, 15 September 2026

Verified the signed Apple Silicon package on macOS 26.7, using the current Inertia/Vue interface and NativePHP Desktop 2.3.1 with Electron 40.10.2. This is an internal preview; notarization did not run because Apple credentials are not configured.

- `php artisan native:build mac arm64 --no-interaction` completed, including the Vue type check and production assets. The package works with Vite stopped and no `public/hot` file.
- Upgrading the existing 0.1.0 workspace ran the pending migration to remove the old preview tables. Every field of the existing project matched the pre-upgrade SQLite backup.
- Created and edited a temporary project in the packaged UI. After Command-Q and relaunch, its name, description, Active status and revision 2 persisted.
- Command-Q and the native Quit menu now exit both Electron and the PHP server. Closing the window and reactivating the app recreates the project screen. No forced termination was needed after the fix.
- Repeated first launch with an empty app-data directory. All six migrations ran, the empty workspace appeared, and a new project survived a normal quit and relaunch. SQLite integrity checks returned `ok`.
- Restored the original app-data directory and removed only the temporary verification project. Compared all original project fields with the pre-upgrade backup again; they were unchanged.
- `codesign --verify --deep --strict` passed before and after launches and clean quits. `hdiutil verify` passed for `Orbit-0.1.1-arm64.dmg`. Runtime cache directories remained outside the bundle with mode 0700.
- Launch-to-visible-window observations took roughly 2–5 seconds in the computer-use tool; these include tool overhead and are not a benchmark. The earlier 30-second launch delay was not reproduced.
- All eight application tests passed (130 assertions). The new `npm test` regression failed against the original quit handler and passed with the fix.

### Quit fix

NativePHP 2.3.1 cancels the first `before-quit` event, cleans up processes, and calls `app.quit()` again. With no child jobs to wait for, that retry happens inside the original cancelled event. Electron closes the windows but leaves the app running without PHP.

`bootstrap/patch-nativephp.php` changes the final retry to `setImmediate(() => app.quit())`, letting the original event finish first. Composer's post-autoload hook and the NativePHP prebuild hook apply this to both the source and compiled plugin. The patch is idempotent and rejects an unexpected handler. Existing cleanup and the second-event guard remain in place. Remove this compatibility patch when adopting an upstream fix.

That package used version 0.1.1 because NativePHP gates upgrade migrations on a version change. [NativePHP versioning](https://nativephp.com/docs/desktop/2/publishing/building), [Electron quit lifecycle](https://www.electronjs.org/docs/latest/api/app).

### Remaining desktop check

A real second macOS user profile has **not** been tested. This machine has only the `mason` login. An empty app-data directory tests first-run setup but does not establish behavior under a different account, home directory, or Keychain. Run the packaged app under a second macOS login, create a project, quit and reopen it, and verify that the project remains. Milestone 0 stays open until that check passes.

## Original runtime choices

- Laravel 13.31.0, NativePHP Desktop 2.3.1, Laravel MCP 1.0.0, nativephp/php-bin 1.2.0, pinned in Composer's manifest and lockfile.
- Host: Apple Silicon, macOS 26.7, PHP 8.5.8, Node 22.22.2. Electron resolved to 40.10.2 from NativePHP's shipped lockfile. Only ARM64 is being exercised.
- Plain Blade forms and CSS for the spike. Livewire and board interaction can be added when the board needs them.
- NativePHP owns persistent SQLite paths and migrations. No custom database locator or background daemon.
- Local Laravel MCP stdio registration; token supplied in environment; hashed approval records; per-request revocation checks. No unauthenticated MCP registration, HTTP server, or source-file operations.
- Shared SaveProject operation validates both UI and MCP writes. Updates compare the expected revision in the database write and increment it atomically; a stale edit returns conflict.
- Encryption uses NativePHP's existing System::canEncrypt/encrypt/decrypt facade. Inspection of the installed Electron code confirms safeStorage.encryptString/decryptString behind NativePHP's authenticated loopback bridge. No custom bridge or distributed encryption key is used for the dummy value. This is not yet a real secret vault.

## Original build evidence

- Native development app launched and displayed the workspace.
- Eight PHP tests passed (50 assertions), including cache location and launch isolation.
- Real stdio smoke process passed initialization, tool discovery, creation, listing, updating, stale-update rejection, and revocation on the same live connection. It used an isolated temporary SQLite database.
- The ARM64 package built successfully, launched, and passed the real native dummy-encryption check. A dummy project created through its UI survived cold relaunch. The same ciphertext was successfully decrypted on subsequent launches, with its SHA-256 digest unchanged.
- After the cache fix, `codesign --verify --deep --strict` passed after app launch and after the bundled MCP smoke check. Cache directory permissions were verified as 0700. Normal Quit remains unresolved below.
- The stdio smoke test also passed using the app bundle’s PHP 8.5.6 and bundled Laravel application, against a disposable database.
- Native encryption failure tests preserve the existing ciphertext. Those mocked tests alone do not establish macOS Keychain behavior.

## Packaging issue found and fixed

NativePHP 2.3.1 redirects Laravel cache paths only for its secure-bundle mode. A standard packaged build ran `artisan optimize` inside the signed app directory, adding config/events/routes caches and invalidating the code signature. Orbit now redirects caches into its application data directory before Laravel boots, restricts that directory to the current user, and uses a distinct config cache for each native bridge credential. This also prevents a later launch from loading the previous bridge credential. The tiny per-launch config files currently accumulate; cache pruning is deferred. A regression test checks the path and isolation.

NativePHP automatically selected the existing Developer ID for the local build. Notarization did not occur: its hook reported missing Apple credentials even though the build proceeded. Do not interpret the hook’s subsequent “done notarizing” message as success.

## Open checks and next work

- Normal Quit and window reopening were fixed and verified in 0.1.1; see the current results above. Repeat them under a second macOS profile before marking milestone 0 complete.
- Evaluate portable backups using established libsodium password derivation and authenticated encryption; select a documented format and test cross-profile recovery before implementing restore. No custom cryptography has been implemented.
- Resolve inherited npm advisories before public distribution. On this install, `npm audit --omit=dev` in NativePHP's Electron directory reported 12 affected packages (10 high, one moderate, one low). These include Electron 40.10.2, axios and electron-updater. The full development tree reports 34 findings including one critical. No forced dependency upgrade or vendor patch was applied; the selected NativePHP dependency set needs a compatible update and another packaged check. Composer reported no advisories.
- Add notarization, updater configuration and Intel support only with actual signing/update and architecture validation. The current build is an internal preview.

## References

[NativePHP installation](https://nativephp.com/docs/desktop/2/getting-started/installation), [SQLite lifecycle](https://nativephp.com/docs/desktop/2/digging-deeper/databases), [native bridge security](https://nativephp.com/docs/desktop/2/digging-deeper/security), [packaging](https://nativephp.com/docs/desktop/2/publishing/building), [Laravel MCP](https://laravel.com/framework/docs/13.x/mcp).
