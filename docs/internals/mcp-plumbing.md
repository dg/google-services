# MCP plumbing: how a tool error travels

## `McpToolCallGuard` — the single error boundary

The guard decorates the SDK `ReferenceHandler` and centralizes **all** conversion of
errors into `ToolCallException`, so the `McpTools` methods carry no per-call
try/catch. The catch order is a contract — **first match wins**:

1. `ToolCallException` → passthrough (already shaped by a tool: an auth/send gate, a
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

## Discovery, and write-gating as a prompt-injection defense

Tools are exposed via `#[McpTool]`/`#[Schema]` attributes discovered by
`setDiscovery(src, ['*Tools.php'])`. Two security-relevant, non-local design choices:

- **Write-gating stays in `tools/list`.** A gated write tool is still advertised; the
  gate rejects the *call* only. This is intentional — "a prompt-injected model can't
  quietly trigger a send even if the host auto-approves" — via `requireSendAllowed()`
  (Gmail) / `requireWriteAllowed()` (Calendar). **Slides has no gate — it writes
  freely.**
- **Every read tool wraps its result as `untrustedContent: true`**, matched by a
  SECURITY block in the server instructions — the prompt-injection flag for content the
  model must not treat as instructions.

`Meet` is latent/dead: `Meet\Manager` exists (`createSpace`) but has **no `McpTools`**,
so discovery never exposes it.
