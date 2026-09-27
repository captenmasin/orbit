# Settings — implementation plan

Status: implemented 27 September 2026. Phases 1–6 and updater controls are implemented with automated and browser checks. Packaged macOS lifecycle, clipboard, login-item and signed-upgrade acceptance checks remain unverified. The user requested that update checks remain unavailable for now; publishing the release feed, signed artifacts and release-notes destination is deferred.

Parent: [Product plan](../PLAN.md). Related: [Secrets and recovery](PLAN-05-secrets-recovery.md), [Public beta](PLAN-07-public-beta.md).

## Outcome

Give Orbit one Settings destination for app preferences, project defaults, security, connections and recovery. Preferences survive restart and apply consistently wherever the relevant operation runs: the desktop interface, background workers and local MCP clients.

The eight sections below are agreed. Exact option values and defaults are proposed implementation choices.

## Settings structure

Use one Settings entry in the main sidebar, with section navigation inside Settings. Retain the existing Connections and Backups URLs and reuse their working components. On narrow windows, section navigation becomes a compact selector above the content.

Follow the existing shared controls, spacing and typography. Lead with headings, without eyebrow labels. Each editable section has its own Save action and saved/error feedback; changing one section must not save another section's unfinished form. Appearance previews immediately and persists when saved.

| Section | Contents | Proposed default |
| --- | --- | --- |
| General | Startup destination; launch at login | Dashboard; launch at login off |
| Appearance | Light, Dark, System | System |
| Security | Secrets PIN; automatic lock duration; clipboard clearing | Lock 15 minutes after unlock; clear copied secrets after 30 seconds |
| Project defaults | Ordered board columns, including their existing colour options | Backlog, To Do, In Progress, Done |
| Connections | Git provider accounts; scratchpad AI provider/key/model; local MCP setup | AI unconfigured until the user supplies a key |
| Tools & runtimes | Global executable paths for PHP, Node, Composer, npm, pnpm and Yarn | Automatic detection |
| Backups & restore | Export; restore preview; preferred destination folder; last successful backup | Manual backups; exclude project secrets |
| About & updates | Installed version; update checks; release notes | Update controls available when a release feed is configured |

## 1. General

- Offer Dashboard or Last project as the startup destination.
- Remember a project identifier, rather than an arbitrary URL or an unfinished form. Resume its overview; use Dashboard if it was removed, archived or is unavailable.
- Expose launch at login through NativePHP's existing macOS support. Read the actual OS setting and confirm it changed before reporting success.
- Opening Settings or another utility page must not replace the last-project record.

Acceptance: both startup choices survive Quit/relaunch; missing projects fall back cleanly; launch-at-login changes are verified in a packaged app.

## 2. Appearance

- Offer Light, Dark and System, with a preview that updates the current window.
- Apply the selected theme before the first paint, then keep the native window, page styles, shared components and toasts consistent.
- In System mode, react to OS appearance changes while Orbit is open. An explicit Light or Dark choice overrides those changes.
- Persist the saved choice. A failed save leaves the saved preference intact and reports the error; abandoning an unsaved preview restores the saved appearance.

Acceptance: all three modes work across navigation and restart, without a flash of the wrong theme; System reacts to an OS change. Existing `.dark` styles need a shared bootstrap because the current app does not apply the class.

## 3. Security

- Reuse the existing four-digit Secrets PIN setup/change flow, validation, attempt limits and native PIN storage.
- Offer automatic locking 5 minutes, 15 minutes, 1 hour or 8 hours after unlocking. Label this as elapsed time after unlock, rather than inactivity. The current hardcoded duration is 8 hours; propose 15 minutes for the new default.
- Enforce expiration centrally through the vault and middleware, covering secret reads, writes, imports/exports and backups that include secrets. The renderer clears displayed plaintext when access expires.
- Changing the PIN or lock duration revokes the current unlock. Close/reopen and Quit/relaunch must start locked.
- Offer clipboard clearing after 30 seconds, 60 seconds or Off, defaulting to 30 seconds. Clear only if the clipboard still contains Orbit's unchanged secret copy; preserve anything copied afterwards.
- Keep clipboard handling native. Secret values must not enter renderer props or persistent timer/job payloads to implement clearing.

Acceptance: configured expiration rejects access on every protected route; PIN changes revoke access; close/reopen is locked; clipboard clearing preserves a later copy. Reuse the existing vault and secret tests, plus packaged checks for native lifecycle and clipboard behaviour.

## 4. Project defaults

