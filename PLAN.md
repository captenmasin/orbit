# Orbit — product and implementation plan

Draft: 8 September 2026. Based on the product interview. Confirmed decisions are separated from proposed defaults below; this is a plan, not an implementation.

## Implementation status — 22 September 2026

Milestone 1 (Catalog) is complete in the 0.2.0 development app. Projects support descriptions, statuses, tags, initials/emoji/uploaded raster icons, multiple repositories and folders, ordered categorized links, search/filter/sort, archive/restore, safe removal, and missing-folder relinking. Native folder selection previews manifest metadata and local HEAD commit information before saving. The whole catalog save is atomic and rejects stale revisions. A multi-repository project survived a full app restart, was found by its tag, and had a moved folder relinked without losing data. All 22 application tests (311 assertions), the production build, and desktop checks pass.

Milestone 2 (Boards) is complete in the 0.3.0 development app. Each project has editable, ordered columns and tasks, with drag-and-drop and keyboard move controls. Deleting a populated column requires a destination for its tasks. Every board change uses the project's revision check and a database transaction; stale board edits, catalog edits, and project removals cannot overwrite newer changes. All 40 application tests (524 assertions) and the production build pass. Desktop checks verified keyboard ordering, conflict handling, the 600 × 600 layout, and persistence after Quit/relaunch. Browser checks verified task and column drag-and-drop; see the integration record for the native automation limitation.

Milestone 3 (Local inspection) is complete in the signed 0.4.0 Apple Silicon package. Background scans read Git checkout metadata, Composer/npm declarations and lockfiles, and bounded host-runtime versions for explicitly selected package roots. Required and locked values remain separate; failures retain prior results. Packaged checks verified two checkouts, a nested root, external branch/manifest changes, executable overrides, missing-root recovery, and Quit/relaunch during a scan. All 71 PHP tests (851 assertions), four JavaScript regressions, Vue type checking, and the production build pass. See [the milestone record](docs/PLAN-03-local-inspection.md) for scope and limits.

Milestone 4 is complete for the agreed GitHub scope in signed 0.5.2. Connections use native encryption; repository activity supports background snapshots, pagination, cooldowns and source-aware commit summaries. Live checks passed for private GitHub reads, Actions, partial permissions, conditional refresh, restart persistence, public pagination, disconnect/reconnect and disposable-token revocation. The user confirmed a full offline restart preserved cached activity, the offline notice and disabled refresh controls. All 94 PHP tests (1,008 assertions) and six JavaScript tests pass; rate limiting uses controlled fixtures. GitLab and verification with a second distinct account are explicitly deferred by the user, not marked passed. See [the provider milestone](docs/PLAN-04-provider-activity.md).

