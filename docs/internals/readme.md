# GoogleServices internals

Google (Calendar / Gmail / Meet / Slides) exposed as MCP tools for Claude and other
MCP clients. The library (`src/`) is clean; `server.php` is the only place auth +
guard + tool discovery are wired together (stdio MCP). Split by seam:

- **[auth.md](auth.md)** — the lazy authentication chain, the token-callback bug and
  the scope subsumption check.
- **[mcp-plumbing.md](mcp-plumbing.md)** — how a tool error travels
  (`McpToolCallGuard`), the manager resolver, tool discovery, and write-gating.
- **[managers.md](managers.md)** — the Manager pattern seam, Gmail's MIME traps, and
  the Slides UTF-16 diff engine.
