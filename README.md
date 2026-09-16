# Orbit

A local macOS project workspace built with Laravel, NativePHP, Inertia 3, Vue 3 and shadcn-vue.

The project catalog uses the [shadcn-vue sidebar-07 layout](https://www.shadcn-vue.com/blocks#sidebar-07). Projects have descriptions, statuses, tags, initials/emoji/image icons, multiple repositories and folders, and ordered categorized links. Create manually or review metadata from a native folder picker. Search, filter, sort by name or last known local commit, archive/restore, relink missing folders, and remove projects without touching source files. Saves and removals reject stale revisions.

Each project's Board tab starts with Backlog, To Do, In Progress, and Done. Add, rename, reorder, and delete columns; create and edit tasks with optional descriptions. Drag cards and column handles, or use the Move dialog and column arrow buttons with the keyboard. Deleting a populated column moves its tasks to a selected destination. Board changes save immediately and atomically, using the same revision protection as the catalog.

## Run

Requires macOS, PHP 8.5 with SQLite and ZIP, Composer, and Node 22+.

```sh
composer setup
composer dev
```

For this already-installed checkout, `composer dev` is sufficient. This starts Vite and NativePHP together. The Vue screens use unmodified shadcn-vue components with the classic Vega style, neutral theme, and locally bundled Inter font. The shared sidebar stays mounted across Inertia visits and becomes a sheet in narrow windows. Laravel owns validation and persistence; Inertia handles page visits and form submissions. No separate API, client router or SSR process is needed.

Native development uses `database/nativephp.sqlite`. Packaged builds use `~/Library/Application Support/orbit/database/database.sqlite`. Browser development (`php artisan serve --host=127.0.0.1`) uses the separate `database/database.sqlite`.

## Verify

```sh
composer test
npm test
npm run build
```

Tests cover atomic catalog and board saves, task/column ordering, rollback after a failed write, stale revisions, search/filter/sort and pagination, folder metadata and relinking, repository ownership, link ordering and URL validation, image handling, archive/removal safety, Inertia error bags, and native cache isolation. `npm test` checks NativePHP's quit handler. `npm run build` checks the Vue/TypeScript code before producing the frontend assets. `npm run typecheck` runs just the type check. Migrations preserve existing project data and add default board columns to existing projects.

Folder selection reads `composer.json`, `package.json`, and local Git metadata; it does not execute project scripts. Commit dates are snapshots taken when linking or relinking a folder. Ongoing Git inspection and provider activity belong to later milestones. Images are stored privately in Orbit's persistent storage and served through project routes.

## Package locally

Stop the development app before building: both use NativePHP's Electron output directory.

```sh
php artisan native:build mac arm64 --no-interaction
```

NativePHP builds the frontend automatically before packaging. The app appears at `nativephp/electron/dist/mac-arm64/Orbit.app`. NativePHP may use an available Developer ID signing identity. Notarization needs separately configured Apple credentials. Updates remain disabled. Bump `NATIVEPHP_APP_VERSION` for each build with migrations; NativePHP checks the app version before migrating an existing workspace.

The source version is 0.3.0 for the board migration. The last verified package is 0.1.1 and predates the completed catalog and boards. `bootstrap/patch-nativephp.php` applies a small NativePHP 2.3.1 quit fix during Composer installation and before packaging, deferring the quit retry until the cancelled event finishes. It patches the source and compiled plugin, requires the expected handler, and can be removed when the upstream fix is adopted. Verification results are recorded in [the integration record](docs/desktop-integration.md). See [PLAN.md](PLAN.md) for the remaining product work.
