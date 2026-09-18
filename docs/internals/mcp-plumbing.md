# MCP plumbing: how a tool error travels

## `McpToolCallGuard` — the single error boundary

The guard decorates the SDK `ReferenceHandler` and centralizes **all** conversion of
errors into `ToolCallException`, so the `McpTools` methods carry no per-call
try/catch. The catch order is a contract — **first match wins**:

1. `ToolCallException` → passthrough (already shaped by a tool: a re-auth hint, a
   sandbox rejection);
2. `GoogleException` → `"Google API error: "` + the extracted upstream message
   (`errors[0].message`);
3. `InvalidArgumentException | AssertionException` → the clean message, no debug noise;
4. anything else → wrapped with `message + class + file:line`.

Without the guard, any `\Throwable` from a tool becomes an opaque JSON-RPC `-32603`
with no message. The trap: the typed `catch`es look like dead code to PHPStan because
the SDK interface under-declares `@throws` — deliberately scoped-ignored in
`phpstan.neon`.

`ManagerResolver` is a static helper (not a trait, so each `McpTools` keeps its own
concretely-typed manager cache) that catches `AuthException` → `ToolCallException` with
a uniform "re-authorize via …" hint; the `$this->manager ??= ManagerResolver::resolve(
$this->managerFactory)` idiom is copied in all three `McpTools`.

## Tool selection as a prompt-injection defense

Tools are declared by `#[McpTool]`/`#[Schema]` attributes plus a mandatory
`#[Access(AccessLevel::…)]` (read / write / send). `server.php` does **not** use the
SDK's `setDiscovery()`: `ToolSelection::discover()` finds all tools,
`ToolSelection::select()` applies the `GOOGLE_TOOLS` rules, and `ToolLoader` registers
only the selected ones. Security-relevant, non-local design choices:

- **A disabled tool does not exist for the model.** It is absent from `tools/list` and a
  call to it fails as an unknown tool; there is no per-call gate inside tool bodies. The
  instructions name what is disabled, so the model can tell the user how to enable it.
- **`send` = something reaches third parties**, and it is never granted implicitly: a
  bare service means up to `write`, and a `*` pattern skips send-level tools. A tool
  must therefore not hide a send behind a parameter; that is why
  `calendar_create_event` has no attendees (guests go through `calendar_add_attendees`).
- **A broken `GOOGLE_TOOLS` stops the server** before the handshake. Unlike a missing
  `secret.json` (deferred to the first call, see auth.md) a typo in a rule could expose
  more tools than intended, so failing loudly is the safe side.
- **Every read tool wraps its result as `untrustedContent: true`**, matched by a
  SECURITY block in the server instructions — the prompt-injection flag for content the
  model must not treat as instructions. `server.php` assembles the instructions from the
  `UntrustedContent` / `Instructions` / `WriteInstructions` / `SendInstructions`
  constants of the enabled services, so no hint names a tool the selection left out.
- **Scopes follow the selection:** the `Authenticator` requires only the scopes of
  enabled services (`Scopes::forServices()`), so a Slides-only server runs on a token
  that cannot touch the mailbox at all.

`Meet` is latent/dead: `Meet\Manager` exists (`createSpace`) but has **no `McpTools`**,
so discovery never exposes it.
