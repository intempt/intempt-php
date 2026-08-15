<?php

/**
 * Opt-in event buffer for long-lived processes.
 *
 * Portions derived from mixpanel-php (Apache License 2.0), as recorded in
 * NOTICE: the consumer strategy split and the chunking follow its
 * ConsumerStrategies. Changed substantially — the retry policy is Intempt's.
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
    private const MAX_RETRY_INTERVAL_MS = 600_000;
    /** Floor for any retry, so a zero or past Retry-After cannot become a hot loop. */
    private const MIN_RETRY_INTERVAL_MS = 100;
    private const MAX_CONSECUTIVE_FAILURES = 5;

    /**
     * Consecutive single-event 413 drops before saying so once.
     *
     * Diagnostic only. Using this tally to change behaviour was tried twice on
     * the Node SDK and both attempts were worse than what they fixed: stopping
     * stranded the queue and discarded every later event, and pinning the width
     * to 1 capped throughput at one event per round trip so a fast producer
     * overflowed the queue.
     */
    private const DROPS_BEFORE_WARNING = 3;

    /** Successful full-width sends before trying a wider batch again. */
    private const SUCCESSES_BEFORE_WIDENING = 10;

    /** @var list<array<string, mixed>> */
    private array $queue = [];
    private int $batchSize;
    private int $consecutiveFailures = 0;
    private int $consecutiveSuccesses = 0;
    private int $consecutiveDrops = 0;
    private bool $stopped = false;
    private ?float $closeDeadline = null;

    /** @param callable(list<array<string, mixed>>): void $send */
    public function __construct(
        private readonly BatchOptions $options,
        private readonly int $maxRequestEvents,
        private readonly Logger $logger,
        private $send,
        private readonly float $closeBudgetSeconds = 30.0,
    ) {
        $this->batchSize = min($options->size, $maxRequestEvents);

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
     * Buffer an event, or log and drop it.
     *
     * Returns nothing on purpose: a drop is reported through the logger, which
     * is the channel callers actually have.
     *
     * @param array<string, mixed> $event
     */
    public function enqueue(array $event): void
    {
        if ($this->stopped) {
            $this->logger->error(
                '[intempt] batching is stopped; event dropped',
                ['name' => $event['name'] ?? null]
            );

            return;
        }
        if (count($this->queue) >= $this->options->maxQueue) {
            $this->logger->error('[intempt] batch queue full; event dropped', [
                'name' => $event['name'] ?? null,
                'maxQueue' => $this->options->maxQueue,
            ]);

            return;
        }

        $this->queue[] = $event;
        if (count($this->queue) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function size(): int
    {
        return count($this->queue);
    }

    /** Drain until the queue is empty or the buffer has stopped. */
    public function flush(): void
    {
        while (true) {
            if ($this->outOfCloseBudget()) {
                return;
            }
            if ($this->stopped || $this->queue === []) {
                return;
            }

            $batch = array_slice($this->queue, 0, $this->batchSize);

            try {
                ($this->send)($batch);
            } catch (\Throwable $error) {
                if ($this->handleFailure($error, $batch) === 'requeue') {
                    continue;
                }

                return;
            }

            array_splice($this->queue, 0, count($batch));
            $this->consecutiveFailures = 0;
            $this->consecutiveDrops = 0;
            $this->widenIfEarned(count($batch));
        }
    }

    /**
     * Grow the width back after a run of successes at the current width.
     *
     * Comparing against the full width instead would be unreachable: the batch
     * is sliced to the current width, so once a 413 halves it the condition can
     * never be true again and the reduction lasts for the life of the client.
     *
     * Only a send that filled the current width counts, so a trickle producer
     * does not earn a widening from batches that never tested the width. At a
     * width of 1 that filter cannot bite, which is the intended floor.
     */
    private function widenIfEarned(int $sent): void
    {
        $full = min($this->options->size, $this->maxRequestEvents);
        if ($this->batchSize < $full && $sent >= $this->batchSize) {
            ++$this->consecutiveSuccesses;
            if ($this->consecutiveSuccesses >= self::SUCCESSES_BEFORE_WIDENING) {
                $this->batchSize = min($full, $this->batchSize * 2);
                $this->consecutiveSuccesses = 0;
            }
        }
    }

    /**
     * Apply the retry table.
     *
     * 413 batch > 1    halve the width and retry
     * 413 batch = 1    drop the event, log it, return the width to full
     * 429              honour Retry-After, else exponential backoff
     * 5xx/408/timeout  exponential backoff
     * other 4xx        drop the batch, surface status and body
     *
     * @param list<array<string, mixed>> $batch
     *
     * @return 'requeue'|'stop'
     */
    private function handleFailure(\Throwable $error, array $batch): string
    {
        $apiError = $error instanceof IntemptApiException ? $error : null;
        $status = $apiError?->status;

        // Any failure ends the run of successes, whichever branch handles it.
        $this->consecutiveSuccesses = 0;

        if ($status === 413) {
            return $this->handleTooLarge($batch);
        }

        if ($apiError !== null && !$apiError->isRetryable()) {
            $this->logger->error('[intempt] non-retryable error; dropping batch', [
                'status' => $status,
                'body' => $apiError->body,
                'count' => count($batch),
            ]);
            array_splice($this->queue, 0, count($batch));
            // Dropping a malformed batch is not a transient failure, so it must
            // not count toward the breaker.
            $this->consecutiveFailures = 0;

            return 'requeue';
        }

        ++$this->consecutiveFailures;
        if ($this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
            $this->logger->error(sprintf(
                '[intempt] %d consecutive failures; stopping batching. %d event(s) remain buffered.',
                $this->consecutiveFailures,
                count($this->queue)
            ));
            $this->stopped = true;

            return 'stop';
        }

        $backoffMs = $this->backoffMs($apiError);
        // Starting a wait that outlives the close budget burns the remaining
        // time and gives up anyway.
        if ($this->closeDeadline !== null
            && microtime(true) + $backoffMs / 1000 >= $this->closeDeadline) {
            return 'stop';
        }
        $this->logger->warning(sprintf('[intempt] send failed; retrying in %dms', $backoffMs));
        usleep($backoffMs * 1000);

        return 'requeue';
    }

    /** @param list<array<string, mixed>> $batch */
    private function handleTooLarge(array $batch): string
    {
        if (count($batch) > 1) {
            $this->batchSize = max(1, intdiv(count($batch), 2));
            $this->logger->warning(
                sprintf('[intempt] 413 received; reducing batch size to %d', $this->batchSize)
            );

            return 'requeue';
        }

        $this->logger->error('[intempt] single event too large; dropping', [
            'name' => $batch[0]['name'] ?? null,
        ]);
        array_splice($this->queue, 0, 1);
        $this->consecutiveFailures = 0;
        // The offending event is gone, so the width was never the problem. Any
        // policy that keeps the width down here costs delivered events, because
        // the widening ramp then has to climb back while the producer keeps
        // filling the queue.
        $this->batchSize = min($this->options->size, $this->maxRequestEvents);

        ++$this->consecutiveDrops;
        if ($this->consecutiveDrops === self::DROPS_BEFORE_WARNING) {
            // Hedged deliberately: this tally cannot tell a gateway whose limit
            // is below one event from a burst of individually oversized events,
            // and in the second case everything behind them sends fine.
            $this->logger->error(sprintf(
                '[intempt] %d events rejected as too large with none accepted in between. '
                . 'Either those events are individually oversized, or the gateway\'s request '
                . 'body limit is below a single event — if it is the latter, every event will '
                . 'be dropped until the limit is raised.',
                $this->consecutiveDrops
            ));
        }

        return 'requeue';
    }

    private function backoffMs(?IntemptApiException $apiError): int
    {
        // Only a positive value. A zero or already-past Retry-After arrives here
        // as 0 and would otherwise burn every attempt in milliseconds.
        $advised = ($apiError?->retryAfterMs ?? 0) > 0 ? $apiError->retryAfterMs : null;
        $computed = $this->options->flushMs * (2 ** $this->consecutiveFailures);

        return (int) min(
            self::MAX_RETRY_INTERVAL_MS,
            max(self::MIN_RETRY_INTERVAL_MS, $advised ?? $computed)
        );
    }

    private function outOfCloseBudget(): bool
    {
        return $this->closeDeadline !== null && microtime(true) >= $this->closeDeadline;
    }

    /** Drain within the budget, then report anything left behind. */
    public function close(): void
    {
        $this->closeDeadline = microtime(true) + $this->closeBudgetSeconds;
        try {
            $this->flush();
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
        }
        $this->stopped = true;
    }
}
