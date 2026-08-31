<?php

/**
 * The public client.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt;

final class Intempt
{
    /** A response the service did not answer is reported as such rather than guessed at. */
    private const UNANSWERED = 'off';

    public const VERSION = '1.1.0';

    /** Reserved event name the platform interprets as an identity write. */
    public const IDENTIFY_EVENT = 'Identify';

    /**
     * Reserved names the platform recognises for commerce reporting. The only
     * reason this namespace exists is to encode them so callers cannot typo them.
     */
    public const COMMERCE_EVENTS = [
        'productViewed' => 'Product viewed',
        'addedToCart' => 'Added to cart',
        'ordered' => 'Product ordered',
    ];

    public readonly Consent $consent;
    public readonly Ecommerce $ecommerce;

    private Config $config;
    private readonly Transport $transport;
    private ?Buffer $buffer = null;
    private bool $optedIn = true;
    private bool $closed = false;

    /** @param array<string, mixed> $options */
    public function __construct(array $options)
    {
        $this->config = Config::resolve($options);
        $this->transport = new Transport($this->config, $this->config->credentials);

        if ($this->config->batch !== null) {
            $this->buffer = new Buffer(
                $this->config->batch,
                $this->config->maxRequestEvents,
                $this->config->logger(),
                fn (array $events) => $this->send($events),
            );
        }

        $this->consent = new Consent($this);
        $this->ecommerce = new Ecommerce($this);
    }

    // -- data in ----------------------------------------------------------

    /** @param array<string, mixed> $options */
    public function track(string $event, array $options = []): void
    {
        $this->assertEventName($event, 'track');
        Validate::identifier($options, 'track');
        $this->submit([$this->buildEvent($event, $options)]);
    }

    /**
     * Send many events, chunked so one oversized call is not one oversized request.
     *
     * @param list<array<string, mixed>> $events
     */
    public function trackBatch(array $events): void
    {
        if ($events === []) {
            return;
        }

        $wire = [];
        foreach ($events as $index => $item) {
            if (!is_array($item)) {
                throw new IntemptConfigException(
                    sprintf('trackBatch[%d]: each event must be an array', $index)
                );
            }
            $name = $item['event'] ?? null;
            $this->assertEventName($name, sprintf('trackBatch[%d]', $index));
            Validate::identifier($item, sprintf('trackBatch[%d]', $index));
            unset($item['event']);
            $wire[] = $this->buildEvent((string) $name, $item);
        }

        if ($this->buffer !== null || !$this->isOptedIn()) {
            $this->submit($wire);

            return;
        }

        foreach (Validate::chunk($wire, $this->config->maxRequestEvents) as $group) {
            $this->send($group);
        }
    }

    /** @param array<string, mixed> $options */
    public function identify(array $options): void
    {
        Validate::identifier($options, 'identify');
        $event = $options['event'] ?? null;
        $traits = $options['traits'] ?? null;
        unset($options['event'], $options['traits']);
        $options['userAttributes'] = $traits;
        $this->submit([$this->buildEvent($this->reservedName($event, 'identify'), $options)]);
    }

    /** @param array<string, mixed> $options */
    public function group(array $options): void
    {
        Validate::nonBlank($options['accountId'] ?? null, 'group', 'accountId');
        $event = $options['event'] ?? null;
        $attributes = $options['attributes'] ?? null;
        unset($options['event'], $options['attributes']);
        $options['accountAttributes'] = $attributes;
        $this->submit([$this->buildEvent($this->reservedName($event, 'group'), $options)]);
    }

    /** @param array<string, mixed> $options */
    public function alias(array $options): void
    {
        Validate::nonBlank($options['userId'] ?? null, 'alias', 'userId');
        Validate::nonBlank($options['previousUserId'] ?? null, 'alias', 'previousUserId');
        $previous = $options['previousUserId'];
        $event = $options['event'] ?? null;
        unset($options['event'], $options['previousUserId']);

        $item = $this->buildEvent($this->reservedName($event, 'alias'), $options);
        $item['payload'][0]['anotherUserId'] = $previous;
        $this->submit([$item]);
    }

    // -- decisions out ----------------------------------------------------

    /**
     * Product recommendations from a feed.
     *
     * Experiments and personalizations are deliberately absent: they resolve a
     * web experience against a page, and a server has no page.
     *
     * @param array<string, mixed> $options
     */
    public function recommend(array $options): mixed
    {
        $this->assertOpen();
        $feedId = Validate::nonBlank($options['feedId'] ?? null, 'recommend', 'feedId');
        $fields = $options['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            throw new IntemptConfigException('recommend: fields must be a non-empty array');
        }
        $limit = $options['limit'] ?? null;
        if ($limit !== null && (!is_int($limit) || $limit < 1)) {
            throw new IntemptConfigException('recommend: limit must be a positive integer');
        }

        $userId = $options['userId'] ?? null;
        $accountId = $options['accountId'] ?? null;
        // The feeds API resolves a single {id, type} entity, so exactly one.
        if (Validate::present($userId) && Validate::present($accountId)) {
            throw new IntemptConfigException(
                'recommend: pass userId or accountId, not both — the feeds API resolves '
                . 'a single entity'
            );
        }
        if (Validate::present($userId)) {
            $identity = ['id' => $userId, 'type' => 'user'];
        } elseif (Validate::present($accountId)) {
            $identity = ['id' => $accountId, 'type' => 'account'];
        } else {
            throw new IntemptConfigException('recommend: one of userId or accountId is required');
        }

        $body = Validate::compact($identity + [
            'fields' => array_values($fields),
            'limit' => $limit,
            'productId' => $options['productId'] ?? null,
            'sourceId' => $this->config->sourceId !== null ? (string) $this->config->sourceId : null,
        ]);

        return $this->transport->post(
            $this->config->projectPath('/feeds/' . rawurlencode($feedId) . '/data'),
            $body
        );
    }

    // -- flags -------------------------------------------------------------

    /**
     * The value assigned to this person for $key, or $defaultValue if the service did not answer.
     *
     * Ask for a KEY, never a mode. Whether the key names an experiment, a personalization or a
     * flag is the platform's business: its serving query filters on channel and status and never
     * on mode. The older surface put the mode in the method name, which forced a caller to know
     * which it was before reading it and grew combinatorially with every new mode.
     */
    public function variation(string $key, FlagContext $context, mixed $defaultValue): mixed
    {
        return $this->variationDetailInternal($key, $context, $defaultValue)->value;
    }

    /**
     * Internal. NOT public, deliberately.
     *
     * It returns a reason, and the platform does not send one: a held-back person's experience is
     * absent from the evaluation response entirely rather than present with a cause. So every
     * reason would read "off" -- including for someone who WAS targeted and did receive the
     * variant. That is a wrong answer, not a missing one, and a method whose only job is
     * explaining why must not guess.
     *
     * variation() uses it for the value, which is correct either way. It becomes public when the
     * serving contract carries a reason.
     */
    private function variationDetailInternal(
        string $key,
        FlagContext $context,
        mixed $defaultValue
    ): FlagDetail {
        $this->assertOpen();
        Validate::flagKey($key, 'variation');

        foreach ($this->chooseOrEmpty($context, [$key]) as $choice) {
            if (($choice['name'] ?? null) !== $key) {
                continue;
            }
            $body = $choice['body'] ?? null;

            // The reason is NOT read off the wire. `grep -rni reason` across the service's
            // experience package returns nothing and `ExperienceApiChoose` carries only
            // name/group/body, so a `reason` key can only arrive from a fixture. Reading one would
            // make this branch look live while being unreachable in production - and an unreachable
            // branch is an unkillable mutant, which costs MSI headroom on a gate set at 85.
            return new FlagDetail($body ?? $defaultValue, self::UNANSWERED);
        }

        return new FlagDetail($defaultValue, self::UNANSWERED);
    }

    /**
     * Every key assigned to this person, in one call.
     *
     * **This is not a free read, and it is not a cheaper `variation()`.** Omitting `names` makes the
     * service evaluate EVERY running Server experience, and every evaluation reports an exposure:
     * `retrieveApiExperiences` -> `ChooserHelper.display` -> `publishEvent` -> Kafka. That is
     * `EXP-SERVE-003` working as specified, not a defect, but the consequence here is specific: one
     * call at request start enrols the caller in every running experiment, including keys this code
     * never reads, inflating those denominators with people who were shown nothing.
     *
     * Use it to enumerate or to debug. For a request path that reads two keys, call `variation()`
     * twice — that reports two exposures instead of all of them. There is no suppress flag on the
     * endpoint today; see docs/CONVENTIONS.md.
     *
     * @return array<string, mixed>
     */
    public function allFlags(FlagContext $context): array
    {
        $this->assertOpen();
        $out = [];
        foreach ($this->chooseOrEmpty($context, null) as $choice) {
            $name = $choice['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $out[$name] = $choice['body'] ?? null;
            }
        }

        return $out;
    }

    public function boolVariation(string $key, FlagContext $context, bool $defaultValue): bool
    {
        $value = $this->variation($key, $context, $defaultValue);

        // A served value of the wrong type is a misconfiguration, not something to coerce:
        // (bool) 'false' is true, and a silent coercion is indistinguishable from a real answer.
        return is_bool($value) ? $value : $defaultValue;
    }

    public function stringVariation(string $key, FlagContext $context, string $defaultValue): string
    {
        $value = $this->variation($key, $context, $defaultValue);

        return is_string($value) ? $value : $defaultValue;
    }

    public function numberVariation(
        string $key,
        FlagContext $context,
        int|float $defaultValue
    ): int|float {
        $value = $this->variation($key, $context, $defaultValue);

        // is_numeric() would accept the STRING '42', so the check is on type, not value.
        // No bool guard is needed here: unlike Python, PHP's is_int() is false for true.
        return is_int($value) || is_float($value) ? $value : $defaultValue;
    }

    /**
     * A JSON object or a JSON array body, returned as-is.
     *
     * Keyed `array-key`, not `string`: a JSON *array* body decodes to a PHP list, `is_array()`
     * accepts it and it is returned with integer keys. Typing it `array<string, mixed>` said
     * otherwise.
     *
     * @param array<array-key, mixed> $defaultValue
     * @return array<array-key, mixed>
     */
    public function jsonVariation(string $key, FlagContext $context, array $defaultValue): array
    {
        $value = $this->variation($key, $context, $defaultValue);

        return is_array($value) ? $value : $defaultValue;
    }

    /**
     * Returns immediately.
     *
     * Present so the cross-SDK surface is the same everywhere, and so a caller porting from an SDK
     * that polls a local flag store does not have to remove the call. Evaluation here is remote:
     * each variation() is a request, so there is no local state to wait for.
     */
    public function waitForInitialization(?int $timeoutMs = null): void
    {
        // $timeoutMs is accepted and ignored on purpose - there is no local store to wait for, so
        // there is nothing a timeout could bound. Stated here because PHPStan L5 does not report an
        // unused parameter, leaving a reader nothing else to go on.
        unset($timeoutMs);

        $this->assertOpen();
    }

    /**
     * A transport failure returns no choices rather than throwing.
     *
     * This is the entire reason $defaultValue is required: a network failure, a 5xx or a timeout
     * must resolve to the value the caller chose. A flag SDK that throws when the service is
     * unreachable takes the application down with it, which is the opposite of what a kill switch
     * is for. A validation mistake still throws, because that is a programming error the caller
     * can fix rather than a runtime condition to absorb.
     *
     * @param list<string>|null $names
     * @return list<array<string, mixed>>
     */
    private function chooseOrEmpty(FlagContext $context, ?array $names): array
    {
        $body = Validate::compact([
            'identification' => Validate::compact([
                'userId' => $context->userId,
                'profileId' => $context->profileId,
                'sourceId' => $this->config->sourceId !== null
                    ? (string) $this->config->sourceId
                    : null,
            ]),
            'names' => $names,
            // Omitted when the caller supplies none, rather than sent as null: `ChooserHelper`
            // rewrites a blank session to the literal "default", so every exposure would otherwise
            // land in one shared session and `ONCE_PER_VISIT` would degrade to `ONCE`.
            'sessionId' => $context->sessionId,
            // NOT decoration. `ExperienceRequest` splices this straight into the serving SQL as a
            // raw predicate: null becomes "0", which matches nothing, ever - every flag would return
            // its default in production while this suite stayed green. ALL becomes "1". Hardcoding
            // it also means device targeting is bypassed rather than honoured, which is correct for
            // a server SDK that has no user agent to read.
            'device' => 'all',
        ]);

        try {
            $response = $this->transport->post(
                $this->config->projectPath('/optimization/choose-api'),
                $body
            );
        } catch (\Throwable $e) {
            // config->logger is nullable; config->logger() is the accessor that falls back to a
            // NullLogger. Reaching for the property directly made the error handler itself throw,
            // turning a recoverable service failure into a fatal - the exact opposite of what this
            // catch exists to do. Found by the sample gate, because the unit tests only ever
            // exercised a 500 with a logger present.
            $this->config->logger()->warning('[intempt] flag evaluation failed, using defaults');

            return [];
        }

        if (!is_array($response)) {
            return [];
        }
        $choices = $response['choices'] ?? null;

        return is_array($choices) ? array_values($choices) : [];
    }

    // -- privacy ----------------------------------------------------------

    public function optIn(): void
    {
        $this->optedIn = true;
    }

    /**
     * Suppress all outbound writes: track, batch, commerce and consent.
     *
     * recommend() is unaffected — it sends an identifier the caller already
     * holds and returns a decision rather than storing anything.
     */
    public function optOut(): void
    {
        $this->optedIn = false;
    }

    public function isOptedIn(): bool
    {
        return $this->optedIn && !$this->closed;
    }

    // -- config -----------------------------------------------------------

    /** @param array<string, mixed> $patch */
    public function setConfig(array $patch): void
    {
        $this->config = $this->config->merge($patch);
        $this->transport->setConfig($this->config);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function buffered(): int
    {
        return $this->buffer?->size() ?? 0;
    }

    // -- lifecycle --------------------------------------------------------

    /** Drain the buffer. A no-op when batching is off. */
    public function flush(): void
    {
        $this->buffer?->flush();
    }

    /** Flush, then release the connection. The client is unusable after. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->buffer?->close();
        $this->closed = true;
        $this->transport->close();
    }

    // -- internals --------------------------------------------------------

    /** @internal */
    public function transport(): Transport
    {
        return $this->transport;
    }

    /** @internal */
    public function assertOpen(): void
    {
        if ($this->closed) {
            throw new IntemptConfigException(
                'Intempt client is closed. Calls after close() are not sent; create a new client.'
            );
        }
    }

    private function assertEventName(mixed $event, string $method): void
    {
        if (!is_string($event) || trim($event) === '') {
            throw new IntemptConfigException(sprintf('%s: event name is required', $method));
        }
        if (strtolower(trim($event)) === strtolower(self::IDENTIFY_EVENT)) {
            throw new IntemptConfigException(sprintf(
                '%s: "%s" is reserved; use identify(), group() or alias()',
                $method,
                $event
            ));
        }
    }

    private function reservedName(mixed $event, string $method): string
    {
        if ($event === null) {
            return self::IDENTIFY_EVENT;
        }

        return Validate::nonBlank($event, $method, 'event');
    }

    private function trackPath(): string
    {
        $sourceId = $this->config->sourceId;
        if ($sourceId !== null && $sourceId !== '') {
            return $this->config->projectPath('/sources/' . rawurlencode($sourceId) . '/track');
        }

        return $this->config->projectPath('/track');
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildEvent(string $name, array $options): array
    {
        $raw = $options['timestamp'] ?? null;
        $item = Validate::compact([
            'eventId' => Validate::uuid4(),
            'timestamp' => $raw !== null ? Validate::timestamp($raw) : Validate::nowMs(),
            'profileId' => $options['profileId'] ?? null,
            'userId' => $options['userId'] ?? null,
            'accountId' => $options['accountId'] ?? null,
            'data' => $options['properties'] ?? null,
            'userAttributes' => $options['userAttributes'] ?? null,
            'accountAttributes' => $options['accountAttributes'] ?? null,
        ]);

        return ['name' => $name, 'payload' => [$item]];
    }

    /**
     * One event carrying several payload items, one per line.
     *
     * Kept bit-compatible with the 1.x commerce wire format: the lines share a
     * single eventId. Nothing on the ingestion path dedupes on eventId, so this
     * cannot collapse rows.
     *
     * @param array<string, mixed>       $ids
     * @param list<array<string, mixed>> $lines
     *
     * @internal
     */
    public function trackLines(string $name, array $ids, array $lines): void
    {
        $eventId = Validate::uuid4();
        $raw = $ids['timestamp'] ?? null;
        $millis = $raw !== null ? Validate::timestamp($raw) : Validate::nowMs();

        $payload = [];
        foreach ($lines as $line) {
            $payload[] = Validate::compact([
                'eventId' => $eventId,
                'timestamp' => $millis,
                'profileId' => $ids['profileId'] ?? null,
                'userId' => $ids['userId'] ?? null,
                'accountId' => $ids['accountId'] ?? null,
                'data' => $line,
            ]);
        }

        $this->submit([['name' => $name, 'payload' => $payload]]);
    }

    /** @param list<array<string, mixed>> $events */
    private function submit(array $events): void
    {
        // A closed client throws; an opted-out client returns quietly. Silently
        // discarding a write after close is how events get lost without anyone
        // being told.
        $this->assertOpen();
        if (!$this->isOptedIn() || $events === []) {
            return;
        }
        if ($this->buffer !== null) {
            foreach ($events as $event) {
                $this->buffer->enqueue($event);
            }

            return;
        }
        $this->send($events);
    }

    /**
     * Post one request. Also the buffer's send callback.
     *
     * The opt-out gate is repeated here because the buffer calls this directly.
     * Without it, events captured before optOut() are still transmitted by a
     * later flush(), close() or the shutdown hook.
     *
     * @param list<array<string, mixed>> $events
     */
    private function send(array $events): void
    {
        if (!$this->isOptedIn()) {
            $this->config->logger()->warning(sprintf(
                '[intempt] opted out; discarding %d buffered event(s) rather than sending',
                count($events)
            ));

            return;
        }
        $this->transport->post($this->trackPath(), ['track' => $events]);
    }
}
