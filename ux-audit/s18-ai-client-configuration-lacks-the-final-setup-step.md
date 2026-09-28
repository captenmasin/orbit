# S18 — AI client configuration lacks the final setup step

Priority: P3

Area: settings

Status: Implemented

Evidence: Live + source — Settings.vue:123,147; SettingsController.php:58

Audit: [Figma finding](https://www.figma.com/design/fUo6BlJ5s4obNpmJ6SPevJ?node-id=3-421)

## Problem

Users can copy MCP JSON but are not told where it belongs, which clients support it or how to confirm connection. Copying a configuration is only one step; users need a concrete destination and an observable way to verify the local connection.

## Implementation plan

1. In the MCP card in `resources/js/pages/Settings.vue`, add short instructions: open a compatible AI client's MCP/server settings, add the local server using the shown command, arguments and environment, then reload its server connection.
2. Verify at least one named client's current official local-MCP setup guide before linking it. Describe that guide's actual configuration destination and avoid presenting Orbit's JSON as universally interchangeable across clients.
3. Explain a minimal confirmation step using a read-only workspace/project request. Check the available capability names in `app/Mcp/Servers/OrbitServer.php` and `app/Mcp/Tools/ReadWorkspaceTool.php`.
4. Keep the existing Copy configuration action, selectable JSON and copy-failure feedback. Explain that setup belongs to the separate AI client; keep its distinction from Scratchpad AI visible.
5. Review the helper next to the code block at desktop/narrow widths. Preserve native-only rendering and the current restriction on revealing secret values.

## Acceptance criteria

- [x] Instructions identify where the copied configuration belongs.
- [x] Any named-client guide matches its current supported local-server setup.
- [x] Users have a concrete read-only connection check.
- [x] Scratchpad AI and external-client setup remain distinguishable.

## Verification

Follow the verified named-client guide with the shown configuration in a local test setup. Confirm a read-only workspace request works, then simulate a missing executable/path and verify the troubleshooting direction is understandable. Inspect copy success and failure.

No automated application tests are required for this guidance-only change; manually verify the linked client's setup before publishing.

## Related plans

None.


## Completion — 28 September 2026

The external-client guide specifies a project-root .mcp.json, restart/approval and a read-only project-list connection check. Current official Claude Code MCP guidance was checked.

Checks: tests/Feature/OrbitMcpTest.php; server capability/source review; official client documentation. These checks passed in the full suites; production build and lint of changed Vue files also passed.

Limit: native history, file pickers/keychain/clipboard, external-client setup and desktop-only execution were covered where applicable by automated boundaries and source review; an installed desktop smoke test remains manual.
