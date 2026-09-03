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
| `track($event, $options)` | `void` | `POST …/track` |
| `trackBatch($events)` | `void` | `POST …/track`, chunked |
| `identify($options)` | `void` | `POST …/track` (reserved `Identify`) |
| `group($options)` | `void` | `POST …/track` (reserved `Identify`) |
| `consent->grant($options)` | `void` | `POST …/consents/data` |
| `consent->revoke($options)` | `void` | `POST …/consents/data` |
| `ecommerce->productViewed($options)` | `void` | `POST …/track` |
| `ecommerce->addedToCart($options)` | `void` | `POST …/track` |
| `ecommerce->ordered($options)` | `void` | `POST …/track` |
| `recommend($options)` | `mixed` | `POST …/feeds/{id}/data` |
| `optIn()` / `optOut()` / `isOptedIn()` | — | — |
| `flush()` / `close()` | `void` | — |
| `setConfig($patch)` / `config()` / `buffered()` | — | — |

Every method throws on failure. Nothing is swallowed.

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

`close()` drains for at most 30 seconds, then stops retrying and logs how many events
it gave up on. `flush()` is **not** bounded.

### Retry policy

| response | behaviour |
| -------- | --------- |
| 413, batch > 1 | halve the batch size, retry |
| 413, batch = 1 | drop the event, log it, return the width to full |
| 429 | honour `Retry-After`, else exponential backoff |
| 5xx, 408, timeout | exponential backoff, floored at 100ms, capped at 10 min |
| other 4xx | drop the batch, log the status and body |
| 5 consecutive failures | stop batching and say how many events are stranded |
| 3 consecutive 413 drops | say the gateway body limit is the likely cause, once |

**Delivery is at-least-once.** Ingestion has no idempotency key, so a retry after a
lost response duplicates rows. Leave `batch` unset if you would rather a failure
surface to your code than be retried.

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
