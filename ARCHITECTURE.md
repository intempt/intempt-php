# Server SDK architecture

One contract, three implementations: `intempt-node`, `intempt-python`, `intempt-php`.

This file is the same in all three repos. Change it in one, change it in all, or the
SDKs drift and a customer switching languages gets different delivery semantics for
the same call.

## Where this came from

The shape is derived from [mixpanel-node] / [mixpanel-python] / [mixpanel-php]: the
consumer/buffer split, the chunking, the keep-alive connection reuse and the bounded
concurrency are theirs. Both projects are Apache 2.0 and this one retains their
notices; see `NOTICE`.

What is **not** inherited is the delivery guarantees. mixpanel-node has no request
timeout, no retry, and discards errors when no callback is passed
(`lib/mixpanel-node.js:80`, `callback = callback || function () {}`). mixpanel-python
is stronger — it has real backoff — but its retry policy is still not ours. Every
guarantee below was added deliberately.

[mixpanel-node]: https://github.com/mixpanel/mixpanel-node
[mixpanel-python]: https://github.com/mixpanel/mixpanel-python
[mixpanel-php]: https://github.com/mixpanel/mixpanel-php

## The surface

Identical across all three, allowing for language idiom (`snake_case` in Python,
`camelCase` in Node/PHP).

| call | endpoint |
| ---- | -------- |
| `track(event, options)` | `POST …/sources/{sourceId}/track` |
| `track_batch(events)` | same, chunked at `max_request_events` |
| `identify(options)` | same, reserved event `Identify` |
| `group(options)` | same, reserved event `Identify` |
| `consent.grant(options)` / `consent.revoke(options)` | `POST …/consents/data` |
| `ecommerce.product_viewed / added_to_cart / ordered` | `POST …/track` |
| `recommend(options)` | `POST …/feeds/{feedId}/data` |
| `opt_in()` / `opt_out()` / `is_opted_in()` | — |
| `flush()` / `close()` | — |
| `set_config(patch)` / `config` / `buffered` | — |

Base path is `{path}/v1/{org}/projects/{project}`. Without a `source_id` the track
path is `…/track` rather than `…/sources/{id}/track`.

### Deliberately absent

- **Experiments and personalizations.** They resolve a web experience against a page.
  A server has no page. These belong to the browser SDK.
- **`profile_id` and `master_id`.** `profile_id` is the anonymous id the browser SDK
  mints on the device; a server that invents one creates an orphan profile that never
  stitches to a real visitor. `master_id` is assigned internally after identity
  resolution, cannot be read from here, and breaks the moment two profiles merge.
  The only identifiers are `user_id` and `account_id`, both values the caller owns.
- **Console and configuration operations.** Journeys, dashboards, segments and brand
  belong to the CLI and MCP server, not to a data-plane SDK.

## Delivery guarantees

### Errors surface. Nothing is swallowed

A caller mistake raises. A delivery problem is reported through `onDeliveryFailure`
and the `bool`/`{sent, failed}` results, never raised and never silently discarded.

### Delivery policy

| outcome | behaviour |
| ------- | --------- |
| 2xx | sent |
| 503, 429 | resend **once** after `Retry-After`, clamped to 1–60 s (1 s when absent); a wait past 5 s drops instead of sleeping, because the wait would run inside your request |
| any other status (400, 401–404, 408, 413, 500, 502, 504…) | drop, report |
| timeout after the request was written | drop, report — it may have landed, so a resend could duplicate |
| server never reached (DNS, refused, connect timeout) | batch mode: keep the batch; the next attempt is an explicit `flush()`/`close()`/exit hook, or the first width-triggered `track()` once `flushMs` has passed (ruling R4); direct mode and consent: drop, report |

"Report" means a log line plus `onDeliveryFailure`, which hears each batch's final
outcome once (`DeliveryFailure`: `kind` transport/terminal/dropped, `status`,
`eventCount`, `eventIds`, `retryAfter` seconds, `terminal`). Nothing is thrown for a
delivery problem, in either mode (INT-7436, INT-7444).

PHP has no timer, which is why `flushMs` gates the width trigger instead of scheduling a
flush. Refusing every width-triggered attempt while a batch waits would leave it stuck in a
worker that never calls `flush()` and runs with `flushOnExit: false`, until `maxQueue`
evicted it. At `maxQueue` the oldest event not carried by an in-progress send is evicted and
reported `dropped`. A `track()` or `flush()` made from inside a send (the send callback or the
`onDeliveryFailure` listener) starts no nested send of the in-flight head.

The previous table (exponential backoff to 10 min, a breaker at 5 failures, 413
halving with a widening ramp) is gone: R-retry and R-503 (CONTRACT-R2 §S1) allow one
resend, for 503 and 429 only. The policy lives in `Delivery`, shared by the buffer,
direct mode and consent.

### Delivery is at-least-once

Ingestion has no idempotency key. `event_id` travels in the payload but is not a
column in the events table, and the table is a plain `MergeTree` — nothing collapses
duplicates. That is why a timeout after the request was written is never resent.

### close() is bounded

`close()` drains for at most 30 seconds, then stops retrying and logs how many events
it abandoned. Unbounded, a shutdown hook blocks for minutes against a failing
endpoint. `flush()` is **not** bounded — a caller who is not shutting down has not
asked to give up.

Enforce the deadline in two places, because one leaks: before starting a backoff (the
first wait alone can exceed the whole budget) and at the top of each drain iteration
(sends that succeed but are slow never reach the backoff check).

### opt-out gates buffered events too

The gate lives in the send path, not only at the call site. Otherwise events captured
before `opt_out()` are still transmitted by a later `flush()`, `close()` or exit hook,
and a consent revocation between capture and flush is not honoured.

## Quality bar

Every implementation ships with:

| gate | threshold |
| ---- | --------- |
| unit tests, HTTP intercepted | full method surface |
| integration tests, real socket | framing, keep-alive, timeout, concurrency |
| statement coverage | 100% |
| mutation score | **≥ 85%, enforced in CI as its own job** |
| lint + format + type check | clean, tests included in the type check |
| contract test against production | every method, negative controls included |

Mutation testing is not optional decoration. On the Node SDK it found two defects that
100% statement coverage did not: a 413 that permanently halved throughput because the
recovery condition was unreachable, and four dead conditionals that could not fail.
It also found three assertions that were green and asserted nothing.

Read the mutation score from CI, never from a developer machine. A timed-out mutant is
scored as killed, so a loaded laptop inflates the number — 86.19% locally against
84.70% on CI for the same commit, with a gate at 85.

## Contract test inputs

Every id must be real. Ingestion returns **201 for unknown accounts and products**, so
a fabricated `product_id` is a green test that proves nothing. Any missing input makes
its step SKIP, never silently pass.

Read endpoints need a negative control. A missing feed and a real feed with no matches
both answer 200 with an empty array, so an empty result proves nothing on its own. A
bogus feed id answers 400, and that is what makes the 200 meaningful.