- Let users add, rename, reorder, remove and colour default board columns, reusing the board's existing names/colour validation and keyboard ordering controls.
- Require at least one valid column and apply the same limits used by project boards. Provide Restore default columns with a saveable preview.
- Apply the saved template in the shared project-creation path so both UI and MCP creation receive the same defaults.
- Existing projects retain their boards. Duplication copies the source project's board; restoring a backup restores its recorded columns.
- Save column definitions, without project-specific IDs or task data. Each new project receives fresh column IDs.

Acceptance: custom names/order/colours appear on newly created projects through UI and MCP; existing, duplicated and restored boards retain their own definitions; an invalid template cannot create an empty or partially initialized board.

## 5. Connections

### Git accounts

Reuse the existing Git connection list, credential verification, encrypted storage, replacement and disconnect behaviour. Preserve working connections if an attempted replacement fails. Surface verification errors and account labels using the current components.

GitLab code exists, but live GitLab verification remains deferred in the earlier roadmap. Moving its controls into Settings must preserve that verification status accurately.

### Scratchpad AI

- Add one active AI configuration: provider, API key and explicit model ID. Offer installed SDK providers after verifying that they support Orbit's structured scratchpad output; OpenAI is the existing integration.
- Store the key using the existing native credential encryption. Show whether a key is configured, with Replace and Remove actions; return no saved plaintext key to the page.
- Use Save and verify to check the provider/key/model with a bounded synthetic structured-output request before replacing the active configuration. Preserve the previous working configuration if verification fails.
- Use the selected provider/model for scratchpad action generation. Remove the user-facing requirement to edit `.env`; packaged builds must not contain a developer API key.
- Explain that generating actions sends the scratchpad and the existing limited project context to the selected provider. Keep the existing preview before applying changes.
- Preserve the bare-URL path that works without AI, revision protection, bounded requests and the user's notes when generation fails.
- If configuration is missing, direct the user to Connections. Keep provider failures readable and credentials out of logs/errors.

### Local MCP

Retain the existing AI-client configuration section, bound to the active workspace database/storage. This is separate from the provider that powers Orbit's own scratchpad actions. Preserve its existing inability to reveal secret values.

Acceptance: Git and AI credentials survive restart; failed replacement preserves the prior working configuration; scratchpad uses the saved provider/model; removing its key leaves notes intact; local MCP setup still works after navigation and restart.

## 6. Tools & runtimes

- Provide automatic detection or an explicit executable path for each of the six tools Orbit already probes. Reuse native file selection and the existing bounded version checks.
- Resolve each tool in this order: package-root override, global setting, automatic detection. An invalid explicit path produces an error instead of silently substituting another executable.
- Display the effective path and detected version/state. Preserve existing exclusions for project-controlled executables and Orbit's bundled runtimes.
- Read saved preferences when a scan starts so long-lived workers see changes. Global edits do not erase root overrides or replace successful cached results with failed probes.
- When a global tool path changes, mark affected results stale and invalidate the existing scan/check tokens. A job started with old effective paths must not publish its results as current; preserve its previous successful snapshot for reference.
- Share effective executable choices wherever runtime probes run. Dependency checks that carry runtime data into rewritten snapshots must preserve its freshness; package update/security requests continue using their existing HTTP APIs. Explain that changed defaults apply on the next runtime scan.

Acceptance: overrides win at the correct level; missing/unsafe executables are rejected; clearing a global path restores detection; a worker started before the preference change uses the new value on its next operation; an in-flight job using old paths cannot publish current results.

## 7. Backups & restore

- Reuse the existing encrypted export, optional project secrets, destination preview, overwrite confirmation, restore preview, changed-file detection and rollback behaviour.
- Remember a preferred backup folder and seed the save dialog from it. A missing/unwritable folder produces a recoverable choice of destination.
- Show the last successful export time and destination, recording them only after the complete backup is written. Label this as the last export, rather than claiming the file still exists.
- Include portable board defaults in the backup. Keep machine-specific preference paths (global executables and preferred backup folder), startup state, last-project state, PIN/unlock state, clipboard policy and Git/AI credentials local to the installation. Preserve the existing backup contract for project folder paths and root executable overrides, which are revalidated when used.
- Add an explicitly validated preferences record and advance the backup schema when introducing it. The current writer uses schema 3 and the reader accepts schemas 1–3; retain those restore paths. Older backups without preferences leave the installation's defaults intact.
- Show incoming board defaults in the restore preview. Reconnect provider accounts after restore, as the current flow requires.
- Include the preference revision in preview/staleness checks. Apply incoming board defaults in the same atomic restore transaction and rollback snapshot as project data, merging them with retained local preferences.

