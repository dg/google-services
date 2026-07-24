# dg/google-services

Ergonomic PHP wrappers over the official `google/apiclient` for **Gmail, Calendar, Meet and Google Slides**, plus a single-user **MCP server** (stdio) that exposes those operations as tools to Claude and other MCP clients.

- **Gmail** – search threads, read bodies (with charset/RFC 2047 decoding and HTML→text), draft / update / send / reply, labels, attachments (sandboxed), archive, trash.
- **Calendar** – the library creates events (with Meet links, recurrence, reminders, attendees); the MCP server exposes read-only listing of events and calendars.
- **Meet** – create standalone meeting spaces.
- **Slides** – read a deck, edit text while preserving inline formatting, style runs, add/duplicate/move/hide slides, and add text boxes.

Requires PHP 8.2–8.5. See `CLAUDE.md` for the detailed architecture notes.

## Installation

```bash
composer install
```

## OAuth setup (one-time)

1. In the [Google Cloud Console](https://console.cloud.google.com/apis/credentials) create an **OAuth 2.0 Client** (type "Web application").
2. Add an authorized redirect URI pointing at `demo/oauth2callback.php` (e.g. `http://localhost/oauth2callback.php`).
3. Download the client credentials and save them as `demo/tokens/secret.json`.
4. Run the authorization flow and grant the requested scopes:

   ```bash
   php demo/authenticate.php
   ```

   This writes `demo/tokens/token.json`. Tokens auto-refresh from then on. **Re-run this whenever the required scope list changes** — tool calls against a token that is missing a scope fail with a clear message.

> **Upgrading an existing install:** the server now requests the full `Calendar` scope (for
> `calendar_create_event`) instead of read-only. An existing `token.json` granted only
> `calendar.readonly` no longer covers the required scopes, so **every** tool call (Gmail and Slides
> included, since authentication is shared) will fail with a clear "missing required scope" error until
> you re-run `php demo/authenticate.php`.

## Running the MCP server

```bash
php server.php                                   # uses ./demo/tokens
GOOGLE_TOKEN_DIR=/path/to/token-dir php server.php
```

Configuration is via environment variables (an MCP host passes them through its config; see `demo/.mcp.json.example` for an example):

| Variable | Purpose |
| --- | --- |
| `GOOGLE_TOKEN_DIR` | OAuth token directory (default `./demo/tokens`) |
| `GOOGLE_ALLOW_SEND` | `"1"` enables the outbound `gmail_send_*` tools; otherwise they refuse the call |
| `GOOGLE_ALLOW_CALENDAR_WRITE` | `"1"` enables `calendar_create_event`; otherwise it refuses the call |
| `GOOGLE_FILES_DIR` | Attachment sandbox directory (required for attachment download/upload) |

The server is **single-user by design**: tokens live on the local filesystem and are not suitable for a shared or multi-user deployment without rewriting the auth layer.

## Development

```bash
vendor/bin/tester tests -s   # run tests (Nette Tester)
composer phpstan             # static analysis (level 8)
```

## Security notes

- Read tools flag their responses with `untrustedContent: true`; the server instructions tell the model to treat all third-party text (subjects, bodies, filenames, event/calendar text) as data, never as instructions.
- Outbound send is double-gated: a host approval plus the server-side `GOOGLE_ALLOW_SEND` check.
- Attachment paths are confined to a flat sandbox directory with `realpath` containment (symlinks escaping it are rejected).
