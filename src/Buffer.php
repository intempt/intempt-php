<?php

/**
 * Opt-in event buffer for long-lived processes.
 *
 * Portions derived from mixpanel-php (Apache License 2.0), as recorded in
 * NOTICE: the consumer strategy split and the chunking follow its
 * ConsumerStrategies. Changed substantially — the delivery policy is Intempt's
 * and lives in Delivery (CONTRACT-R2 §S1).
 *
 * Deliberately in memory. Crash durability needs disk with fsync, file locking
 * and boot-time recovery, which is a different design and is not in scope.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt;

final class Buffer
{
    /** @var list<array<string, mixed>> */
    private array $queue = [];
    private int $batchSize;
    private bool $stopped = false;
    private ?float $closeDeadline = null;
    private readonly Delivery $delivery;
    private readonly \Closure $clock;

    /**
     * Set while a batch that never reached the server waits at the head. PHP has no timer, so
     * `flushMs` is enforced lazily: until this time the width trigger in enqueue() starts no
     * network attempt (ruling R4: no hot loop on a dead host); the first trigger at or after it
     * makes the next attempt, so a worker that never calls flush() is not stuck behind the batch
     * until the queue cap evicts it. An explicit flush() always attempts.
     */
    private ?float $heldUntil = null;

    /** Events at the head of the queue that a send is carrying right now; 0 when idle. */
    private int $inFlight = 0;

    /**
     * @param callable(list<array<string, mixed>>): void $send
     * @param (\Closure(): float)|null                     $clock seconds; injectable so tests do not wait
     */
    public function __construct(
        private readonly BatchOptions $options,
        int $maxRequestEvents,
        private readonly Logger $logger,
        private $send,
        private readonly float $closeBudgetSeconds = 30.0,
        ?Delivery $delivery = null,
        ?\Closure $clock = null,
    ) {
        $this->batchSize = min($options->size, $maxRequestEvents);
        $this->delivery = $delivery ?? new Delivery($logger);
        $this->clock = $clock ?? static fn (): float => microtime(true);

        if ($options->flushOnExit) {
            register_shutdown_function(function (): void {
                $this->shutdown();
            });
        }
    }

    /**
     * The flushOnExit hook. Public so the exit path is testable without ending the process.
     */
    public function shutdown(): void
    {
        // close(), not flush(): flush() returns at once on a kept batch, and the process ends right
        // after, so those events would vanish unreported. A shutdown function must never throw: it
        // runs while PHP is tearing down and an exception there is reported without context.
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }

    /**
     * Buffer an event, or log, report and drop it. True when it was queued.
     *
     * At the cap the oldest waiting event is evicted and reported `dropped`, so the newest data
     * survives an outage (ruling R4). Events a send is carrying are never evicted: the post-send
     * splice removes them by position. When every queued event is in flight the new one is dropped.
     *
     * @param array<string, mixed> $event
     */
    public function enqueue(array $event): bool
    {
        if ($this->stopped) {
            $this->logger->error(
                '[intempt] batching is stopped; event dropped',
                ['name' => $event['name'] ?? null]
            );
            $this->delivery->reportDropped([$event]);

            return false;
        }
        if (count($this->queue) >= $this->options->maxQueue) {
            if (count($this->queue) === $this->inFlight) {
                $this->logger->error('[intempt] batch queue full; event dropped', [
                    'name' => $event['name'] ?? null,
                    'maxQueue' => $this->options->maxQueue,
                ]);
                $this->delivery->reportDropped([$event]);

                return false;
            }
            [$oldest] = array_splice($this->queue, $this->inFlight, 1);
            $this->logger->error('[intempt] batch queue full; oldest event dropped', [
                'name' => $oldest['name'] ?? null,
                'maxQueue' => $this->options->maxQueue,
            ]);
            $this->delivery->reportDropped([$oldest]);
        }

        $this->queue[] = $event;
        if (count($this->queue) >= $this->batchSize && !$this->holding()) {
            $this->flush();
        }

        return true;
    }

    private function holding(): bool
    {
        return $this->heldUntil !== null && ($this->clock)() < $this->heldUntil;
    }

    public function size(): int
    {
        return count($this->queue);
    }

    /**
     * Drain until the queue is empty, the buffer has stopped, or the server is unreachable.
     *
     * A call made while a send is in progress (from the send callback or the onDeliveryFailure
     * listener) returns at once: the outer run is still draining, and a nested one would resend the
     * in-flight head.
     *
     * @return array{sent: int, failed: int}
     */
    public function flush(): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        if ($this->inFlight > 0) {
            return $result;
        }
        while (true) {
            if ($this->outOfCloseBudget()) {
                return $result;
            }
            if ($this->stopped || $this->queue === []) {
                return $result;
            }

            $batch = array_slice($this->queue, 0, $this->batchSize);
            $send = $this->send;
            $this->inFlight = count($batch);
            try {
                $outcome = $this->delivery->deliver(
                    static fn () => $send($batch),
                    $batch,
                    true,
                    $this->closeDeadline
                );
            } finally {
                $this->inFlight = 0;
            }
            if ($outcome === Delivery::KEPT) {
                $this->heldUntil = ($this->clock)() + $this->options->flushMs / 1000;

                return $result;
            }

            $this->heldUntil = null;
            array_splice($this->queue, 0, count($batch));
            $result[$outcome === Delivery::SENT ? 'sent' : 'failed'] += count($batch);
        }
    }

    private function outOfCloseBudget(): bool
    {
        return $this->closeDeadline !== null && microtime(true) >= $this->closeDeadline;
    }

    /**
     * Drain within the budget, then report anything left behind.
     *
     * @return array{sent: int, failed: int}
     */
    public function close(): array
    {
        $this->closeDeadline = microtime(true) + $this->closeBudgetSeconds;
        try {
            $result = $this->flush();
        } finally {
            $this->closeDeadline = null;
        }

        $remaining = count($this->queue);
        if ($remaining > 0) {
            $this->logger->error(sprintf(
                '[intempt] close() gave up after %.0fs with %d event(s) unsent.',
                $this->closeBudgetSeconds,
                $remaining
            ));
            $this->delivery->reportDropped($this->queue);
            $result['failed'] += $remaining;
            $this->queue = [];
        }
        $this->stopped = true;

        return $result;
    }
}
