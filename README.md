# dg/google-services

Ergonomic PHP wrappers over the official `google/apiclient` for **Gmail, Calendar, Meet and Google Slides**, plus a single-user **MCP server** (stdio) that exposes those operations as tools to Claude and other MCP clients.

- **Gmail** – search threads, read bodies (with charset/RFC 2047 decoding and HTML→text), draft / update / send / reply, labels, attachments (sandboxed), archive, trash.
- **Calendar** – list events and calendars, create events (with Meet links, recurrence, reminders), manage attendees.
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
| `GOOGLE_TOOLS` | Which tools the server exposes (default `gmail, calendar:read, slides`), see below |
| `GOOGLE_FILES_DIR` | Attachment sandbox directory (required for attachment download/upload) |

### Choosing tools: `GOOGLE_TOOLS`

Every tool has an access level, and each level includes the lower ones:

- **read** – only reads (search, get, list),
- **write** – changes in your own account nobody else sees (drafts, labels, archive/trash, slide edits, events without guests),
- **send** – something reaches third parties (`gmail_send_draft`, `gmail_send_reply`, `calendar_add_attendees`).

`GOOGLE_TOOLS` is a comma-separated list of rules applied left to right, starting from nothing:

| Rule | Meaning |
| --- | --- |
| `slides` | whole service up to **write** |
| `gmail:read` | service up to the given level (`read`, `write`, `send`) |
| `gmail_create_draft` | a single tool |
| `gmail_label_*` | tools matching the pattern (never a send-level one) |
| `default` | the default selection `gmail, calendar:read, slides` (cannot be negated) |
| `-rule` | removes tools instead (a service is removed as `-gmail`, without a level) |

**Nothing reaches third parties unless you name it:** send-level tools are enabled only by `service:send` or by their full name.

```
slides                                  # Slides only, e.g. for a project working on presentations
slides:read                             # read presentations only
gmail:read, gmail_*_draft               # read mail and write drafts, but never send
default, -gmail_trash_thread            # the default without trash
gmail:send, calendar:send, slides       # everything
```

Disabled tools are not advertised to the model at all. An invalid rule (unknown service, level or tool, a pattern matching nothing) stops the server with a message on stderr, and on startup the server logs what it enabled, e.g. `tools: calendar off, gmail off, slides 12/12 tools`.

The server requests OAuth scopes only for the enabled services. To get a token limited to them (e.g. a Slides-only token that cannot touch the mailbox), run the authorization flow with the same `GOOGLE_TOOLS` and a separate `GOOGLE_TOKEN_DIR` containing a copy of `secret.json`.

The server is **single-user by design**: tokens live on the local filesystem and are not suitable for a shared or multi-user deployment without rewriting the auth layer.

## Development

```bash
vendor/bin/tester tests -s   # run tests (Nette Tester)
composer phpstan             # static analysis (level 8)
```

## Security notes

- Read tools flag their responses with `untrustedContent: true`; the server instructions tell the model to treat all third-party text (subjects, bodies, filenames, event/calendar text) as data, never as instructions.
- Outbound send is double-gated: a host approval plus the server-side `GOOGLE_TOOLS` selection, which never enables send-level tools implicitly.
- Attachment paths are confined to a flat sandbox directory with `realpath` containment (symlinks escaping it are rejected).
