# To My Agents!

It is my fervent wish that this file guide every AI coding agent working with code in this repository.

## Documentation

Any distilled, agent-facing documentation for this package - how it works
internally and the rationale behind key design decisions - lives in `docs/`.
Consult it before non-trivial changes; it is the source of truth from which the
public manual is distilled.

This package is dense with non-local invariants and deliberate traps (the token
callback bug, the two-layer sandbox, Gmail MIME encoding, the Slides UTF-16 diff).
Read the relevant `docs/internals/` seam - `auth.md`, `mcp-plumbing.md`,
`managers.md` - before editing those areas.

## Project Overview

A PHP library wrapping Google APIs (Gmail, Calendar, Meet, Slides) with an
ergonomic layer over the official `google/apiclient`. It also ships an **MCP server**
(stdio, single-user) exposing Gmail, read-only Calendar, and Slides read/edit tools
as tools to Claude and other MCP clients.

- **PHP Version**: 8.2 - 8.5
- **Package**: `dg/google` (namespace `DG\Google`)

## Essential Commands

```bash
composer install

# Run the MCP server over stdio
php server.php                                       # uses ./demo/tokens
GOOGLE_TOKEN_DIR=/path/to/token-dir php server.php

# OAuth flow (needed once; re-run after adding a scope - tokens don't auto-upgrade)
php demo/authenticate.php

# Tests / static analysis (PHPStan level 8)
vendor/bin/tester tests -s
composer phpstan
```

**Server env vars** (the host passes these via `.mcp.json`): `GOOGLE_TOKEN_DIR`
(OAuth token dir), `GOOGLE_TOOLS` (which tools are exposed, e.g. `slides` or
`gmail:send, calendar:read`; see `ToolSelection`), `GOOGLE_FILES_DIR` (attachment sandbox).

## Conventions

- Every file starts with `declare(strict_types=1);`; namespace `DG\Google`; two blank
  lines between methods; constructor property promotion; Nette Coding Standard.
- **Exceptions:** `Gmail\Manager` throws `Gmail\Exception` (domain) /
  `\InvalidArgumentException` (bad caller input); `Authenticator` throws
  `AuthException` (re-auth states). Tool bodies do **not** catch their own errors -
  `McpToolCallGuard` converts everything centrally.
- **Classmap autoload gotcha:** after adding a new class file under `src/`, run
  `composer dump-autoload` or discovery won't find a new `#[McpTool]`.
- Every tool carries `#[Access(AccessLevel::…)]`; `send` means something reaches third
  parties. After adding/renaming a tool, update `tests/McpTools.discovery.phpt`. Tool
  names, descriptions, and JSON schemas all consume the agent's context - keep them tight.

## Working in this repo

- **The token-callback trap:** Google's *default* token callback drops the
  `refresh_token` after a transparent refresh, so a long-running process eventually
  401s "Invalid Credentials" until restart. The custom `setTokenCallback` carries
  `refresh_token`/`scope` forward and persists atomically. See `auth.md`.
- **The `Google\Client` is built lazily** so a missing `secret.json` surfaces as an
  `AuthException` at the first tool call, not a pre-handshake crash the host reads as
  "server died". `AuthException` -> a re-authorize `ToolCallException` via
  `ManagerResolver`.
- **`McpToolCallGuard` is the single error -> `ToolCallException` converter** and its
  first-match-wins order is a contract. It decorates the SDK's `ReferenceHandler`, so
  it also catches the SDK's own argument casting.
- **`GOOGLE_TOOLS` selects the exposed tools**; a disabled tool is **not registered at
  all** (absent from `tools/list`), send-level tools are never enabled implicitly, and a
  broken rule stops the server. Required OAuth scopes follow the enabled services. Read
  responses set `untrustedContent: true`. The attachment sandbox is flat with a
  `realpath` containment check. See `mcp-plumbing.md`.
- **Gmail MIME traps:** attachments capped at 18 MB; headers/bodies are forced to
  valid UTF-8 (or `json_encode` of the whole response breaks); reply-recipient logic
  differs when we sent the last message. See `managers.md`.
- **Slides `setShapeText` is a UTF-16 diff engine** that preserves inline formatting
  (trailing-newline reconciliation, non-BMP chars = 2 units); `revisionId` gives
  optimistic locking; `\v` soft breaks are decoded in the MCP layer only.
- **Single-user by design - never introduce per-user state into the server.**
- User-facing setup (Google Cloud OAuth client, `secret.json`, the `demo/` flow) is
  documented in the README / `demo/`, not here.
