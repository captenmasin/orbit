# Milestone 7 — Public beta

Status: planned. Written 15 September 2026; renumbered 22 September 2026. Depends on completed milestones 1–6 and closure of the remaining milestone 0 runtime checks. This plan does not publish or upload a release.

Parent: [Product plan](../PLAN.md). Previous: [Project knowledge and migration](PLAN-06-project-knowledge.md).

## Outcome

Distribute a signed, notarized macOS beta that installs on the advertised systems, upgrades an existing workspace without losing data or secret access, and receives authenticated updates through NativePHP's updater. Installation, recovery, limitations, and support instructions are accurate and usable by someone who has never run the development checkout.

The exit demonstration starts with a downloaded release on a clean supported Mac/account, creates representative data, installs an update, and proves catalog, documents, content search, board, provider connections, and grouped secrets still work after normal Quit/relaunch. A separate backup restores on a different account.

## Baseline to recheck

The integration record currently documents a signed ARM64 0.1.1 package, with later catalog work in the 0.2.0 development source. The main implementation may have advanced since this plan was written; inspect the actual version, source, lockfiles, tests, and artifacts before choosing release numbers.

Existing useful work includes native SQLite persistence, cache writes outside the signed bundle, isolated launch credentials, and a compatibility patch for NativePHP 2.3.1's reentrant quit behavior. Keep these protections unless a verified upstream fix replaces them. The current integration record does not establish second-account behavior, notarization, Intel compatibility, or an operational update feed.

Historical Electron/npm advisory counts in the integration record are a prompt for a fresh dependency audit, not a statement about the current candidate. Do not ship based on an old audit or mark an advisory fixed merely because a lockfile changed.

## Release scope and decisions

| Area | Default and decision needed |
| --- | --- |
| OS | macOS only; choose and publish a minimum supported version from actual NativePHP/Electron requirements and tests |
| CPU | ARM64 first; include Intel only after a real x64 package and runtime validation |
| Distribution | Downloadable signed/notarized app/DMG through a chosen release destination |
| Updates | NativePHP's existing updater; select one supported provider and one beta channel |
| Pricing | Free beta remains provisional; do not add billing, licensing, or entitlements without a product decision |
| Provider scope | Document GitHub.com/GitLab.com and manual tokens if milestone 4 retains those defaults |
| Support | Choose a support destination, release owner/contact, and issue-reporting route before publication |

Record the actual publisher name, app identifier, release destination, channel naming, website, support contact, and supported matrix. Do not invent account names or publish links to placeholders. Preserve app identity across upgrades because storage and native encryption depend on application/account context.

## Workstream 1 — Freeze and validate the candidate

1. Confirm each earlier milestone's exit evidence, especially board ordering, interrupted scan recovery, provider failure handling, and cross-account backup restore. Resolve omissions before describing features as available.
2. Inventory application and bundled-runtime dependencies using current lockfiles and installed versions. Check Composer, the frontend dependency tree, and NativePHP's actual Electron application dependency tree; root `npm audit` alone is insufficient.
3. Resolve exploitable high/critical runtime findings before public distribution. Assess remaining findings for reachability, affected artifact, remediation, and release impact. Prefer compatible upstream upgrades; avoid blind forced updates or broad vendor edits.
4. Re-test the exact bundled Electron/PHP/runtime combination after changes. If an upstream release fixes the quit issue, prove the original regression against that release before removing the patch and its hook.
5. Run the existing application suite, focused new failure tests, native quit regression, PHP formatting, and production build. Record commands, versions, and results for the final source state.
6. Freeze the candidate input in a reproducible source snapshot: a commit/tag if the project has a repository by then, otherwise a checksummed source archive and lockfiles. This plan does not assume the current directory is a Git repository.

Keep release work focused on failures and completion criteria. Do not redesign the app, add deferred providers, expand lockfile support, or introduce a new updater as part of release preparation.

## Workstream 2 — Production boundary and package contents

Review the actual packaged application, not just configuration declarations:

