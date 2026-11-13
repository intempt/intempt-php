# Conventions

**The cross-SDK surface is not decided here.** Every Intempt SDK conforms to
`intempt-swift/docs/SDK-API-CONTRACT.md`, which is the single authority on method names, argument
order, defaults and what is deliberately withheld. This file covers what is specific to PHP and
to this repo. Where the two disagree, the contract wins and this file is the bug.

## The rules that come from the contract

- **A caller asks for a KEY, never a mode.** There is no `flagVariation` / `experimentVariation`
  split. The platform resolves whether a key is an experiment, a personalization or a flag; its
  serving query filters on channel and status and never on mode. A method name that encodes the
  mode forces an integrator to know the answer before they can ask the question, and grows
  combinatorially with every mode added.
- **`defaultValue` is REQUIRED, everywhere, and is never optional.** It is what a caller receives on
  a network failure, a timeout, an unknown key or a malformed response. A flag SDK that throws when
  the service is unreachable takes the application down with it, which is the opposite of what a
  kill switch is for.
- **A wrong-typed value falls back; it is never coerced.** A flag configured as a string and read as
  a boolean returns the caller's default, not `true`. Coercion makes a misconfiguration look like a
  deliberate value.
- **`variationDetail` is public, in every SDK** (Beso ruling R1, 2026-11-13), together with
  `FlagDetail`, its `reason`, `disableExposure` and `onFlagError`. What it can tell you is limited
  by the wire: the serving response sends no reason (INT-7457), so a served value and an unassigned
  key both read `off`. Its job is to separate a FAILURE (`reason: 'error'` plus the `FlagError`)
  from "off", which a bare `variation()` cannot. Do not invent a reason the response did not send.
- **Evaluation is REMOTE only.** No local rule engine, no flag store to poll, and no hashing
  utility: the server decides, so no second implementation can disagree with it.
  `check-no-local-bucketing.mjs` enforces this in CI (EXP-ASSIGN-001..005) and a new bucketing
  helper will fail the build. Note what the server actually does, because the guard used to claim
  otherwise: `VariantChooserService` draws with `SecureRandom().nextInt(100)` and PERSISTS the draw
  under a `VariantChooseKey`. There is no hash and no bucket count. An SDK cannot re-derive a draw
  it never witnessed, which is why the rule is absolute rather than a matter of matching algorithms.
- **A validation mistake throws; a service problem does not.** A blank key or a missing default is a
  programming error the caller can fix, so it fails loudly at the call site. A 5xx is a runtime
  condition to absorb.

## Flags: three things the surface does not say for itself

**`allFlags()` evaluates; `variation()` exposes.** `allFlags()` sends `exposure: false`, so it no longer
records an exposure against every running experiment; reading a key with `variation()` (and the typed
variants) records that key's exposure, and `disableExposure: true` opts a single read out for a
pre-render or prefetch (INT-7456, CONTRACT-R2 §S4). This needs the audience-service side of INT-7456:
a backend without the `exposure` field ignores it and still records an exposure for every evaluation
(`ChooserHelper.display` -> `publishEvent` -> Kafka), so until that ships treat `allFlags()` as an
enumerate-or-debug call and read two keys with two `variation()` calls.

**Identifier precedence is not the argument order.** `buildAudienceRequest` tests
`sourceId != null && profileId` non-blank FIRST and only then falls through to `userId`. A context
carrying both, from a client with a configured `sourceId`, segments on the PROFILE and the `userId`
is never read. `EXP-ASSIGN-005` makes the identifier the caller's choice, which requires them to
know this. There is deliberately **no `accountId`**: `Identification` carries only `sourceId`,
`profileId` and `userId`, an account-only request makes the service throw, and this SDK absorbs
service failures — so such a caller would silently get their default for every flag, forever.

**`device` is hardcoded to `all`, and that is load-bearing, not laziness.** `ExperienceRequest`
splices it into the serving SQL as a raw predicate: a null device becomes the string `"0"`, which
matches nothing ever; `ALL` becomes `"1"`. Sending it is the difference between the surface working
and every flag returning its default. The trade is real and accepted: device targeting is *bypassed*
rather than honoured, so a rollout scoped to mobile is also served to a PHP backend, which has no
user agent to read anyway.

## Errors

Two tiers, and they are not interchangeable: a configuration mistake surfaces when the config is
built, and an API failure carries the status, the body and any `Retry-After`. A transport failure
that never produced a response carries a **null** status — read as retryable, because a request
that never arrived may well arrive next time, whereas a 400 fails identically however often it is
repeated.

## Wire shape

The ingest envelope is shared byte-for-byte across the server SDKs:

```
{"track": [{"name": "<event>", "payload": [{eventId, timestamp, profileId?, userId?, accountId?,
                                            data?, userAttributes?, accountAttributes?}]}]}
```

**An absent field is omitted, never sent as null** — a present key is an assertion about the entity.
A divergence here does not fail any test; it ingests cleanly and never appears in a report.

## Credentials

The evaluation endpoint requires a **server** credential, sent as HTTP **Basic** — not Bearer. A
public key holds users and accounts and nothing else, and the response describes how every
experience in the project targets, so a public key is refused there. Never log the credential and
never put it in a URL.

## PHP specifics

- `declare(strict_types=1)` in every file; `final` by default.
- `composer validate` runs in CI — the manifest is part of the build, not documentation.
- The buffer registers a shutdown function when `flushOnExit` is set. **A shutdown function must never
  throw**: it runs while PHP is tearing down and an exception there is reported without context.