Following the initial ChatGPT-style direction, the user selected the [shadcn-vue sidebar-07 layout](https://www.shadcn-vue.com/blocks#sidebar-07) with default component styles and no overrides. The interface uses the classic Vega style, neutral theme, and locally bundled Inter font. Eyebrows and promotional copy are excluded. MCP clients and the desktop diagnostic block and functionality have been removed from implementation and future scope. NativePHP remains the desktop framework. The interface uses Inertia 3, Vue 3 with TypeScript, Tailwind CSS 4 and shadcn-vue. Laravel owns validation and persistence; Inertia handles navigation and forms.

The latest verified internal package is signed Apple Silicon 0.6.0 and includes the catalog, boards, local inspection, provider activity, secrets/recovery, and project knowledge features. Packaged encryption/restart, invalid-token handling, and GitHub acceptance checks pass. GitLab and distinct-account live verification are deferred by the user. Packaging excludes Vite’s `public/hot` marker so the app uses its bundled production assets. Packaged upgrade, project persistence, normal Quit/relaunch, window reopening, clean app-data setup, and signature integrity checks pass on the current macOS profile. NativePHP's reentrant quit bug is fixed with a small compatibility patch; milestone 6 also fixed native-dialog PHP timeouts and rollback snapshots selecting the wrong database. See [README.md](README.md) for setup and [the integration record](docs/desktop-integration.md) for evidence. A real second macOS profile remains the outstanding milestone 0 exit check. Dependency security updates, cross-profile recovery, notarization, and Intel validation remain separate release work; none is implied by milestone 6 completion.

Milestone 6 is **complete** in the fourth signed 0.6.0 build, 22 September 2026: titled documents and Notes migration, grouped links, secret context, project/workspace content search, bulk ideas, explicit review dates, and schema 2 backups with schema 1 restore compatibility. All 166 PHP tests (1,726 assertions), 21 JavaScript tests, type checking, production build, Pint, and diff checks pass. Packaged acceptance passed for populated upgrades, document/search/review interactions, exact code copying, 600 × 600 keyboard use, encrypted backup/restore, and normal Quit/relaunch with exact restored rows/ciphertext and successful native Reveal. The original full profile was returned: all three projects and common original fields, provider ciphertext, and snapshots matched the pre-migration database; integrity was `ok`, with no foreign-key violations. Strict app signature and final DMG verification passed. See [Project knowledge and migration](docs/PLAN-06-project-knowledge.md) and [the integration record](docs/desktop-integration.md). Public beta remains milestone 7, with notarization, Intel, and second-profile checks still open.

## Product

Orbit is a NativePHP desktop project manager for developers who want their projects, documentation, tasks, repositories, runtime information, links, and secrets in one place. Each developer manages their own local workspace.

The primary workflow is: find a project, understand its status and recent activity, see what needs doing, and access its working context without hunting through folders and browser bookmarks.

### Confirmed decisions

| Area | Decision |
| --- | --- |
| Audience | A distributed product for other developers |
| Workspace | Each developer manages projects independently |
| Storage | Everything stored on one machine; no workspace sync |
| Platform | macOS only for v1 |
| Framework | NativePHP Desktop |
| Kanban | Tasks within each project; project status managed separately |
| Repositories | Local Git plus GitHub and GitLab integrations |
| Dependencies | Manifest and lockfile information, plus installed local runtimes |
| Links | Organized bookmarks that open their destinations |
| Project knowledge | Titled Markdown documents, grouped links and secrets, content search, and explicit review dates |
| Visual style | shadcn-vue sidebar-07 layout and default styles without overrides; no eyebrows or filler text |

### Proposed defaults, not yet confirmed

The last interview questions received no answers. This draft assumes read-only GitHub/GitLab activity, secrets grouped by environment with manual `.env` import/export, and a free initial release with pricing decided later.

Other implementation defaults: GitHub.com and GitLab.com initially; manually supplied provider tokens; an Inertia/Vue interface with shadcn-vue; user-triggered backup and restore; a desktop UI for all operations. Self-hosted providers and billing remain separate decisions.

## v1 experience

### Project catalog

- Create a project manually or select a local folder and review detected metadata before saving.
- Store a name, description, icon, status, and tags. Offer an initials fallback, emoji, or uploaded raster image for the icon.
- Start with Idea, Active, Paused, Maintenance, and Archived statuses. These are a proposed initial vocabulary.
- Associate any number of repository records and local folders with a project. A project can exist without either.
- Let a repository have multiple checkouts, and let folders exist without Git. Preserve the distinction between a remote repository and a local checkout.
- Search by name, description, and tags; filter by status/tag; sort by name or last commit date.
- Archive without deleting. Removing a project from Orbit never deletes its repositories or local source folders.

Use shadcn-vue's sidebar-07 structure: a sidebar that collapses to icons, a breadcrumb header, and a main content pane. Keep the default component styles. Use a project list with icon, name, status, tags, and last commit as those fields become available. A project opens to Overview, Board, Repositories, Dependencies, Links, and Secrets. Settings contains provider connections, backups, and updates when implemented.

### Task boards

Each project has one board. Default columns are Backlog, To Do, In Progress, and Done; allow renaming, adding, removing, and reordering columns.

A task has a title, optional description, column, and position. Support creating, editing, moving, reordering, and deleting tasks. Column deletion requires moving its remaining tasks to another column. Provide keyboard-accessible move controls alongside drag and drop.

Milestone 6 adds previewed bulk creation from pasted idea lists into a selected column, preserving their order.

Persist board moves atomically. A stale edit must return a conflict instead of silently overwriting newer changes. Team assignments, sprints, time tracking, and recurring tasks are outside this v1 proposal.

### Repositories and activity

Show local path, remote URL, checked-out branch or detached state, latest local commit, and an action to open the folder or hosted repository.

Define dates explicitly:

- Local last commit: the committer timestamp of the commit at a checkout's current HEAD.
- Remote last commit: the timestamp of the tip commit on the provider's default branch.
- Project last commit: the latest known date across its associated repositories/checkouts, retaining the source and branch in the detail view.

Never present a fetch time or an Orbit edit time as a commit date. Empty repositories, missing Git, disconnected folders, denied permissions, and stale provider data get distinct states.

GitHub and GitLab display default-branch activity, open issues, pull/merge requests, and CI status, with links to the provider. These are read-only snapshots under the provisional scope; the local task board remains independent.

Store multiple provider connections when needed. Paginate responses, cache results locally, and respect rate limits. Start with manual refresh, refresh on project open when stale, and a modest refresh interval while the app is active. Keep the last successful snapshot and its timestamp when a refresh fails.

### Dependencies and runtimes

Track dependencies per selected package root so a project can contain several PHP or JavaScript applications. Inspect explicitly linked roots first; let the user add nested roots rather than recursively crawling the whole disk.

| Display | Source and meaning |
| --- | --- |
| Required version | Constraints from `composer.json` and `package.json` |
| Locked version | Resolved dependency versions from supported lockfiles |
| Runtime requirement | PHP requirements, Node engines, and package-manager declarations where present |
| Detected runtime | Version returned by the selected local PHP, Node, Composer, npm, pnpm, or Yarn executable |

Begin lockfile support with `composer.lock` and `package-lock.json`. Declared dependencies still work for Yarn/pnpm projects; label their locked versions unsupported until parsers are added. Locked does not mean installed: do not claim a lockfile proves what is in `vendor` or `node_modules`.

Highlight PHP, Laravel, Node, and major frameworks; allow the complete dependency list to be inspected. Record the source path and last scan time.

For runtime detection, show the executable path and probe directory, allow an executable override per folder, and use bounded version commands. A GUI app may resolve a different executable from the user's terminal. Do not report Orbit's bundled PHP as the project's PHP or silently execute project scripts to discover versions. Container-only runtimes can be marked undetected in v1.

Latest-release lookups and automatic dependency upgrades are outside the selected scope.

### Links

A link has a label, URL, optional category, icon, and display order. Include sensible categories such as Website, Social, Analytics, Inbox, Documentation, and Hosting, while allowing custom labels.

Milestone 6 displays these categories as visible groups, preserving saved ordering within each group and keeping uncategorized links accessible.

Open supported URLs in the default browser or registered application. Validate schemes; never treat a saved URL as an arbitrary shell command. No live analytics fetching is included.

### Secrets

Store named project secrets, grouped by environment under the provisional scope. Support add, edit, delete, reveal, and copy. Values may be multiline, including pasted PEM text; file attachments are a separate feature.

Milestone 6 adds an optional service/category, description, and management URL. Service groups are independent of environments: a Stripe group can contain production and development entries. These fields do not change secret uniqueness or the `.env` format.

Use macOS-backed encryption and store ciphertext locally. Keep plaintext out of logs, ordinary search, project summaries, and provider error messages. Reveal only through an explicit UI action. Provider credentials use the same storage protection.

Manual `.env` import previews names and collisions before saving; export requires selecting a destination and confirming any overwrite. Parsing never evaluates variables or commands. Import/export does not create ongoing synchronization with project files.

Record operation, secret identifier, and time for secret access, never the value. Keep project data out of telemetry by default.

### Project documentation and migration

Milestone 6 replaces the single Notes field with titled, ordered Markdown documents. Preserve existing notes during migration. Support direct editing, heading navigation, and copying code blocks. Documents such as Hosting, Deployment, Database, and Email hold operational knowledge without requiring a separate infrastructure model.

Search project and workspace content across documents, task titles/descriptions, links, and secret names/metadata. Results identify their project and content type and open the matching item. Never decrypt or index secret values for search.

Keep project knowledge in editable Markdown documents. Encrypted workspace backups remain the recovery mechanism for secrets and binary assets.

Store an optional project review date independently of edit, commit, and scan timestamps. Set it through an explicit review action. See [Milestone 6](docs/PLAN-06-project-knowledge.md) for migration safeguards and exit checks.

## Technical approach

Use one Laravel application with NativePHP Desktop, Eloquent, and SQLite in the app's persistent data directory. NativePHP Desktop v2 uses `nativephp/desktop` and the Electron runtime; SQLite is its built-in database path. Pin a mutually supported dependency set during the initial spike rather than guessing package versions now. [NativePHP installation](https://nativephp.com/docs/desktop/2/getting-started/installation), [database support](https://nativephp.com/docs/desktop/2/digging-deeper/databases).

Use Inertia/Vue and shadcn-vue for the interface, with Vue interactions for the future board. Use ordinary Laravel validation, transactions, HTTP clients, and process handling. Keep application operations small; avoid building a separate public REST API or generic integration framework.

For encryption, first inspect what the selected NativePHP version exposes. Where necessary, use a narrow authenticated bridge to Electron's macOS Keychain-backed `safeStorage`; verify against the Electron version actually bundled. Never distribute a shared secret-encryption key inside the app. [Electron safeStorage](https://www.electronjs.org/docs/latest/api/safe-storage), [NativePHP security](https://nativephp.com/docs/desktop/2/digging-deeper/security).

### Storage outline

| Records | Purpose |
| --- | --- |
| Projects | Identity, description, icon, status, archive marker, explicit review date |
| Project documents | Project, title, Markdown body, display order, revision, timestamps |
| Repositories and project folders | Remote identity, checkout paths, optional associations, scan metadata |
| Tags and project/tag associations | Filtering and organization |
| Board columns and tasks | Project-local ordering and task content |
| Links | Categorized project bookmarks |
| Secrets | Project, environment, name, ciphertext, optional service/category, description, management URL |
| Provider connections | Provider/account metadata and encrypted credentials |
| Access events | Bounded metadata-only secret-access history |

Store derived provider and dependency snapshots as replaceable cached data with source and refresh timestamps. Use database constraints for relationships and uniqueness. Include revisions on records subject to concurrent edits. No users, organizations, or tenant model is needed for independent local workspaces.

### Backup and release

Provide a manual portable backup containing project data, icons, and optionally secrets. Backups containing secrets use established password-based authenticated encryption; raw machine-bound ciphertext alone is not a portable recovery mechanism. Do not include source repositories. Reconnect provider accounts after restore.

Validate a backup before replacing any data, preserve the current workspace until restore succeeds, and offer folder relinking when paths differ. Test wrong passwords, corruption, interrupted restore, and restoration on a different macOS user profile. The portable encryption format and library are an explicit engineering choice for the spike, not a custom cryptographic design.

Distribute a signed, notarized macOS download. Proposed CPU targets are Apple Silicon and Intel, subject to the packaging spike; publish only the versions and architectures actually tested. Reuse NativePHP's updater. macOS automatic updates require a signed application. Test an upgrade from an earlier packaged build, including database migration and continued secret access. [NativePHP building](https://nativephp.com/docs/desktop/2/publishing/building), [updating](https://nativephp.com/docs/desktop/2/publishing/updating).

## Delivery sequence

These are implementation milestones, not promises of calendar duration. Estimate after the first milestone resolves packaging and native integration uncertainty.

| Milestone | Deliverable | Exit check |
| --- | --- | --- |
| 0. Prove desktop runtime | Packaged NativePHP app and persistent SQLite | Works from a packaged app on a second macOS profile; quit and restart work; runtime decisions recorded |
| 1. Catalog — complete | Projects, icons, descriptions, statuses, tags, links, multiple repos/folders | Passed in the desktop development app: create a multi-repository project, restart, filter it, and relink a missing folder without losing data |
| 2. Boards — complete | Per-project columns and tasks | Passed: ordering survives desktop restart; stale edits preserve the draft and show a conflict; failed writes roll back all moves |
| 3. Local inspection — complete | Git dates, manifest/lockfile parsing, selected executable probes | Passed: source/version regression matrix and packaged mixed-project demonstration, including interrupted-scan recovery |
| 4. Provider activity — complete for agreed GitHub scope | Provider connections and cached activity views | GitHub private access, pagination, offline restart, revoked credentials and controlled rate limits verified; GitLab and a second distinct account deferred |
| 5. Complete secrets and recovery | Environment secrets, import/export, portable backup/restore | Encrypted storage survives restart; backup restores on another profile; no plaintext in logs |
| [6. Project knowledge and migration](docs/PLAN-06-project-knowledge.md) — complete | Titled documents, grouped links and secrets, content search, bulk ideas, review dates, compatible backups | Passed in signed 0.6.0: populated upgrades, document maintenance/search/copy/review, 600 × 600 keyboard use, encrypted recovery, and restart/decryption. Original full profile returned unchanged; app/DMG verification passed |
| [7. Public beta](docs/PLAN-07-public-beta.md) | Signed distribution, updates, setup instructions, accessibility pass | Fresh install and upgrade pass on the published OS/CPU support matrix |

A first internal build can stop after milestone 2; the public v1 scope includes every confirmed feature through milestone 7.

Keep regression checks focused on data loss, authorization, parsing, ordering, and concurrent updates. Use small representative repositories and manifests to exercise multiple roots, malformed JSON, missing lockfiles, detached HEAD, and missing executables. Packaged-app checks are required for secret access, paths, and updating; browser-only tests cannot establish those behaviors.

## Remaining decisions

The plan is ready to refine, with these defaults still provisional:

1. Whether GitHub/GitLab access stays read-only or gains issue writes/synchronization.
2. Whether secrets need environments and `.env` import/export, and whether attachments are required.
3. Free launch, one-time purchase, or subscription; any paid choice needs a separate licensing and offline-use policy.
4. GitLab self-hosting/GitHub Enterprise demand, token-based onboarding versus OAuth, and launch CPU support.

The three largest implementation uncertainties are native encryption and packaging, accurate per-project runtime detection, and portable secret recovery. Resolve them early; the catalog and board should remain straightforward Laravel work.
