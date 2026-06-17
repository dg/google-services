# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A PHP library wrapping Google APIs (Gmail, Calendar, Meet, Slides) with a more ergonomic API on top of the official `google/apiclient`. Also ships an **MCP server** (stdio, single-user) that exposes Gmail operations, read-only Calendar listing (`calendar_list_events`, `calendar_list_calendars`) and Google Slides read/edit tools (`slides_*`) as tools to Claude and other MCP clients.

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

**Slides\Manager (`src/Slides/Manager.php`)**
- Ergonomic wrapper over `Google\Service\Slides`. Every write op (add/duplicate/delete slide, insert/replace text) is a `presentations->batchUpdate` under the hood; the manager builds the `Request` objects so callers don't touch the raw batch protocol
- `getPresentation(id, fields?)` — raw fetch; pass a field mask to keep the payload small (the full presentation easily blows the MCP token budget)
- `addSlide(id, layout?, index?)` → object ID of the new slide; `layout` validated against `Manager::Layouts` (predefined layouts)
- `duplicateSlide(id, objectId)` → object ID of the copy; `deleteObject(id, objectId)` removes a slide or element
- `insertText(id, objectId, text, index=0)` — index is **UTF-16 code units** (emoji and other non-BMP chars count as 2), matching the Slides API
- `setShapeText(id, objectId, text, styles=[])` — overwrites a shape's whole text **preserving inline formatting where the text is unchanged**. Diffs old vs new (common prefix/suffix in UTF-16 units via `diffEdit`) and rewrites only the differing middle with one `deleteText`+`insertText`, so unchanged runs keep bold/color — unlike a delete-all+insert, which resets every run to the shape's default (the original formatting-loss bug). Text inserted in the changed region inherits the preceding char's style (Slides API behavior). Empty shape = clean insert; empty `text` = clear. Works on the trimmed body only — the API keeps one trailing newline and **rejects deleting it** ("end index … greater than existing text length"), so `stripTrailingNewline` excludes it from the diff. **Throws** when the object ID isn't a text-bearing shape. The optional `$styles` list (entries `['substring' => …, 'bold'|'italic'|'underline' => bool, 'fontSizePt' => float, 'color' => '#RRGGBB', 'occurrence' => int]`, only `substring` required) makes the result deterministic: it **first clears** bold/italic/underline on the freshly rewritten run (killing the inherited accent) and then applies exactly the listed styles by literal substring (like `styleText`); font size and color are NOT cleared (kept inheriting unless set). Text edit, accent reset and styling go out in **one atomic batch**. Returns a per-entry report (`[['substring' => …, 'occurrences' => int], …]`, empty when no styles) so a typo'd substring shows `occurrences 0`. The request planning is split into the pure, network-free `setShapeRequests` (unit-tested)
- `getElementRuns(id, objectId)` returns the shape's text split into styled runs (`null` when not found / not a text shape) — backs `slides_get_text_styles`
- `styleText(id, objectId, substring, bold?/italic?/underline?/fontSizePt?/foregroundColor?, occurrence?)` → occurrences styled. **Computes the UTF-16 range from the substring itself** by fetching the shape's current text, so callers never count code units by hand — the ergonomic replacement for ad-hoc "calc_bold.js"-style index math (handles non-BMP emoji and auto-text). Styles every occurrence by default; pass `occurrence` (0-based) for one. Style args nullable (null = leave unchanged); `foregroundColor` is hex `#RRGGBB`. Targets shapes only (not table cells)
- `replaceAllText(id, find, replace, matchCase=true)` → occurrences changed
- `batchUpdate(id, requests)` — public low-level escape hatch for ops the helpers don't cover (e.g. `updateSlideProperties` isSkipped to hide/show a slide, table styling)
- `getElementText(id, objectId)` returns a shape's raw text (incl. auto-text and trailing newline), **`''` when the shape exists but is empty, `null` only when not found** — searches every slide, descends into groups, and includes notes pages. `extractText(PageElement)` / `walkElements(PageElement[])` / `slideElements(Page)` (public static) are the shared traversal helpers used by both the manager and `McpTools`
- **One field mask, one source of truth:** `Manager::ContentFields` (composed from `self::TextMask`/`ShapeMask`/`LeafMask` consts) is used by both `getElementText` and `slides_get_presentation`. It includes `autoText(content)` so the reconstructed text matches the API's UTF-16 index space, plus one level of group children and the notes page

