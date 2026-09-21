# Milestone 3 — Local inspection

Status: complete, verified 19 September 2026 in the signed 0.4.0 Apple Silicon package. Written 15 September 2026. The specification below is retained with implementation decisions and verification evidence recorded here.

Parent: [Product plan](../PLAN.md). Next: [Milestone 4 — GitHub/GitLab](PLAN-04-provider-activity.md).

## Implementation and verification — 19 September 2026

- Implemented explicit relative package roots, independent revision checks, additive migrations, and token-guarded background scans. Existing catalog and board records were preserved. A root relink/overlap/delete regression prevents obsolete jobs from publishing.
- Composer and npm lockfile versions 1–3 preserve required constraints, resolved versions, development scope, nested locations, and workspace links. Yarn/pnpm lockfiles remain explicitly unsupported. Reads are capped at 256 KB per manifest, 16 MB per lockfile, and 20,000 entries, with structure and containment validation.
- Runtime probes use `/` as their working directory to avoid loading project configuration. PHP/Node must resolve to native binaries; Composer PHARs and known package-manager CLI scripts use the selected interpreter. Corepack, arbitrary wrappers, project executables, and Orbit’s bundled runtimes are excluded. Probes strip injection variables and have three-second/64 KB limits; no installation or compatibility solving occurs.
- NativePHP owns one database worker on the `inspection` queue. Jobs have a 45-second timeout, the worker 60 seconds, and retry visibility 90 seconds. Automatic refresh checks the selected active project at one-minute intervals when older than five minutes; pending scans poll every two seconds. Derived props preserve unsaved form input.
- All 71 PHP tests passed (851 assertions), plus four JavaScript regressions, Vue type checking and a production build. Tests cover the matrix below, including malformed sources, process limits, stale revisions, and draft preservation. Test storage now lives outside the repository so non-Git fixtures remain non-Git after repository initialization.
- Packaged UI checks used two Git checkouts and a nested JavaScript root. The UI showed distinct branches, the HEAD committer date, both nested Vue versions, and a changed manifest constraint independently of its unchanged lockfile. Saved roots and overrides survived restart.
- Quit during an observed `Scanning` state stopped the worker and probe children. Relaunch retained prior results; after the queue visibility budget the interrupted job became stale and could be retried. Controlled slow executables produced `Timed out` results. Moving the root away produced `Missing folder` without erasing the previous snapshot; restoring it recovered successfully.
- Native folder selection and external runtime detection passed on a normal GUI launch. The signed bundle and DMG checks passed; original packaged/development data matched backups after fixture cleanup. See [the desktop integration record](desktop-integration.md) for packaging details and the non-reproduced initial server-exit observation. A second macOS profile and notarization remain separate release checks.

## Outcome

Opening a project shows what is actually on disk: its Git checkout state, latest local HEAD commit, declared and locked dependencies, and the local executables used to detect runtimes. A mixed PHP/JavaScript project can have several explicitly selected package roots. Scanning never freezes the workspace or executes project scripts.

The exit demonstration is a mixed project with two checkouts and a nested package root. Refresh it, change a branch and a manifest outside Orbit, refresh again, then remove a folder and quit during another scan. After reopening, correct results and useful failure states remain, with catalog and board data intact.

## Baseline and constraints

- `app/Actions/InspectFolder.php` already reads bounded manifest metadata and Git information when adding or relinking a folder. Reuse its safe process invocation and metadata semantics; remove duplication if ongoing scans need a separate reader.
- `ProjectFolder` already stores `git_state`, `branch`, `last_commit_hash`, `last_commit_at`, and `scanned_at`. The project list already sorts by the latest known local commit.
- `CatalogController` supplies the native folder picker and scoped folder/repository opening. Keep those flows.
- The inspected dependency set is Laravel 13.31.0, NativePHP Desktop 2.3.1, Inertia Laravel 3.3.4, and Vue/Inertia packages declared in `package.json`. Confirm installed versions again before using APIs.
- Keep one Laravel application, SQLite, Inertia/Vue, the existing sidebar-07 shell, and default shadcn-vue component styles. Keep useful field labels and errors; add no promotional copy or diagnostic dashboard.
- Read the applicable `AGENTS.md` and `.ai/rules`, if present, before implementation. This plan does not authorize unrelated dependency upgrades.

## Scope

### Git checkouts

Show repository root, linked folder, remote, current branch or detached HEAD, abbreviated commit hash, commit subject, and the committer timestamp at HEAD. Preserve the distinction between one repository and its multiple local checkouts.

Identify normal repositories and linked worktrees, including `.git` files. Resolve a selected directory's containing Git root without recursively searching the disk. Treat bare repositories explicitly: show their commit metadata when readable, but do not label them working checkouts. Do not confuse a nested package root with a second checkout.

