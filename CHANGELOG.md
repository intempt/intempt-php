# Changelog

## 1.0.1 — unreleased

- Fix: `tests/FakeServer.php` and `tests/ExampleAppTest.php` referenced the
  pcntl-only `SIGTERM` constant without pcntl being an installed extension,
  fataling the suite in any environment matching CI's extension set
  (`curl`, `json`). Replaced with the literal `15`.
- Fix: `examples/bare/send.php` and `examples/basic/app.php` hardcoded
  `require __DIR__.'/../../vendor/autoload.php'`, which only resolves when
  the example runs from within this repo. Installed as a real Composer
  dependency, that path lands one level short of the consumer's own
  `vendor/autoload.php` and fatals. Both now try the repo-relative and the
  installed-package-relative path.

## 1.0.0 — unreleased

First release. Server-side SDK, Apache 2.0, derived from mixpanel-php; see
[NOTICE](./NOTICE) for what was taken and what changed.

### The surface

`track`, `trackBatch`, `identify`, `group`, `alias`, `consent.grant/revoke`,
`ecommerce->productViewed/addedToCart/ordered`, `recommend`,
`optIn`/`optOut`/`isOptedIn`, `flush`, `close`, `setConfig`, `config`,
`buffered`.

Identical to `intempt-node` and `intempt-python` allowing for language idiom, so a
customer switching languages gets the same delivery semantics for the same call.
The shared contract is in [ARCHITECTURE.md](./ARCHITECTURE.md).

### Deliberately absent

- **Experiments and personalizations.** They resolve a web experience against a
  page, and a server has no page. Browser SDK territory.
- **`profileId` and `masterId`.** The only identifiers are `userId` and
  `accountId`, both values the caller already owns.
- **Console and configuration operations.** Journeys, dashboards, segments and
  brand belong to the CLI and MCP server.

### Delivery guarantees

- Every method raises on failure. Nothing is swallowed.
- Retry policy: 413 halves the batch width and recovers by doubling after ten
  full-width successes; 429 honours `Retry-After`; 5xx/408/timeout back off
  exponentially, floored at 100ms and capped at 10 minutes; other 4xx drop the
  batch; five consecutive failures stop batching and report what is stranded.
- `close()` is bounded at 30 seconds and says how many events it abandoned.
  `flush()` is unbounded.
- Opt-out is enforced in the send path, so events buffered before `opt_out()`
  are discarded rather than transmitted by a later flush.
- A platform id never goes through `(int)`. A 19-digit snowflake exceeds float
  precision and a numeric round trip addresses a different source.
- Consent timestamps are epoch **seconds**; `/track` is milliseconds.
- Delivery is at-least-once. Ingestion has no idempotency key, so a retry after
  a lost response duplicates rows.