**Slides\McpTools (`src/Slides/McpTools.php`)**
- `slides_*` tools mirroring the Calendar `McpTools` pattern (lazy `getManager()` → `AuthException` to `ToolCallException`; other errors converted centrally by `McpToolCallGuard`). `slides_get_presentation` flags its response `untrustedContent: true` and returns only text content via `Manager::ContentFields` — never the full DTO graph. Each slide carries `slideNumber` (1-based), object ID, `elements[]` (object ID, `isTitle`, text; group children flattened in) and, with `includeNotes=true`, `notes[]` (object ID + text). The speaker-notes shape is taken from `notesPage.notesProperties.speakerNotesObjectId` (in `ContentFields`), so its object ID is returned **even when the notes are empty** (text `""`) — the old code filtered out empty-text elements and so hid them (the shape itself is present in `pageElements`, just empty). That ID is what you write into with `slides_insert_text` / `slides_set_shape_text` (both work on empty notes; `getElementText` returns `''` for them), so empty notes are addressable without the old "type a character first" workaround. **Response-size controls** (a big deck would otherwise blow the context window): `outline=true` omits all `text` (cheap object-ID/isTitle map), `slideNumbers` (1-based) scopes to specific slides; `slideCount` always reports the true total so the model knows what it's sampling
- Tools (9): `slides_get_presentation` (read-only), `slides_get_text_styles` (read-only — a shape's text split into styled runs, for inspecting current formatting before editing), `slides_add_slide`, `slides_duplicate_slide`, `slides_delete_object` (destructive), `slides_insert_text` (shapes only, append/splice), `slides_set_shape_text` (destructive overwrite of ONE shape, diff-based so it keeps unchanged inline formatting; optional `styles` list applies deterministic inline styling in the same atomic batch), `slides_format_text` (bold/italic/underline/size/color by substring — no manual index math, optional `occurrence`), `slides_replace_all_text` (destructive, deck-wide find&replace). **Naming is deliberate:** `set_shape_text` says "one shape" (vs deck-wide `replace_all_text`) and the original destructive `set_text` was renamed/rebuilt because its innocuous name lured the model into using it for small edits and losing formatting. Slides writes are NOT gated behind an opt-in flag (unlike `gmail_send_*`): they edit the user's own documents and are reversible, analogous to Gmail draft creation
- **Soft line breaks (`\v` escape):** the model emits the two-character ASCII sequence `\v` for a soft line break (U+000B, within-paragraph), and the write tools (`slides_insert_text`, `slides_set_shape_text`, `slides_replace_all_text`) expand it via `decodeSoftBreaks` before calling the manager. **Why:** a raw U+000B does not survive the MCP transport (host→server JSON-RPC) — verified that the lib + Slides API round-trip U+000B fine, so the loss is upstream of the server. Only `\v` is expanded (newline/tab survive natively; expanding `\n`/`\t` would corrupt code samples); a literal `\v` is written as `\\v` (the escape leaves `\n`/`\t`/lone `\\` untouched). The manager methods take real U+000B — decoding lives in the MCP layer, not the lib
- Text extraction across both `Manager` and `McpTools` walks Google's generated DTOs whose getters are typed non-nullable but return null at runtime; the `?->`/null guards are correct and the resulting PHPStan noise (`nullsafe.neverNull`, `nullCoalesce.expr`, `*.always*`, `return.unusedType`) is suppressed scoped to the two Slides files in `phpstan.neon`. `extractText` deliberately avoids an early return so the stub non-nullability doesn't make the table branch look like dead code
- **Known limitations** (worth a comment, not yet handled): `styleText` reaches shapes on slides, in groups, and on notes pages but **not table cells** (no `cellLocation`); `styleText` re-fetches the presentation each call (fine for single-user, a read-once/apply-many caller should use `batchUpdate` directly); only **one** level of group nesting is in the field mask

**Gmail\McpTools (`src/Gmail/McpTools.php`)**
- Defines Gmail MCP tools via `#[McpTool]` attributes from `mcp/sdk`
- All tools prefixed `gmail_*`; tool names, descriptions and JSON schemas land in Claude's context, so descriptions matter
- Read tools (`search_threads`, `get_thread`, `list_attachments`) flag responses with `untrustedContent: true` so the model treats third-party text (subject, sender, snippet, body, attachment filenames) as data, not instructions
- Write tools default to draft-first workflow (`create_draft*` + `send_draft` over one-shot `send_reply`)
- Outbound mail (`gmail_send_draft`, `gmail_send_reply`) is **opt-in** via `$allowSend` constructor flag (server wires this to env `GOOGLE_ALLOW_SEND`). When off, those two tools still appear in `tools/list` but immediately throw `ToolCallException` on call — no Gmail API request is made. The double-gate (host approval + server-side check) is intentional; a single class with one runtime flag was preferred over splitting tools into a separate file with discovery-side filtering, which only complicated the layout
- **Filesystem sandbox** for any tool that touches disk (`gmail_get_attachment`, `attachments[].path` in draft/send tools): one base directory configured at construction (`$filesDir` ← env `GOOGLE_FILES_DIR`). When unconfigured, those tools refuse with a clear error. The sandbox is **flat** — `attachments[].path` must be a plain filename (no subdirectories, no path separators, no `.`/`..`, no null byte); `resolveSandboxedPath` enforces that and adds a `realpath` containment check so a symlink in the sandbox pointing outside is also caught. `gmail_get_attachment` doesn't take a path at all — server names downloaded files `gm-<sha1(messageId.attachmentId)[0:12]>.<ext>` where the extension comes from the content's magic bytes (via `finfo`), making the name deterministic and free of any third-party-controlled string. The download tool is therefore idempotent (`idempotentHint: true`) and overwrites blindly — same input ⇒ same output, byte-identical content
- Tool bodies do NOT catch their own errors: the central `McpToolCallGuard` (see below) converts everything to `ToolCallException`. Methods just throw — `\InvalidArgumentException` for caller-supplied bad input, `Gmail\Exception` for domain errors — and the guard shapes the response. The two ToolCallExceptions raised directly in this class (`getManager` auth failure, `requireSendAllowed`/sandbox checks) pass through the guard unchanged
- **Calendar\McpTools (`src/Calendar/McpTools.php`)** — read-only Calendar tools (`calendar_list_events`, `calendar_list_calendars`), mirrors the Gmail `McpTools` pattern (lazy `getManager()` → `AuthException` to `ToolCallException`, `untrustedContent: true` on responses; everything else is converted centrally by `McpToolCallGuard`). Listing helpers (`getEvents`, `getCalendars`) live on `Calendar\Manager`. Meet MCP tools, when added, should live in `src/Meet/McpTools.php` next to its manager
- **Classmap autoload gotcha:** after adding a NEW class file under `src/`, run `composer dump-autoload`. The package uses classmap autoloading, so `setDiscovery` (which reflects via the autoloader) silently won't find a new `#[McpTool]` until the classmap is regenerated. `setDiscovery` scans `src/` recursively, so no server.php change beyond container registration + scope

**McpToolCallGuard (`src/McpToolCallGuard.php`)**
- Single place that converts any failure escaping a tool body into a `ToolCallException` (the MCP SDK renders that as a `CallToolResult` with `isError=true` so the model can self-correct). Implements `ReferenceHandlerInterface` and decorates the SDK's default `ReferenceHandler`; wired in via `Server\Builder::setReferenceHandler`. Replaces the former per-class `safe()` wrapper that every tool method had to call. Conversion rules (first match wins): an already-shaped `ToolCallException` is rethrown as-is; `Google\Service\Exception` becomes `Google API error: <upstream message>`; `\InvalidArgumentException`/`Nette\Utils\AssertionException` forward their clean message; any other `\Throwable` (domain `Exception`, `TypeError`, "method on null", a bare `\RuntimeException`) is wrapped with class + `file:line` so genuine bugs surface with context instead of crashing the stdio transport as an opaque JSON-RPC -32603. Because the guard wraps the SDK's invocation, it also catches failures from the SDK's own argument casting, which the old in-method `safe()` could not

**DTOs (`src/Gmail/Message.php`, `Thread.php`, `Recipient.php`)**
- Returned by `Gmail\Manager`. `Message` carries headers, plaintext + HTML bodies, label IDs, and attachment metadata (`attachmentId`, `filename`, `mimeType`, `sizeBytes`)

**Gmail\Exception (`src/Gmail/Exception.php`)**
- Domain-level error type for `Gmail\Manager` (oversize attachments, empty thread, malformed payload from Gmail). Extends `\RuntimeException`; converted to `ToolCallException` by `McpToolCallGuard`'s `\Throwable` catch-all (with class + `file:line` context) so the model sees the failure rather than the transport crashing. In `Manager`, `Google\Service\Exception` is aliased to `GoogleException` to free the short name `Exception` for this class

### MCP Server

**`server.php`** — entry point invoked over stdio.
- Configured via env vars (the `.mcp.json` host gives them to the process):
  - `GOOGLE_TOKEN_DIR` — path to the OAuth token directory (default `__DIR__/demo/tokens`)
  - `GOOGLE_ALLOW_SEND` — set to `"1"` to wire `McpTools` with `$allowSend = true`. Anything else means outbound `gmail_send_*` tools throw on call.
  - `GOOGLE_FILES_DIR` — path to the attachment sandbox. Required for `gmail_get_attachment` and for any draft/send call that uses `attachments[]`; absent ⇒ those tools throw on call.
- Authenticates via `Authenticator` with scopes `Gmail::GMAIL_MODIFY`, `Calendar::CALENDAR_READONLY`, `Slides::PRESENTATIONS` (extend the scope list to add Meet tools later — adding a scope requires re-running `php demo/authenticate.php`, the stored token does not pick up new scopes automatically)
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
- Exception handling: in `Gmail\Manager`, throw `Gmail\Exception` for domain errors and `\InvalidArgumentException` for caller-supplied bad input; in `Authenticator`, throw `AuthException` for re-auth states. In `McpTools`, `AuthException` is converted to `ToolCallException` in `getManager`; everything else a tool throws is converted centrally by `McpToolCallGuard` (input errors get a clean message, other `\Throwable`s get class + `file:line`). Tool methods do not wrap their own bodies

## MCP-specific guidance when editing tools

- Tool names, descriptions, and JSON schemas all consume Claude context — keep descriptions tight but explicit about side effects, formats, and ordering with sibling tools
- After adding or renaming a tool, update `tests/McpTools.discovery.phpt`
- Do not introduce per-user state into the server; it is single-user by design
