# Milestone 4 — GitHub and GitLab activity

Status: planned. Written 15 September 2026. Depends on completed local inspection. This document specifies future work; no provider connection is created by writing it.

Parent: [Product plan](../PLAN.md). Previous: [Local inspection](PLAN-03-local-inspection.md). Next: [Secrets and recovery](PLAN-05-secrets-recovery.md).

## Outcome

A developer connects a GitHub or GitLab account, associates existing repository records with accessible hosted repositories, and sees cached default-branch activity, open issues, pull/merge requests, and CI status. Offline use preserves the last successful data. Provider activity remains separate from the local task board.

The exit demonstration uses private repositories on both providers, multiple accounts, pagination, a revoked token, and a rate-limited response. Cached data survives restart; failures do not erase it or expose credentials.

## Scope and working defaults

These defaults carry forward the parent plan and remain product assumptions, not newly confirmed decisions:

- GitHub.com and GitLab.com only. Self-hosted GitLab and GitHub Enterprise are deferred.
- Manually supplied, user-owned provider tokens. No OAuth callback service or hosted Orbit backend.
- Read-only provider operations. No issue creation, comment posting, merge action, CI trigger, or task synchronization.
- Multiple saved connections, with an explicit connection selected for each repository association.
- Manual refresh, refresh on opening stale data, and limited refreshing while the project is visible.

Keep the existing Laravel/Inertia/Vue architecture and default shadcn-vue sidebar/components. Implement two small provider clients using Laravel's HTTP client; avoid a provider SDK, generic integration framework, or GraphQL layer unless a demonstrated requirement demands one. Recheck installed versions and endpoint requirements before implementation.

## Credential storage comes first

This milestone needs the encrypted-storage foundation before the full secrets UI in milestone 5.

The installed NativePHP 2.3.1 already exposes `System::canEncrypt()`, `System::encrypt()`, and `System::decrypt()`. Its Electron bridge calls `safeStorage.encryptString()` and `decryptString()`. Reuse those capabilities through one narrow application operation that throws a sanitized failure; do not build a custom bridge or encrypt credentials with a distributed Laravel application key.

