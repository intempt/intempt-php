# Changelog

## 1.1.0 — 2026-09-01

### Added — reading flags

`variation`, `boolVariation`, `stringVariation`, `numberVariation`,
`jsonVariation`, `allFlags` and `waitForInitialization`, plus the `FlagContext`
value object. Evaluation is server-side: the SDK sends an identity and a set of
keys to `/optimization/choose-api` and returns what comes back. It derives
nothing locally, which is enforced by a CI guard rather than by convention —
an SDK cannot re-derive a draw it did not witness, so any client-side bucket
arithmetic would disagree with the platform for some people, silently.

Every read returns the caller's default when the service cannot answer, in the
caller's own type. A failure is one WARN line, never an exception, because a
flag read sits on the request path and a personalization outage must not become
an outage of the thing being personalized.

### Deliberately absent

- **`variationDetail()` and a public `FlagDetail`.** The serving response
  carries no reason, so the only honest reason this SDK could return is
  "unknown" for every call. Withheld until the platform sends one, rather than
  shipped returning a constant a caller would reasonably act on.
- **`FlagContext::accountId`.** Accepted in an earlier draft and removed before
  release: the service identifies by `sourceId` / `profileId` / `userId` only,
  so an account-only caller would have had every flag return its default
  forever, with nothing raised.

### Validated at the call site, not absorbed

A flag read returns your default when Intempt cannot answer. It does **not**
do that for a mistake you can fix, because absorbing one produces an
integration that looks healthy while every key reads its default forever:

- **A context the service cannot resolve raises.** It needs either a `userId`,
  or a `profileId` together with a `sourceId` configured on the client — the
  serving endpoint resolves an entity by one or the other and rejects anything
  else. An empty context, a blank `userId`, or a `profileId` with no configured
  `sourceId` all used to reach the service, be rejected there, and come back as
  a silent default.
- **A key raises unless it matches `^[a-zA-Z0-9_-]+$`.** The service applies the
  same expression and answers a violation with a 400.

### Known limitations, recorded rather than hidden

- **`allFlags()` records an exposure on every running Server experiment**,
  including keys the caller never reads. The endpoint has no suppress field, so
  no SDK can avoid it. Prefer `variation()` per key where denominators matter.
- **`userId` is ignored when `profileId` is present.** The service tests
  `sourceId` + `profileId` first. The precedence is the caller's to choose;
  it is documented so it can be chosen deliberately.
- **A key must match `^[a-zA-Z0-9_-]+$`.** Checked locally now, so a key the
  service would reject fails loudly at the call site instead of resolving to a
  default.

### Corrects 1.0.0

1.0.0 listed **Experiments and personalizations** under *Deliberately absent*,
reasoning that "they resolve a web experience against a page, and a server has
no page". That is true of the `web` channel and false of `api`, which is a
first-class server-side experience type — which is why this release exists.
The 1.0.0 text is left as written rather than edited, since it is what shipped.

### Fixed

- `scripts/check-no-local-bucketing.mjs` reported success on a tree it had
  never read: a missing source root produced zero files, zero breaches and
  exit 0. A missing root and a zero-file scan are both errors now, reported
  before the allowlist checks, and the pass line states the file count. The CI
  job asserts both vacuous shapes fail.

## 1.0.1 — 2026-08-18

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

## 1.0.0 — 2026-08-16

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
