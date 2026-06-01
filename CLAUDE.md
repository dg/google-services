# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A PHP library wrapping Google APIs (Gmail, Calendar, Meet) with a more ergonomic API on top of the official `google/apiclient`. Also ships a **Gmail MCP server** (stdio, single-user) that exposes Gmail operations as tools to Claude and other MCP clients.

## Architecture

### Core Components

**Authenticator (`src/Authenticator.php`)**
- Manages OAuth2 authentication flow with Google APIs
- Handles token storage and refresh in `demo/tokens/` directory
- Requires `secret.json` (OAuth2 credentials from Google Cloud Console)
- Stores `token.json` (access and refresh tokens)
- Automatically refreshes expired tokens; token writes are atomic (tmp file + `rename`) so a crash mid-write cannot corrupt `token.json`
- Throws `AuthException` (not bare `RuntimeException`) for any "user must re-authorize" state — missing/malformed token, refresh transport failure, refresh token revoked
- On refresh failure, only `invalid_grant` purges the stored token; transport errors keep it so the user can retry

**AuthException (`src/AuthException.php`)**
- Distinct exception type for recoverable re-auth states; `Gmail\McpTools::getManager` catches it and converts to a self-correctable tool error with a "re-authorize" hint instead of crashing the MCP transport

**Gmail\Manager (`src/Gmail/Manager.php`)**
- Main API for Gmail operations (search, threads, drafts, send, labels, attachments)
- Works against the authenticated user (`me`)
- MIME building delegated to `Nette\Mail\Message` (handles RFC 2047 encoding, line folding, CRLF sanitation)
- Reply header derivation: subject / To / Cc / In-Reply-To / References pulled from the last thread message via a metadata-only fetch; the user's own address is stripped from Cc
- Total raw attachment size capped at 18 MB (Gmail's 25 MB encoded-message limit minus base64 overhead) — enforced in `createMessage`/`createReplyMessage` (via the `MaxAttachmentBytes` constant), so library callers are protected even if they bypass `McpTools::validateAttachments`
- Attachment downloads via `users.messages.attachments.get`; MIME types come straight from the Gmail payload

**Calendar\Manager (`src/Calendar/Manager.php`)**
- Main API for calendar operations (create events, manage attendees)
- Works with either primary calendar or specified calendar ID
- Supports creating Google Meet meetings automatically
- Handles recurring events with daily frequency and intervals
- Email validation and normalization for attendees

**Calendar\Event (`src/Calendar/Event.php`)**
- Data transfer object for event creation
- Supports optional location, description, reminders
- Recurring events via `repeatCount` and `repeatIntervalDays`
- Optional Google Meet creation via `createMeeting` flag

**Meet\Manager (`src/Meet/Manager.php`)**
- Creates standalone Google Meet spaces not linked to calendar events
- Wraps `Google\Service\Meet` API

**Gmail\McpTools (`src/Gmail/McpTools.php`)**
- Defines Gmail MCP tools via `#[McpTool]` attributes from `mcp/sdk`
- All tools prefixed `gmail_*`; tool names, descriptions and JSON schemas land in Claude's context, so descriptions matter
- Read tools (`search_threads`, `get_thread`, `list_attachments`) flag responses with `untrustedContent: true` so the model treats third-party text (subject, sender, snippet, body, attachment filenames) as data, not instructions
- Write tools default to draft-first workflow (`create_draft*` + `send_draft` over one-shot `send_reply`)
- Outbound mail (`gmail_send_draft`, `gmail_send_reply`) is **opt-in** via `$allowSend` constructor flag (server wires this to env `GOOGLE_ALLOW_SEND`). When off, those two tools still appear in `tools/list` but immediately throw `ToolCallException` on call — no Gmail API request is made. The double-gate (host approval + server-side check) is intentional; a single class with one runtime flag was preferred over splitting tools into a separate file with discovery-side filtering, which only complicated the layout
- **Filesystem sandbox** for any tool that touches disk (`gmail_get_attachment`, `attachments[].path` in draft/send tools): one base directory configured at construction (`$filesDir` ← env `GOOGLE_FILES_DIR`). When unconfigured, those tools refuse with a clear error. The sandbox is **flat** — `attachments[].path` must be a plain filename (no subdirectories, no path separators, no `.`/`..`, no null byte); `resolveSandboxedPath` enforces that and adds a `realpath` containment check so a symlink in the sandbox pointing outside is also caught. `gmail_get_attachment` doesn't take a path at all — server names downloaded files `gm-<sha1(messageId.attachmentId)[0:12]>.<ext>` where the extension comes from the content's magic bytes (via `finfo`), making the name deterministic and free of any third-party-controlled string. The download tool is therefore idempotent (`idempotentHint: true`) and overwrites blindly — same input ⇒ same output, byte-identical content
- `safe()` wraps every tool body. Google API errors convert to `ToolCallException`. Domain errors from `Gmail\Manager` (`Gmail\Exception`), input errors (`InvalidArgumentException`, `Nette\Utils\AssertionException`) and filesystem errors (`Nette\IOException`) are converted too. **Bare `\RuntimeException` is NOT caught** — that's reserved for genuine bugs and should crash, not be reported as a self-correctable tool error
- Calendar / Meet MCP tools, when added, should live in `src/Calendar/McpTools.php` / `src/Meet/McpTools.php` next to their respective managers; `setDiscovery` scans `src/` recursively, so no server.php wiring change beyond container registration

**DTOs (`src/Gmail/Message.php`, `Thread.php`, `Recipient.php`)**
- Returned by `Gmail\Manager`. `Message` carries headers, plaintext + HTML bodies, label IDs, and attachment metadata (`attachmentId`, `filename`, `mimeType`, `sizeBytes`)

**Gmail\Exception (`src/Gmail/Exception.php`)**
- Domain-level error type for `Gmail\Manager` (oversize attachments, empty thread, malformed payload from Gmail). Extends `\RuntimeException` but is caught explicitly by `McpTools::safe()` so genuine library bugs don't get hidden under the same handler. In `Manager` and `McpTools`, `Google\Service\Exception` is aliased to `GoogleException` to free the short name `Exception` for this class

### MCP Server

**`server.php`** — entry point invoked over stdio.
- Configured via env vars (the `.mcp.json` host gives them to the process):
  - `GOOGLE_TOKEN_DIR` — path to the OAuth token directory (default `__DIR__/demo/tokens`)
  - `GOOGLE_ALLOW_SEND` — set to `"1"` to wire `McpTools` with `$allowSend = true`. Anything else means outbound `gmail_send_*` tools throw on call.
  - `GOOGLE_FILES_DIR` — path to the attachment sandbox. Required for `gmail_get_attachment` and for any draft/send call that uses `attachments[]`; absent ⇒ those tools throw on call.
- Authenticates via `Authenticator` with `Gmail::GMAIL_MODIFY` scope (extend the scope list to add Calendar/Meet tools later — token will need re-auth)
- Discovers tools by scanning `src/` for `#[McpTool]` attributes via `setDiscovery`
- Provides server-level `instructions` covering: untrusted-content warning (the `untrustedContent: true` flag), current send-enabled state, default draft workflow, and tool ordering hints
- Single-user / personal scope: tokens live on the local filesystem; not suitable for shared / multi-user deployment without rewriting the auth layer

**`demo/.mcp.json`** — example host configuration. Drop `GOOGLE_ALLOW_SEND` (or set it to anything other than `"1"`) for read-only / draft-only mode.

### OAuth2 Flow

1. User runs `demo/authenticate.php` → redirects to Google authorization
2. Google redirects to `demo/oauth2callback.php` with authorization code
3. Callback exchanges code for access token and saves to `demo/tokens/token.json`
4. Subsequent requests use stored token, auto-refreshing when expired

### Demo Structure

The `demo/` directory contains working examples:
- `bootstrap.php` - Shared setup with `createAuthenticator()` and `googleAuthenticate()` helpers
- `authenticate.php` - Initiates OAuth2 flow
- `oauth2callback.php` - Handles OAuth2 callback and token exchange
- `list-calendars.php` - Example of listing calendars using authenticated client
- `tokens/` - Directory for OAuth2 credentials and tokens (gitignored)

## Setup Requirements

1. Create OAuth 2.0 Client in [Google Cloud Console](https://console.cloud.google.com/apis/credentials)
2. Add authorized redirect URI pointing to `oauth2callback.php`
3. Download OAuth2 credentials and save as `demo/tokens/secret.json`
4. Run `demo/authenticate.php` to obtain access token

## Dependencies

Exact version constraints live in `composer.json`; this list is about what each package is for.

- PHP 8.2 - 8.5
- `google/apiclient` - Official Google API client library
- `mcp/sdk` - PHP MCP SDK (server, transport, attribute-based tool discovery)
- `nette/mail` - MIME message building (RFC 2047 encoding, line folding, CRLF sanitation)
- `nette/utils` - filesystem helpers, AssertionException
- `symfony/finder` - used by tool discovery
- Autoloading via classmap of `src/` directory

### Dev Dependencies

- `phpstan/phpstan` + `phpstan/extension-installer` - Static analysis (level 8)
- `nette/phpstan-rules` - Additional PHPStan rules
- `nette/tester` - Test runner

## Common Tasks

```bash
# Install dependencies
composer install

# Update dependencies
composer update

# Run demo authentication flow
php demo/authenticate.php

# Run the Gmail MCP server (stdio)
php server.php                          # uses ./demo/tokens
GOOGLE_TOKEN_DIR=/path/to/token-dir php server.php   # custom token directory

# Run tests
vendor/bin/tester tests -s

# Run static analysis
composer phpstan
```

## Code Style

- All PHP files use `declare(strict_types=1)`
- Namespace: `DG\Google`
- Two empty lines between methods
- Constructor property promotion used throughout
- Exception handling: in `Gmail\Manager`, throw `Gmail\Exception` for domain errors and `\InvalidArgumentException` for caller-supplied bad input; in `Authenticator`, throw `AuthException` for re-auth states. In `McpTools`, all three are converted to `ToolCallException` (`Gmail\Exception` and input errors via `safe()`; `AuthException` via `getManager`). Never catch a bare `\RuntimeException` — let bugs surface

## MCP-specific guidance when editing tools

- Tool names, descriptions, and JSON schemas all consume Claude context — keep descriptions tight but explicit about side effects, formats, and ordering with sibling tools
- After adding or renaming a tool, update `tests/McpTools.discovery.phpt`
- Do not introduce per-user state into the server; it is single-user by design