Acceptance: portable defaults round-trip with project data; older backups still restore; credentials and machine preferences remain excluded; failed exports do not advance the last-export record; defaults changed after preview reject the restore; wrong passwords, damaged files and failed restores preserve a usable workspace and its preferences.

## 8. About & updates

- Show the actual installed app version and provide release notes through a configured destination.
- Reuse NativePHP's updater for Check for updates, available-update state, download progress, failure/retry and an explicit restart/install action.
- Keep app startup usable when offline or when the update service fails. Protect unsaved forms and active backup/restore/write operations before restarting.
- In development builds or before an update feed is configured, state that update checks are unavailable. Do not display a successful check that never contacted a working source.

The release destination, signed artifacts, feed and release-notes URL belong to the existing public-beta work. This section can ship its version display first; operational update acceptance requires those inputs.

Acceptance: a packaged app checks the actual feed, handles offline/failure states and installs a signed update without losing workspace data or preferences. Use the public-beta migration/recovery checks for the actual upgrade.

## Shared implementation

- Add one small workspace preference record in SQLite and a concrete accessor with validated defaults. UI requests, native startup, workers and MCP already share the workspace database. Native Settings alone cannot serve MCP, which runs without the native bridge.
- Read preferences per operation, rather than keeping process-wide copies in long-lived workers. Merge section changes atomically and prevent stale saves from overwriting newer preferences.
- Keep the PIN hash in its current Native Settings storage. Keep API keys encrypted with `ProtectCredential`; expose only safe configuration/status fields through page props.
- Treat macOS as the source of truth for launch at login. Report failed native operations without saving a misleading success state.
- Reuse existing Inertia forms/HTTP handling and shared controls. Keep native-only operations gated in browser development; previewable settings continue to work there.
- Use the installed stack: PHP 8.5, Laravel 13.31.0, Inertia Laravel 3.4.0 / Vue adapter 3.7.1, Laravel AI 1.0.0 and NativePHP Desktop 2.3.1. Recheck package versions and relevant Boost documentation before implementing each phase.

Primary integration points: the existing Settings/Connections/Backups pages, shared workspace layout/sidebar, `SecretVault`, `SecretVaultController`, `Project::created`, `ProbeRuntimes`, dependency-check actions, scratchpad controller, workspace backup/restore and `NativeAppServiceProvider`.

## Implementation order

| Phase | Deliverable | Completion check |
| --- | --- | --- |
| 1 | Settings navigation, preference storage, Appearance, About version display | Section saves/reloads/restarts work; theme follows the chosen mode |
| 2 | Security preferences | Expiration, PIN revocation, lifecycle locking and clipboard clearing verified |
| 3 | Project defaults | UI/MCP creation use the template; existing/duplicate/restored boards retain their data |
| 4 | Connections, including saved scratchpad AI configuration | Credential persistence/replacement failures and configured AI generation verified |
| 5 | Backups & restore metadata and portable board defaults | New-format round-trip and schema 1–3 compatibility verified |
| 6 | Tools & runtimes, General startup preferences | Executable precedence, worker freshness, startup fallback and login item verified |
| 7 | Operational updates and release notes | Published feed and signed old-to-new upgrade pass the public-beta checks |

All eight sections remain in scope. Phases specify implementation order.

## Verification

- Extend the existing feature tests around vault access, project creation/duplication, credentials, scratchpad generation, runtime/dependency checks and backup/restore. Add focused preference tests for validation, persistence and stale writes.
- Fake provider calls, native APIs and time in automated tests. Cover distinct failure modes through behaviour; presentation-only changes need visual checks rather than new tests.
- Run the narrowest affected tests after each phase. Run Vue type checking and the production build for frontend integration; run Pint when PHP changes are made.
- Check keyboard access, labels, validation feedback, section navigation and both themes at the existing 600 × 600 minimum window size.
- Verify native-only behaviour in the desktop app, including normal Quit/relaunch and window close/reopen. Use disposable test data for restore and packaged-upgrade checks.

## Roadmap alignment

This plan records the current settings agreement. The working implementation takes precedence over older roadmap statements: local MCP setup exists; workspace backups currently write schema 3; PIN, Git connections and manual backup/restore already have working flows. Operational updater work continues to depend on the public-beta release infrastructure.
