# Authentication

## The invariant: never crash before the MCP handshake

An OAuth failure must surface as a `ToolCallException` on the **first tool call**, not
as a process crash — otherwise the MCP host sees only "server died". This invariant is
spread across four places and is easy to break with a local "fix":

- `Authenticator::getClient()` builds the client **lazily** (building eagerly in the
  constructor would crash the server before the handshake);
- `server.php` holds managers as **closure factories** (`static fn() => new …(…->authenticate())`),
  so `authenticate()` runs only inside the call;
- `ManagerResolver::resolve()` catches `AuthException` → `ToolCallException`;
- each `McpTools::getManager()` caches the manager and goes through the resolver.

## The token-callback bug

Google's default token callback **discards the `refresh_token` and writes nothing to
disk**, so the next expiry has no refresh token and **every subsequent request 401s
"Invalid Credentials" until the process restarts**. `setTokenCallback` installs a
custom callback that re-carries `refresh_token`+`scope` and `saveToken`s (atomically,
tmp+rename).

Refresh nuance: only `invalid_grant` unlinks the token and forces re-auth; a transport
error **keeps** the token; a refresh response that omits `refresh_token` has it
re-attached.

## Scopes: subsumption, and scope ≠ write

`BroaderScopes` encodes subsumption (`mail.google.com` subsumes `gmail.modify`,
`calendar` subsumes `calendar.readonly`, `drive` subsumes `presentations`), and
`assertScopesGranted` fails fast when the token has fewer scopes than the code needs —
except a legacy token with **no `scope` field is left unchecked**. `Scopes::Services`
(scope per service) is the single source of truth, shared by `server.php` and the demo;
both request only the scopes of the services `GOOGLE_TOOLS` enables.

**Granting a scope does not enable writes.** Even with the `CALENDAR` scope, which tools
the model gets is decided at the **tool layer** by `GOOGLE_TOOLS` (see mcp-plumbing.md)
— an easy detail to miss.
