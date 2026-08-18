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
    public const VERSION = '1.0.1';

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
