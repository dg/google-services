# Managers: the Manager pattern, Gmail MIME, Slides diff

A `Manager` wraps a `Google\Service\X`; the matching `McpTools` exposes operations as
MCP tools. There is one **construction-contract seam** worth knowing: `Gmail\Manager`
takes an already-built `Gmail` service (the factory builds it outside), while
`Calendar`/`Slides`/`Meet` managers take a `Google\Client` and build the service
**inside**. Two different contracts.

## Gmail: MIME and reply traps

- **`withBatch` flips global state.** `setUseBatch(true)` toggles a flag on the shared
  `Client`, balanced by try/finally — explicitly **not safe when the Client is shared
  across concurrent requests** (Swoole/parallel). Listing (`searchThreads`/`listDrafts`)
  fetches only IDs, then batch-fetches metadata; a per-item error arrives as a
  `GoogleException` keyed `response-<id>` and is rethrown.
- **Encoding is hardened because the whole MCP response is `json_encode`d.**
  `decodeHeader` does RFC 2047 → UTF-8; `decodeTextPart` honors the declared charset
  (old ISO-8859-2/windows-1250 mail), falling back `mb_convert_encoding` →
  `iconv(...//IGNORE)` → `Strings::fixEncoding`, so a mislabeled charset can't produce a
  string that later breaks `json_encode`. `splitAddressList` splits on commas **outside
  quotes**.
- **`createReplyMessage` is the most delicate logic.** Reply-To wins; otherwise, if the
  last message is **ours** (`strcasecmp` sender == my address), it addresses the
  original recipients (replying "to the sender" would just mail ourselves); our own
  address is stripped from Cc; `In-Reply-To`/`References` come from the last message-id;
  `Re:` is added only if absent.
- **Attachment sandbox is two-layered.** The MCP layer's `resolveSandboxedPath` requires
  a flat filename, `realpath`-contains it, and checks for a symlink escape; the Manager
  layer enforces an 18 MB cap with a `filesize()` precheck **before** reading, so an
  oversize file can't be slurped into RAM first. Saved names are neutral, derived from
  **magic bytes** (`finfo`), never the client filename.

## Slides: a UTF-16 diff engine

`Slides\Manager` carries a non-trivial, testable diff core worth its own attention:

- **`setShapeText` reconciles a mandatory trailing newline** — the API keeps exactly one
  trailing newline and refuses to delete it, so that is handled separately from the
  prefix/suffix `diffEdit`, to avoid **bold-bleed** (a single bold word just before the
  edit point would otherwise bleed bold to the end).
- **Indexing is UTF-16**, so a non-BMP character (emoji) counts as two units
  (`utf16Ranges`/`utf16Length`/`diffEdit`).
- **`decodeSoftBreaks`** maps `\v` → U+000B because the MCP transport drops a raw U+000B.
- **Optimistic locking** via `requiredRevisionId` → `WriteControl`; and empty
  placeholders/notes must **not** be filtered out or the "add a slide then fill it"
  workflow breaks.
- Field masks (`ContentFields`/`StyleFields`) request text-only, because the full DTO
  graph easily exceeds the MCP token budget.

Calendar (selective attendee notifications; IANA-vs-offset `eventDateTime`) and the dead
`Meet` are minor by comparison.