- Ensure development mode, debug overlays, request/body logging, test fixtures, test credentials, development routes, and diagnostic UI are absent or disabled.
- Ensure Vite is stopped, `public/hot` is absent from the release, and frontend/font assets load locally. The UI remains default shadcn-vue with sidebar-07 and no theme overrides or filler copy.
- Inspect bundled environment data and files for credentials, user databases, icons, provider caches, logs, sessions, backup files, and private absolute paths. No developer workspace data may enter the archive.
- Confirm SQLite, icons, logs, caches, and temporary files are written outside the signed application bundle with appropriate private permissions. Add bounded cleanup for the existing per-launch cache files without deleting files used by a live process.
- Keep NativePHP's authenticated native bridge and normal browser-access protections enabled. Retain Laravel CSRF/origin protections and validate all native-opening targets.
- Confirm Electron context isolation and the intended sandbox/renderer configuration. Do not load provider HTML or navigate the app window to untrusted origins; external destinations use the existing validated opening path.
- Verify secrets and tokens remain absent from page props, errors, history, diagnostics, queue payloads, exports that omit them, and process arguments.

NativePHP documents per-launch bridge authentication and packaged browser-access protection; Electron's security guidance covers renderer isolation and untrusted navigation. Verify the installed implementation against those requirements. [NativePHP security](https://nativephp.com/docs/desktop/2/digging-deeper/security), [Electron security](https://www.electronjs.org/docs/latest/tutorial/security).

Treat this as a concrete review of reachable product boundaries, not a new generic security subsystem. Record real residual limitations in release notes or the support instructions where users need them.

## Workstream 3 — Build, sign, and notarize

Stop the development application before packaging because this project currently shares NativePHP's Electron output directory between development and build operations. Use the documented NativePHP build path and existing prebuild hooks; do not replace it with a parallel packaging system.

