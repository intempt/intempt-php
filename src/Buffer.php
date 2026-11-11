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

    /** @param callable(list<array<string, mixed>>): void $send */
    public function __construct(
        private readonly BatchOptions $options,
        int $maxRequestEvents,
        private readonly Logger $logger,
        private $send,
        private readonly float $closeBudgetSeconds = 30.0,
        ?Delivery $delivery = null,
    ) {
        $this->batchSize = min($options->size, $maxRequestEvents);
        $this->delivery = $delivery ?? new Delivery($logger);

        if ($options->flushOnExit) {
            register_shutdown_function(function (): void {
                // A shutdown function must never throw: it runs while PHP is
                // tearing down and an exception there is reported without
                // context.
                try {
                    $this->flush();
                } catch (\Throwable) {
                }
            });
        }
    }

    /**
     * Buffer an event, or log, report and drop it. True when it was queued.
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
            $this->logger->error('[intempt] batch queue full; event dropped', [
                'name' => $event['name'] ?? null,
                'maxQueue' => $this->options->maxQueue,
            ]);
            $this->delivery->reportDropped([$event]);

            return false;
        }

        $this->queue[] = $event;
        if (count($this->queue) >= $this->batchSize) {
            $this->flush();
        }

        return true;
    }

    public function size(): int
    {
        return count($this->queue);
    }

    /** Drain until the queue is empty, the buffer has stopped, or the server is unreachable. */
    /** @return array{sent: int, failed: int} */
    public function flush(): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        while (true) {
            if ($this->outOfCloseBudget()) {
                return $result;
            }
            if ($this->stopped || $this->queue === []) {
                return $result;
            }

            $batch = array_slice($this->queue, 0, $this->batchSize);
            $send = $this->send;
            $outcome = $this->delivery->deliver(
                static fn () => $send($batch),
                $batch,
                true,
                $this->closeDeadline
            );
            if ($outcome === Delivery::KEPT) {
                // Never reached the server: stop here rather than spin on a
                // network that is down; the next flush tries again.
                return $result;
            }

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
