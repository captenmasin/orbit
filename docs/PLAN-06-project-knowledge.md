# Milestone 6 — Project knowledge and migration

Status: **complete** in the fourth signed Apple Silicon 0.6.0 build, 22 September 2026. Packaged acceptance passed for document maintenance and search, exact code copying, encrypted recovery, 600 × 600 keyboard use, and normal Quit/relaunch. The original full profile was returned and verified unchanged. Notarization, Intel, and second-macOS-profile verification remain separate release checks. Builds on the catalog, boards, project details, and milestone 5 secrets/recovery.

Parent: [Product plan](../PLAN.md). Previous: [Secrets and recovery](PLAN-05-secrets-recovery.md). Next: [Public beta](PLAN-07-public-beta.md).

## Implementation and verification — 22 September 2026

The four workstreams are implemented in the current checkout:

- Project-owned documents support create, rename, edit, preview, keyboard reordering, and deletion. Existing Notes text migrates without alteration into a Notes document; the old editor field is removed. Project and document revisions reject stale writes while the editor retains its draft. The shared safe Markdown view adds heading navigation and code-copy controls. Review dates change only through Mark reviewed.
- Link categories render as ordered groups, including uncategorized entries. Secrets support service/category, description, and a validated management URL. Service and environment filters combine, and context can be edited without decrypting or replacing the value.
- Bounded local queries search project identity/tags, documents, tasks, links, and secret names/context. Results target the matching record and show removed-item states. Secret values and provider credentials are excluded from search; no separate index is maintained.
- Bulk ideas use a separate board preview.

Workspace backups now write schema 2 and restore schemas 1 and 2. Documents, positions, revisions, review dates, and secret context round-trip; older Notes content uses the document compatibility path. Regression work also corrected the backup UI completion handling and validation of orphan asset data during restore.

Final verification:

| Evidence | Observed result |
| --- | --- |
| Full automated suite | 166 PHP tests / 1,726 assertions and 21 JavaScript tests passed |
| Static/build checks | Vue type checking, production build, Pint, and diff checks passed |
| Populated web and native development database migrations | Existing rows preserved, Notes copied exactly, SQLite integrity `ok`, zero foreign-key violations |
| Pre-migration snapshots | Web, native development, and packaged databases saved under `/var/folders/8d/c15fpr252wb99pxjr7gn45440000gn/T/orbit-milestone6-lacfqvhd` |
| Initial signed 0.6.0 package | Original workspace fields preserved; project knowledge checks covered documents, grouped links, bulk ideas, and secret context |
| Packaged interactions | Direct document edit/reorder, exact fenced-code copy, Redis search to Database, explicit review dates, and secret context editing passed |
| Packaged restart | Every isolated database row survived normal Quit/relaunch; native dummy-secret reveal succeeded |
| Minimum-window keyboard use | At 600 × 600, document actions, search results, and the context dialog were usable |
| Encrypted recovery | 9,061-byte export, preview, and native apply passed in the fourth build; metadata/documents/order/tasks/repositories/links/context preserved, with empty asset arrays normalized to `[]`; private rollback snapshot mode 0600 and integrity `ok` |
| Post-restore restart | Every fixture database row including ciphertext matched after normal Quit/relaunch; native Reveal returned the dummy value; final quit left no Orbit runtime processes |
| Original profile and final package | Original full profile returned; three projects and every common original field/ciphertext matched the pre-migration snapshot, integrity `ok`, no foreign-key errors; strict app signature and final DMG verification passed |