1. Set a new application version for the candidate, keeping environment/config defaults consistent. NativePHP uses version changes to trigger upgrade migrations; every migration-bearing release needs a changed version. [NativePHP building/versioning](https://nativephp.com/docs/desktop/2/publishing/building).
2. Supply Developer ID and notarization credentials through the approved local/CI secret mechanism. Keep them out of committed files and produced artifacts. Verify the signing identity matches the intended publisher.
3. Build the selected architecture(s). Test the produced bundle with no development server or project checkout dependency.
4. Verify code signatures and hardened-runtime/signing configuration for the app and nested runtime components. Recheck signature integrity after launch and normal Quit; runtime caches must not invalidate the bundle.
5. Submit the actual distribution artifact for notarization. Wait for accepted status, inspect failure logs if rejected, staple the appropriate ticket, and verify it. A build log saying a notarization hook completed is not sufficient evidence.
6. Validate the DMG/container and retain artifact hashes, signature identity, notarization submission/result, and exact build inputs with the release evidence.
7. Download the candidate through its intended distribution path to exercise quarantine and Gatekeeper behavior on another supported machine/account. Do not remove quarantine or bypass a warning to count the check as passed.

Use Apple's current notarization process and tools supported by the installed toolchain. [Apple notarization](https://developer.apple.com/documentation/security/notarizing-macos-software-before-distribution).

The initial documented ARM64 build command is `php artisan native:build mac arm64 --no-interaction`; inspect `--help` before assuming syntax for other architectures or publishing. Keep stable artifact names/version/channel conventions once users start upgrading.

## Workstream 4 — Updates and migrations

Reuse NativePHP's supported updater provider. Choose a public read-only release feed/artifact destination for a public beta so the app need not contain a shared privileged download credential. Store upload credentials only on the publishing side. Separate beta and stable feeds if both exist; do not invent a stable channel before it is needed.

NativePHP exposes updater configuration and lifecycle events; macOS updates require signing. Validate the feed and events against the installed version. [NativePHP updating](https://nativephp.com/docs/desktop/2/publishing/updating).

### User flow

- Add Settings → Updates with installed version, Check for updates, download progress, errors, and an explicit restart/install action.
- Preserve usable cached workspace data when offline or when the update service fails. An update check must not block opening the app.
- Do not restart with unsaved forms or during backup/restore/application writes. Coordinate with the workspace maintenance mechanism and stop workers cleanly before applying an update.
- Surface a retryable download failure without discarding the installed working version. The trusted updater handles artifact integrity/signature checks; do not add an ad hoc downloader that bypasses them.

### Upgrade and recovery

- Test old-to-new migrations using the actual last distributed package and a populated fixture workspace. Development migrations alone are insufficient.
- Take a verified local recovery snapshot before applying a migration-bearing upgrade. Preserve encrypted values and icons. Test failures without replacing working data with a partially migrated schema.
- Maintain stable app identity and native encryption access through the upgrade. Prove previously saved credentials and secrets decrypt after a normal relaunch.
- Reject an unsupported schema downgrade instead of opening a newer database with older code. Retain the prior installer and recovery snapshot for a controlled recovery path; do not promise automatic binary rollback over incompatible data.
- Verify update feed metadata, artifact URLs, versions, channels, and architecture routing. A client must not receive an untested architecture or an older release as an apparent upgrade.
- Withdraw or supersede a bad release at the feed level and document recovery instructions. Keep the last known good package available.

## Workstream 5 — Accessibility and usability

Use WCAG 2.2 AA as the web-content review target alongside actual macOS VoiceOver and keyboard testing. Default components help but do not establish application accessibility automatically. [WCAG 2.2](https://www.w3.org/TR/WCAG22/).

Exercise full user journeys, including:

- Keyboard-only navigation through sidebar, tabs, tables, fields, dialogs, native pickers, and Settings.
- Board task moves and column ordering without dragging; visible focus and focus return after save/delete/cancel.
- Meaningful accessible names for icon-only controls, structured headings, form labels, error associations, and status announcements that do not steal focus.
- VoiceOver discovery of scan state, provider errors, task counts, hidden/revealed secret state, and backup/restore confirmations without speaking a value before explicit reveal.
- Minimum 600 × 600 window use, enlarged text/zoom, long project names, long paths, multiline values, and overflow without clipped actions.
- System reduced-motion preference, adequate contrast in supported appearances, and non-color-only status information. Keep default shadcn styles rather than applying a custom theme to patch a layout problem.
- Clear cancellation and retry behavior for slow scans, provider requests, downloads, and backup/restore; useful empty states with no placeholder functionality.

Fix concrete issues through existing component APIs and layout changes. Do not add an accessibility overlay or replace established native dialogs with custom imitations.

## Workstream 6 — Setup, privacy, and support material

Prepare release-facing instructions covering:

| Topic | Required content |
| --- | --- |
| Install | Download link, verified publisher, supported OS/CPU, normal installation steps; no requirement for PHP/Node/Composer to run Orbit itself |
| First use | Create/link a project, select roots, use the board, and connect a provider when desired |
| Project knowledge | Edit documents, find content, organize links/secrets, and record a review |
| Local tools | Runtime detection uses selected host executables; Orbit does not install project toolchains or run project scripts |
| Data location | Where local workspace data lives, what project removal does, and that source folders remain untouched |
| Connections | Supported providers/token scopes, local encrypted storage, replacement/disconnect, and provider-side revocation |
| Secrets | Explicit reveal/copy, limits of the clipboard, `.env` plaintext export, and native encryption behavior |
| Recovery | Create a portable backup, password responsibility, optional secret inclusion, replacement restore, reconnection, and relinking |
| Updates | Channel/version, manual check, restart behavior, failures, and supported upgrade/recovery policy |
| Privacy | Actual network destinations and purposes; local storage; no workspace sync or project-data telemetry by default |
| Limitations | Unsupported lockfiles/enterprise providers/CPU or OS versions, and any verified unresolved issues |
| Support | A real contact/issue destination and safe instructions for reporting a problem without tokens, secrets, or private files |

Update existing README/setup information and create only the release documents needed for distribution. Use fictitious demonstration projects and dummy credentials in screenshots. Never export the developer's real workspace as demo content.

If diagnostics are offered, make collection explicit and previewable, keep them narrowly sanitized, and do not reintroduce the removed desktop diagnostic feature. A support email with version/OS and a user-written description is sufficient for the first beta.

## Acceptance matrix

Build a real matrix with exact machine/OS/package entries before release. An unrun row stays unverified.

| Scenario | Required result |
| --- | --- |
| Clean install, supported ARM64 macOS | Downloaded app passes platform checks and opens without development dependencies |
| Real second macOS login | Create, quit, restart, and persist data with that account's home/Keychain; closes milestone 0 |
| Intel, if advertised | Real x64 artifact runs all essential workflows; Rosetta-only testing is insufficient proof of native Intel support |
| Minimum supported OS | Install/start, native dialogs, encryption, and updater work on the actual advertised minimum |
| Populated upgrade | Catalog, documents, review dates, link/secret groups, board order, icons, roots, connections, secrets, and revisions survive migrations/relaunch; content search still opens matching items |
| Offline/provider failure | Local app works; snapshots remain honestly stale; no retry storm |
| Interrupted scan/update | Clean process lifecycle; no lost edits, stuck worker, or incomplete replacement |
| Wrong/corrupt backup and interrupted restore | Current workspace remains complete; recovery phase is clear |
| Cross-account restore | Secrets re-encrypted; provider reconnection and folder relinking work |
| Signature after repeated use | Signed bundle stays unchanged and valid |
| Keyboard/VoiceOver/minimum window | Essential workflows complete without dragging or inaccessible controls |
| Downloaded update candidate | Correct channel/architecture/version; signature integrity and data migration verified |

For every row record artifact version/hash, host OS/CPU, date, observed result, and any limitation. Automated checks supplement these native checks; they cannot replace them.

## Delivery sequence

1. Close earlier milestone gaps and confirm scope/support decisions.
2. Audit the candidate dependencies and production boundaries; make targeted fixes and rerun affected checks.
3. Finish accessible Settings/update flows and release-facing instructions.
4. Build/sign/notarize a candidate and upload it only to the intended staging/draft destination for verification.
5. Exercise the download, fresh install, real second-account run, populated upgrade, update feed, and recovery matrix.
6. Prepare the exact public artifacts, hashes, notes, download/feed links, and support information. Publish only the verified candidate through the chosen release workflow.
7. Confirm the published links return the intended artifacts and a released client sees the correct update. Record the release and its known limitations.

Likely implementation areas: dependency manifests/lockfiles only where required, `config/nativephp.php`, native provider/worker lifecycle, bundle cleanup/cache handling, update Settings UI, targeted accessibility fixes, tests, README/release material, and the existing integration record. Do not redesign the product or add a hosted backend to complete this milestone.

## Completion checklist

- [ ] Milestones 1–6 and the outstanding real second-account runtime check have evidence.
- [ ] Supported macOS/CPU versions and all provisional release decisions are recorded.
- [ ] Fresh audits and production-boundary checks cover the actual bundled dependencies/artifact.
- [ ] Application tests, native regression checks, formatting, and production build pass for the candidate.
- [ ] Signed/notarized/stapled artifact passes downloaded-install checks without bypasses.
- [ ] Fresh installation and populated upgrade pass on every advertised support target.
- [ ] Secret access, worker shutdown, and signature integrity survive normal use and restart.
- [ ] NativePHP update checks/download/install and failure recovery are verified.
- [ ] Cross-account portable restore and accessibility journeys pass.
- [ ] Setup, privacy, recovery, limitations, release notes, and support material match actual behavior.
- [ ] Public artifacts/feed links are verified and the last known good release remains recoverable.

No billing system, workspace sync, collaboration layer, enterprise-provider support, or additional operating system is required for this beta. Those are separate product decisions after the confirmed v1 scope is complete.