Native encryption can be unavailable or fail. Treat a null result/bridge failure as failure, preserve the previous ciphertext, and disable saving a token if encryption cannot be established. A browser-only development session must never fall back to plaintext. macOS `safeStorage` depends on Keychain availability and is not a portable backup format. [Electron safeStorage](https://www.electronjs.org/docs/latest/api/safe-storage).

Token fields must bypass trimming only where byte preservation requires it, never be flashed back into a session, and never be placed in Inertia shared props, remembered form state, browser history, job payloads, URLs, exceptions, or request logs. Test redaction before accepting real credentials. Use a transient request for token entry/replacement and clear the frontend value after submission or cancellation.

Verify dummy-token encryption through a signed package and a normal restart before building live account flows. The earlier dummy encryption spike is useful evidence, but its removed diagnostic UI must not return.

## Data model

| Record | Proposed fields and ownership |
| --- | --- |
| `provider_connections` | UUID, provider enum, label, provider account ID/login, encrypted token, user-edit revision, safe status/error code, last verified time, timestamps |
| Repository association | On existing `repositories`: nullable connection FK, provider repository ID, canonical namespace/name, default branch, validated hosted URL, and association/request generation |
| `provider_snapshots` | Repository FK, resource kind and page key, normalized JSON payload, fetch/check/success times, ETag or Last-Modified where supported, next-page cursor/link, refresh state/error, generation |
| Access events | Minimal credential-use metadata using the same bounded event facility milestone 5 will extend; identifiers, operation, result category, and time only |

Keep records scoped through their project/repository/connection relationships. Never infer that an ID from the client is owned by the currently open project. A repository has one selected connection at a time; switching it invalidates pending jobs and clears private cached pages from the previous association.

Use explicit user-edit revision checks for connection and association edits. Derived fetches do not advance catalog/board edit revisions or overwrite dirty forms. A generation check prevents an old job from restoring data after a disconnect, token replacement, repository edit, or deletion.

Store only the normalized fields needed for display: IDs/numbers, titles, state, branch/SHA, timestamps, author display name where used, and validated web links. Do not cache full response bodies, patches, job logs, comments, or credential-bearing URLs.

## Connection workflow

1. Add Settings → Connections using the existing app shell.
2. Choose provider, enter a local label and token, and submit a transient validation request.
3. Establish native encryption, validate identity with the provider, and persist only the encrypted token and allowlisted account metadata. Show organization approval or missing-permission failures clearly.
4. Allow token replacement without revealing the old value. Verify the replacement before switching; a failed replacement retains the working credential.
5. Connect an existing repository from its Repositories section. Parse supported HTTPS/SSH remotes as a suggestion, then confirm the repository through the provider API. Use its stable provider ID after resolution so renames can be handled.
6. Permit replacing or disconnecting an association. Removing a connection removes its ciphertext and clears associated private snapshots, while leaving local repository/folder/task records intact. Explain that removing a token from Orbit does not revoke it at the provider.

Prefer GitHub fine-grained tokens with read access limited to selected repositories. Build an endpoint-to-permission table for contents/metadata, issues, pull requests, and the chosen CI endpoints, checking the live documentation. Do not request write permissions for convenience. [GitHub token permissions](https://docs.github.com/en/rest/authentication/permissions-required-for-fine-grained-personal-access-tokens).

For GitLab, start with a personal token using the documented `read_api` scope for API reads, and verify the chosen endpoints against it. Do not silently upgrade to `api` or assume `read_repository` covers issue and pipeline APIs. [GitLab token scopes](https://docs.gitlab.com/user/profile/personal_access_tokens/#personal-access-token-scopes).

## Provider reads and date semantics

Resolve repository identity/default branch before fetching activity. Pin and document a supported GitHub API version in the implementation instead of relying on an implicit server default. GitLab calls use its `/api/v4` API. [GitLab REST API](https://docs.gitlab.com/api/rest/).

| Display | GitHub starting point | GitLab starting point |
| --- | --- | --- |
| Identity/default branch | Repository metadata | Project metadata |
| Default-branch activity | Commits with explicit branch/SHA | Repository commits with explicit `ref_name` |
| Open issues | Repository issues; exclude entries representing pull requests | Project issues filtered to opened |
| Pull/merge requests | Open pull requests | Open merge requests |
| CI | Check runs/commit statuses for the displayed SHA; Actions details only if needed | Pipelines associated with the displayed ref and SHA |

On GitHub, issue responses can include pull requests; do not double-count them. Paginate far enough to supply the displayed issue page after filtering. [GitHub issues API](https://docs.github.com/en/rest/issues/issues).

Remote last commit means the tip commit on the provider's default branch. Use GitHub's nested commit committer date and GitLab's `committed_date`, preserving SHA, branch, and repository identity. Do not substitute account-linked author metadata, provider refresh time, or a pipeline's update time. [GitHub commit responses](https://docs.github.com/en/rest/commits/commits), [GitLab commits](https://docs.gitlab.com/api/commits/).

CI must name its associated commit/ref. A pipeline on another branch or an older commit cannot become “current HEAD passed.” Distinguish no CI, pending/running, success, failure, cancelled/skipped, and unavailable; an empty response is not success. Preserve provider-specific details behind the compact summary. [GitLab pipelines](https://docs.gitlab.com/api/pipelines/).

Extend the project's latest known commit summary to consider local checkouts and connected remote default branches. Keep source and freshness visible in detail. Disconnecting a repository removes that remote contribution; stale snapshots remain eligible only while labeled as last known data.

## HTTP, pagination, and refresh behavior

Use HTTPS with fixed API origins. Tokens go in the provider's documented authorization header, only to that provider. Validate pagination and redirect targets against the same origin and allowed API path; never forward credentials to an arbitrary URL returned in data. Display links use the existing safe opening flow and remain separate from credentialed API requests.

- Reuse milestone 3's NativePHP-managed worker lifecycle. Provider jobs serialize IDs and request generations, then decrypt at execution time.
- Serialize requests per connection to avoid bursts. Start with a 5-second connection timeout, 15-second request timeout, bounded response size, and a finite job budget. These are tunable implementation defaults, not promises about provider latency.
- Fetch an initial bounded activity page, with explicit next/previous or Load more controls. Preserve provider cursors/links and deduplicate by stable item identity. A limited list must not be labeled as an exact total.
- Fetch each resource independently so a CI permission failure does not blank issues or commits. Promote a page only after validation succeeds; do not splice partial failed pages into successful data.
- Save conditional request validators where supported. A `304` advances the successful check time while retaining content and its original data timestamps.
- Proposed schedule: refresh on open when older than five minutes; check once per minute while visible. Honor larger provider polling intervals and cooldowns. Pause automatic work while hidden/offline, and never refresh every project on launch.
- Honor `Retry-After` and provider reset headers. Do not loop through `401`, forbidden, or missing-resource errors. Stop automatic retries until reconnection or an appropriate cooldown.

GitHub recommends conditional reads, serialized requests, following pagination links, and respecting rate-limit delays. Use those rules with the fixed-origin boundary above. [GitHub REST best practices](https://docs.github.com/en/rest/using-the-rest-api/best-practices-for-using-the-rest-api).

## Failure and privacy states

| Condition | User-facing behavior |
| --- | --- |
| Offline/timeout/provider unavailable | Keep successful snapshot and timestamp; allow retry |
| `401`/expired or revoked token | Mark connection as needing a new token; stop automatic attempts |
| Forbidden/approval or scope missing | Explain access is unavailable; retain unaffected resources |
| Private repository returns `404` | Say unavailable or inaccessible; do not claim deletion is proven |
| Rate limit reached | Show next permitted retry time; disable immediate retry until then |
| Empty repository/default branch unavailable | Explicit empty state; no fabricated date or CI result |
| Malformed response, invalid URL, or oversized body | Reject that resource update; preserve previous successful data |
| Connection removed while fetch runs | Discard the result; never recreate the connection or its snapshot |

Do not load remote HTML into the app. Render titles and descriptions as escaped text; avoid remote avatars or tracking resources by default. No project files or dependency manifests are uploaded to either provider. API calls use only the connection and repository identifiers needed for the requested activity.

## Implementation sequence

1. **Credential foundation.** Reuse NativePHP encryption; add failure handling and redaction tests; prove it in the packaged app.
2. **Connections and associations.** Add schema, validation, revision guards, Settings UI, identity verification, token replacement, and disconnect cleanup.
3. **GitHub adapter.** Implement identity, commit, issue, pull-request, and CI reads against fixtures, including permission and pagination cases.
4. **GitLab adapter.** Implement the equivalent normalized outputs, preserving GitLab IDs, namespace encoding, and ref/commit semantics.
5. **Cache and jobs.** Add conditional reads, per-resource persistence, request generations, cooldowns, and visible-project refresh dispatch.
6. **Activity interface.** Add provider panels inside Repositories and source-aware project summaries; keep the board independent.
7. **Desktop verification.** With user-supplied test connections, verify private access, restart persistence, revocation, offline mode, and clean disconnect. Record actual results; do not mark live checks passed from mocks.

Likely areas: a small credential action, connection/snapshot models and migrations, two HTTP client classes, refresh jobs, a connections controller/settings page, repository association writes, current project Vue tabs, commit summary queries, and feature tests. Build only the Settings destinations that now exist.

## Verification and exit checks

Automated checks use Laravel HTTP fakes for every endpoint and reject stray requests. Exercise both providers with multiple pages, duplicate IDs, missing fields, inaccessible private repositories, empty branches, wrong-SHA CI, timezones, conditional responses, revoked credentials, per-resource failures, and rate limits. Use distinguishable dummy tokens and assert they never appear in rendered props, logs, sessions, queue payloads, or error text.

Test that connection/repository ownership is enforced; invalid or stale writes preserve working data; malicious redirect/page URLs receive no authorization header; and completing an old job after token replacement/disconnect has no effect. Re-run the catalog/board regression checks affected by project summary changes.

## Completion checklist

- [ ] Both providers show accurate default-branch activity, issues, pull/merge requests, and CI.
- [ ] Private repositories, multiple accounts, pagination, and partial permissions work.
- [ ] Tokens use native encryption and never enter ordinary app state or logs.
- [ ] Successful snapshots survive offline use and restart with honest freshness labels.
- [ ] Rate limits and failure cooldowns prevent request loops.
- [ ] Disconnect/relink/token replacement cannot be undone by an old job.
- [ ] A signed desktop build passes real credential/restart checks.
- [ ] Formatting, focused tests, production build, and verification evidence are recorded.

OAuth, enterprise hosts, remote writes, webhooks requiring a public server, cloning/fetching repositories, and synchronizing provider issues into boards remain outside this milestone. Milestone 5 reuses the credential foundation for project secrets and portable recovery.