Automated coverage includes document ownership/order/conflicts, draft retention, code-copy text, review dates, secret context filtering, search disclosure, backward-compatible recovery, and snapshots of the active native database. The observed packaged journey is recorded in [the desktop integration record](desktop-integration.md#project-knowledge-and-migration--060-complete-22-september-2026). Acceptance found and fixed a rollback snapshot lookup using the wrong database connection. The fix has focused regression evidence and passed packaged verification. The original full workspace was returned intact, and the isolated acceptance profile is retained under the snapshot directory. Milestone 6 is complete; notarization, Intel validation, and a real second macOS profile remain separate public-beta/release work.

## Outcome

A developer can maintain operational documentation in Orbit, navigate grouped links and credentials, find a specific detail, and capture an ideas list.

The reference is the Sitepulse project note: multiple repositories, V2 ideas, service dashboards, hosting details, deployment commands, database tuning, domain ownership, email routing, browser-renderer setup, credentials, and a last-reviewed date. Use a sanitized equivalent with dummy values for fixtures and demonstrations; do not copy the original credentials into this repository.

## Existing foundation

Before this milestone, the checkout supported project status/tags, multiple repositories and folders, task boards with Markdown descriptions, one Markdown Notes field, categorized ordered links, project assets, environment-scoped encrypted secrets, `.env` paste/import/export, and workspace backup/restore. The implementation extends those features; packaged evidence remains separate from source and automated verification.

The milestone addresses organization and retrieval. Infrastructure can initially live in documents such as Hosting, Deployment, Database, Email, and Browser Renderer. Keep the existing local workspace, Laravel/SQLite, Inertia/Vue, NativePHP, and default shadcn components.

## Workstream 1 — Project documents and review dates

- Replace the single Notes field with project-owned, titled, ordered Markdown documents. Support create, rename, edit, reorder, and delete, using the existing revision/conflict protection and safe Markdown renderer.
- Migrate each nonempty Notes value into a Notes document without changing its text. Preserve notes restored from older backups through the same compatibility path; avoid two independently editable copies.
- Edit a document directly from its view. Provide heading navigation and copy buttons for fenced code blocks, preserving exact command text and line breaks. Commands are documentation and are never executed by viewing or copying them.
- Keep document navigation and actions usable with the keyboard and at the supported minimum window size. Reuse existing rendering and components before adding dependencies.
- Add an optional project-level review date with an explicit Mark reviewed action. Show an unreviewed state when absent. Normal edits, Git activity, and scans must not update it.

Suggested storage is a project-document record with title, Markdown body, position, revision, and timestamps, plus a review date on the project. Apply existing project ownership, stale-write, removal, and backup conventions. Document history and a separate infrastructure/service model are outside this milestone.

## Workstream 2 — Link groups and secret context

Display existing link categories as headings rather than only badges in one flat list. Keep custom categories, preserve saved ordering within each group, derive group order from the existing ordered list, and provide a group for uncategorized links. Existing links and URLs require no migration beyond presentation.

Add optional service/category, description, and management URL metadata to project secrets. Service grouping is independent of environment: Stripe, Cloudflare, or Slack can each contain entries from several environments. Support editing metadata without revealing or replacing the encrypted value, and let users combine service and environment filters.

Keep project/environment/name uniqueness and existing `.env` names/values unchanged. Group labels are organizational metadata, not an additional secret namespace. Existing secrets remain valid with empty optional fields. Validate management URLs through the existing safe opening rules. Descriptions are non-secret context and should be labeled accordingly.

## Workstream 3 — Content search

Extend search to work within a project and across the workspace. Include project identity/tags, document titles and bodies, task titles and descriptions, link labels/categories/URLs, and secret names plus non-secret metadata. Do not decrypt, index, or return secret values or provider credentials.

Results show the project, content type, title, and a short relevant excerpt where appropriate. Selecting a result opens the specific document, task, link, or secret metadata entry, including the correct project section. Handle removed results without navigating to an unrelated item.

Use local database queries with bounded results and existing filtering conventions first. Search updates after edits, deletions, and restore. An external search service or a separate indexing pipeline is unnecessary for this scope.

## Workstream 4 — Bulk ideas

Provide a small bulk-task preview on the board: paste a list, review one title per selected item, choose a column, and create cards in source order in one atomic save.

## Persistence and compatibility

Include documents, ordering, review dates, and new secret metadata in workspace backup/restore. Accept older supported backups with sensible defaults, preserve their Notes content, and validate ownership and URLs before applying new records. Update the backup format/version only if its existing compatibility rules require it.

Existing projects, boards, repositories, assets, links, secrets, and provider connections must survive the migration. Preserve exact secret values and existing native encryption behavior. Keep source folders untouched. Check normal Quit/relaunch and migration from a populated packaged build before declaring the milestone complete.

## Delivery sequence

1. Add documents, migrate existing notes, and include the new data in backup/restore. Add direct editing, heading navigation, code copying, and explicit review dates.
2. Display existing link categories as groups and add optional secret context with independent service/environment filtering.
3. Add project/workspace content search and direct navigation to matching items.
4. Add bulk task creation.
5. Run focused regressions and the packaged acceptance journey below; record observed results in the existing integration record and update the parent milestone status.

## Acceptance checks

| Scenario | Required result |
| --- | --- |
| Existing workspace upgrade | Notes become documents without text loss; unrelated project data and secret access remain intact |
| Operational documentation | Hosting, deployment, database, and email documents can be edited, reordered, navigated, and copied accurately; stale writes preserve the draft |
| Link groups | Existing category labels become visible groups; uncategorized links and ordering remain usable |
| Secret context | Service and environment filters combine correctly; metadata editing does not require plaintext access or change `.env` output |
| Search | Terms such as Redis, renderer, and deploy find the right document/task/link; secret names are searchable and dummy secret values never appear |
| Review dates | Explicit review actions persist; edits and background scans do not claim a new review |
| Bulk ideas | Reviewed items become cards once, in order, in the selected column; cancel makes no changes |
| Recovery and restart | New fields and documents survive backup/restore and normal Quit/relaunch; older supported backups still restore |
| Keyboard and small window | Documents, groups, and search results can be used without dragging or clipped actions |

Use focused regression coverage for migration, ownership, ordering, conflicts, search disclosure, and backup compatibility. Use dummy values throughout. Code copying and restart require desktop checks; run the relevant automated tests, PHP formatting when PHP changes, and the frontend build.

The recorded project-knowledge acceptance completes milestone 6. [Milestone 7 — Public beta](PLAN-07-public-beta.md) retains its separate distribution and release checks.