The authoritative local date is the HEAD commit's committer date, normalized to UTC in storage and serialized with a timezone. Author date, refresh time, and Orbit's edit time must never substitute for it. Git documents `%cI` as the strict ISO committer date. [Git log format](https://git-scm.com/docs/git-log#_pretty_formats).

### Dependencies

Each explicitly selected package root may contain either or both supported ecosystems:

| Source | Information to display |
| --- | --- |
| `composer.json` | Direct production/development requirements; PHP and extension constraints |
| `composer.lock` | Resolved package versions from `packages` and `packages-dev`, retaining development scope |
| `package.json` | Production, development, peer, and optional declarations; engines and package-manager declaration |
| `package-lock.json` | Resolved entries, package locations, and direct/transitive identity for supported lockfile versions |

Support npm lockfile versions 1, 2, and 3 with explicit handling of their structures. In versions 2/3, distinguish the root entry from installed-location entries and preserve nested duplicate versions. In version 1, retain enough location information to avoid flattening different resolved versions into one. Handle workspace links without treating them as a registry version. Unknown future versions get an unsupported-format state. [npm lockfile format](https://docs.npmjs.com/cli/v11/configuring-npm/package-lock-json/).

Show required and locked values in separate columns. A missing or mismatched lock entry is unknown, not proof of an uninstalled dependency. Do not inspect `vendor` or `node_modules` to claim installation status. Composer's lockfile records exact resolved versions used by installs. [Composer lockfiles](https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies).

Yarn and pnpm declarations remain readable through `package.json`; their locked versions are labeled unsupported in this milestone. Version-range solving and automatic compatibility judgments are unnecessary: preserve the declared constraint and detected value without inventing a match result.

### Runtime detection

Detect PHP, Node, Composer, npm, pnpm, and Yarn when an appropriate executable is available. Display the version, resolved executable path, probe directory, and scan time. PHP/Laravel/Node and recognized framework packages can be summarized above the full dependency table using the same parsed data.

Allow a saved executable override per tool per selected root. Resolve the GUI process's environment honestly; do not silently launch a login shell or source shell startup files to imitate the terminal. If no runtime can be resolved, show the reason and offer the override.

Container-only tools remain undetected unless the user selects a supported host executable. Do not execute Docker, project commands, package installation, dependency upgrades, or remote release lookups.

## Data changes

Use additive migrations and keep user configuration separate from derived snapshots.

| Record | Proposed change |
| --- | --- |
| `project_folders` | Keep existing successful Git fields; add last-attempt time, scan state, safe error code, and a request token for replacing scan results |
| `package_roots` | UUID, owning folder FK, relative path, executable overrides, user-edit revision, timestamps; unique folder/path |
| Package-root snapshot | Versioned JSON stored on its root with declared/locked/runtime results, source file information, warnings, successful scan time, last-attempt state, and request token |

Create a `.` package root for linked folders as needed through an explicit migration or write operation, and include it when linking new folders. Allow adding/removing nested roots using the existing native picker. Removing a root only removes its configuration and cached results.

Canonicalize selected roots. Nested roots must remain inside their linked folder after resolving symlinks; a root outside it should be linked as a separate folder. Store relative paths so folder relinking preserves nested roots. Revalidate those paths at scan time and after relinking.

Keep a successful snapshot when an attempt fails, along with its original timestamp and a visible stale/error state. A successful scan that finds an empty Git repository must clear the obsolete successful commit; it is different from a failed scan.

Derived scan writes must not increment the project's user-edit revision or replace current form input. Root and executable-override edits do use stale-write protection. Do not add a generic cache framework or a database row per dependency unless actual query needs justify it.

## Reading and execution boundaries

1. Validate the folder/root and capture its current path, settings, and request token before dispatch.
2. Read only supported manifest/lockfile names in explicitly selected roots. Do not recursively crawl repositories, follow arbitrary manifest paths, or fetch referenced URLs.
3. Retain the existing 256 KB manifest limit as the starting default. Start lockfiles at a documented 16 MB limit; oversized files receive a clear state. Validate structure, depth, entry count, and scalar types as well as JSON syntax.
4. Use argument-array subprocesses with an explicit working directory, bounded output, and timeouts. Start with three seconds and 64 KB of output per version probe; tune only from representative fixtures.
5. Preserve disabled Git terminal prompts, optional locks, unsafe inherited Git environment overrides, and unnecessary global configuration. Read-only inspection must not fetch, checkout, run hooks, or write repository files.
6. For version probes, strip inherited runtime injection variables such as `NODE_OPTIONS`, `NODE_PATH`, `PHPRC`, and `PHP_INI_SCAN_DIR` where appropriate. Use PHP's no-configuration version mode and Composer's available no-plugin options after checking installed APIs.
7. Account for wrappers: Corepack may download a package manager, Yarn may delegate through project configuration, Composer may load plugins, and environment-manager shims may execute other tools. Permit only bounded version behavior that the implementation can establish; otherwise report that the executable needs an explicit selection. Never auto-install a missing tool.
8. Exclude Orbit's bundled PHP and Electron/Node executables from project-runtime discovery, including resolved symlink targets. A missing external tool is a valid result.
9. Sanitize command errors and remote URLs. Store a useful error category, not raw environment variables, private configuration, or unbounded stderr.

## Background work and refresh lifecycle

Use Laravel's existing database queue and NativePHP-managed worker support. Start with one worker, one scan per selected folder/root at a time, and ID/token-only job payloads. No additional daemon, Redis, or operating-system scheduled task is required. NativePHP supports configured queue workers in the application lifecycle. [NativePHP queues](https://nativephp.com/docs/desktop/2/digging-deeper/queues).

- Manual Refresh is always available when the target is not already queued or running.
- Proposed automatic policy: refresh on project open when older than five minutes; while that project is visible, check staleness once per minute. Pause automatic dispatch when the window is hidden or the app is inactive. Do not scan all projects on launch.
- Bound each job's total work. Choose worker timeout and database `retry_after` so a live job cannot be claimed twice; the worker timeout must exceed the job's process budget and be shorter than `retry_after`.
- Duplicate requests coalesce rather than spawning parallel processes for one target. Validation failures do not retry; transient process failures permit one manual retry.
- Commit results only if the record still exists and its path, settings, and request token still match. A removed/relinked root or an older finishing job cannot overwrite newer results.
- Keep transactions short: subprocesses and file reads happen outside them; validate the token again inside the final write.
- On quit, let NativePHP stop workers and child processes. On the next launch, reconcile abandoned running states with the queue and their time budgets, rather than showing “Scanning” forever.

## Interface

Add a Dependencies project tab. Group results by root, with source paths and last successful scan time. Use default shadcn tables, badges, fields, alerts, and dialogs.

Repositories continues to show repository records and their checkouts. Refresh controls update the Git readout without resetting unsaved catalog or board edits. Show the source checkout/branch for the project-level latest known commit, especially when several checkouts disagree.

Use distinct states for: not scanned, queued, scanning, current, stale, missing folder, permission denied, not Git, empty repository, detached HEAD, missing executable, timed-out probe, malformed file, oversized file, and unsupported lockfile. Do not turn every partial failure into a blank page or a generic zero value.

## Implementation sequence

1. **Define result shapes and fixtures.** Inventory current readers and consumers; specify Git, manifest, lockfile, and runtime snapshot shapes. Create representative fixture files without running their scripts.
2. **Add roots and snapshot persistence.** Migrate existing folder metadata without changing catalog/board data. Implement root selection, relinking behavior, executable overrides, and revision validation.
3. **Complete readers.** Expand the Git reader, add Composer/npm lockfile parsers, and add bounded runtime probes. Exercise failure states before wiring refresh.
4. **Run scans off the request path.** Configure the existing queue worker, deduplication, request tokens, result writes, and interrupted-job recovery.
5. **Connect the UI.** Add Dependencies and refresh/state controls; retain successful snapshots while loading or failing. Keep derived refreshes separate from dirty forms.
6. **Verify a packaged app.** Compare GUI and terminal executable resolution on fixtures, interrupt a scan with normal Quit, restart, and verify persistence. Record actual results in the existing integration record and update the parent milestone status only after exit checks pass.

Likely implementation areas: `InspectFolder`, `ProjectFolder`, `SaveProject` folder handling, a package-root model, small reader/actions and scan jobs, `CatalogController` or a focused inspection controller, `config/nativephp.php`, `routes/web.php`, project Vue tabs, `types.ts`, and focused feature/parser tests. Use existing helpers and primitives before introducing new files.

## Verification matrix

| Scenario | Required result |
| --- | --- |
| PHP, JS, and mixed roots | Correct declared, locked, and detected fields with source paths |
| Nested roots, worktrees, duplicate dependency versions | No root confusion or lossy flattening |
| Author/committer dates differ; timezone differs | HEAD committer date and consistent UTC serialization |
| Empty, detached, bare, corrupt, or non-Git folder | Correct distinct state; no fabricated commit |
| Missing file, permission failure, malformed/oversized JSON | Useful partial results; previous success clearly marked stale |
| Unsupported lockfile or Yarn/pnpm project | Declarations shown; locked state explicitly unsupported |
| Missing executable, wrapper, timeout, output flood | Bounded failure; no silent installation or project script execution |
| Relink/delete while a job runs; two overlapping requests | Obsolete job cannot publish or recreate data |
| Dirty form while scan completes | User draft preserved; background result cannot mutate catalog data |
| Quit/relaunch during scanning | No orphan processes or permanently stuck state |

Use existing PHPUnit facilities, real temporary Git fixtures for Git semantics, and controlled subprocess doubles for timeouts and executable failures. Run focused tests, PHP formatting, Vue type checking/build, and the existing quit regression when queue lifecycle changes. Do not use live network calls for local-inspection tests.

## Completion checklist

- [x] Supported source/version labels are correct across the verification matrix.
- [x] Explicit roots and overrides survive restart and folder relinking.
- [x] Scans are bounded, run outside UI requests, and never execute project scripts.
- [x] Failed and obsolete scans cannot erase good snapshots or user edits.
- [x] Catalog commit sorting retains correct timestamps and visible source provenance.
- [x] Desktop remains usable while several targets are queued; normal Quit still works.
- [x] Migration, focused tests, production build, and packaged checks are recorded.

No GitHub/GitLab requests, secret vault, dependency installation/upgrades, recursive disk indexing, or filesystem watcher is part of this milestone. Continue with [Milestone 4](PLAN-04-provider-activity.md) once these checks pass.
