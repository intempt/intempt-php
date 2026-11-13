# Intempt PHP SDK

Server-side client for [Intempt](https://intempt.com). **Data in, decisions out.**

- **In** — events, identity, consent, commerce
- **Out** — recommendations from your feeds

This is a server library, not a browser one. It holds no per-user state: every call
takes its identifier explicitly.

```bash
composer require intempt/intempt-php
```

Requires PHP 8.1 or newer, with `ext-curl` and `ext-json`. No package dependencies.

## Quick start

```php
use Intempt\Intempt;

$intempt = new Intempt([
    'org' => 'my-org',
    'project' => 'my-project',
    'apiKey' => getenv('INTEMPT_API_KEY'),   // "<prefix>.<secret>"
    'sourceId' => '684508596718616576',
]);

$intempt->track('purchase', [
    'userId' => 'user@example.com',
    'properties' => ['total' => 99.99],
]);

$feed = $intempt->recommend([
    'userId' => 'user@example.com',
    'feedId' => '5292',
    'fields' => ['id', 'title'],
]);
```

By default each call sends one request and returns when the server responds.

## API

| call | returns | endpoint |
| ---- | ------- | -------- |
| `new Intempt($config)` | client | — |
| `track($event, $options)` | `bool` | `POST …/track` |
| `trackBatch($events)` | `bool` | `POST …/track`, chunked |
| `identify($options)` | `bool` | `POST …/track` (reserved `Identify`) |
| `group($options)` | `bool` | `POST …/track` (reserved `Identify`) |
| `consent->grant($options)` | `bool` | `POST …/consents/data` |
| `consent->revoke($options)` | `bool` | `POST …/consents/data` |
| `ecommerce->productViewed($options)` | `void` | `POST …/track` |
| `ecommerce->addedToCart($options)` | `void` | `POST …/track` |
| `ecommerce->ordered($options)` | `void` | `POST …/track` |
| `recommend($options)` | `mixed` | `POST …/feeds/{id}/data` |
| `variation($key, $context, $default, $disableExposure = false)` | `mixed` | `POST …/optimization/choose-api` |
| `variationDetail($key, $context, $default, $disableExposure = false)` | `FlagDetail` | `POST …/optimization/choose-api` |
| `boolVariation` / `stringVariation` / `numberVariation` / `jsonVariation` | typed value | `POST …/optimization/choose-api` |
| `allFlags($context)` | `array` | `POST …/optimization/choose-api` (no exposure) |
| `optIn()` / `optOut()` / `isOptedIn()` | — | — |
| `flush()` / `close()` | `array{sent, failed}` | — |
| `setConfig($patch)` / `config()` / `buffered()` | — | — |

A caller mistake (bad name, missing identifier, closed client) throws.
`track`, `trackBatch`, `identify` and `group` return `bool` — accepted (queued in batch
mode, answered 2xx in direct mode) or not — and never throw for a delivery problem;
those reach `onDeliveryFailure` (see the delivery policy below).
A flag read never throws for a service problem: it returns `$default`, and the failure
reaches the `onFlagError` option as a `FlagError`. `variationDetail()` returns the same
value plus `reason: 'error'` and the `FlagError` when the call failed; the service sends no
reason, so a served value and an unassigned key both read `off`. `consent->grant` and
`consent->revoke` are always sent at once, batch mode or not, and return `true` only when
the server answered 2xx; a failure returns `false`, is logged at error level and reaches
`onDeliveryFailure` once. `recommend()` throws
`IntemptApiException` on failure.

## Identifiers

`userId` and `accountId`. That is the whole list, and both are values you already
own. The platform resolves identity from `userId` itself.

`profileId` and `masterId` are deliberately absent: `profileId` is the anonymous id
the browser SDK mints on the device, and a server that invents one creates an orphan
profile; `masterId` is assigned after identity resolution and cannot be read from a
server.

## Batching, and the PHP caveat

**Leave batching off under PHP-FPM.** A typical FPM process handles one request and
dies, so an in-memory buffer has nowhere to live and `flush()` has nothing useful to
do. Turn it on only in a long-running process — a worker, a queue consumer, Swoole,
RoadRunner:

```php
use Intempt\BatchOptions;

$intempt = new Intempt([
    // …
    'batch' => new BatchOptions(size: 50, flushMs: 5_000, maxQueue: 10_000),
]);
```

`close()` drains for at most 30 seconds, then logs and reports (`dropped`) the events
it gave up on. `flush()` is **not** bounded.

**While the server is unreachable.** PHP has no timer, so `flushMs` is the wait after a
batch that never reached the server: for that long, reaching `size` sends nothing, so a
dead host is not hit on every `track()`. The first `track()` that reaches `size` after the
wait makes the next attempt; an explicit `flush()`, `close()` or the exit hook (`flushOnExit`)
tries at once. The kept batch is reported once, as `transport`. Meanwhile `maxQueue` bounds
memory: when it is reached the **oldest** waiting event is dropped and reported `dropped`,
and the new event is queued (`track()` returns `true`).

### Delivery policy

| outcome | behaviour |
| ------- | --------- |
| 2xx | sent |
| 503, 429 | resend **once** after `Retry-After`, clamped to 1–60 s (1 s when absent); a wait past 5 s drops instead of sleeping, because the wait would run inside your request |
| any other status (400, 401–404, 408, 413, 500, 502, 504…) | drop, report |
| timeout after the request was written | drop, report — it may have landed, so a resend could duplicate |
| server never reached (DNS, refused, connect timeout) | batch mode: keep the batch for the next attempt (above); direct mode and consent: drop, report |

"Report" means a log line plus `onDeliveryFailure`, which hears each batch's final
outcome once (`DeliveryFailure`: `kind` transport/terminal/dropped, `status`,
`eventCount`, `eventIds`, `retryAfter` seconds, `terminal`). Nothing is thrown for a
delivery problem, in either mode (INT-7436, INT-7444).

## Credentials

The SDK holds only the encoded credential — it is never a property on any object you
can print, and it is redacted in `print_r`, `var_dump`, `var_export` and `__toString`.

**Set `zend.exception_ignore_args=1` in production.** PHP copies call arguments into
every stack trace, so your own `new Intempt(['apiKey' => …])` frame carries the key
into every later exception, including `getTraceAsString()`. No SDK can fix that from
the inside. `tests/CredentialGuardTest.php` proves both halves: what the SDK redacts,
and what only that ini setting removes.

## Timestamps

`timestamp` accepts a `DateTimeInterface` or epoch milliseconds. It **is** a backfill
mechanism between 2010 and 2040; below that the request is rejected, and **above 2040
the server silently replaces your value with its own clock**. Sending seconds where
milliseconds were meant lands in that upper band and looks like it just happened.

## Not in this SDK

Console and configuration operations belong to the CLI and MCP server. Experiments and
personalizations resolve a web experience against a page, and a server has no page.

## License

Apache 2.0. Contains code derived from
[mixpanel-php](https://github.com/mixpanel/mixpanel-php), also Apache 2.0; see
[NOTICE](./NOTICE) for what was taken and what was changed.
