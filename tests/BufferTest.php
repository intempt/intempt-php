<?php

/**
 * Batching and the delivery policy.
 *
 * Every assertion here pins a decision a rewrite could silently change: which
 * statuses are resent, how many times, how long the wait is, and which
 * failures keep the batch. The policy is CONTRACT-R2 §S1 (INT-7436).
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\BatchOptions;
use Intempt\Buffer;
use Intempt\Delivery;
use Intempt\IntemptApiException;

final class BufferTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function event(string $name): array
    {
        return [
            'name' => $name,
            'payload' => [['eventId' => $name, 'timestamp' => 1, 'userId' => 'u1']],
        ];
    }

    private static function options(
        int $size = 10,
        int $flushMs = 60_000,
        int $maxQueue = 100,
    ): BatchOptions {
        return new BatchOptions(
            size: $size,
            flushMs: $flushMs,
            maxQueue: $maxQueue,
            flushOnExit: false
        );
    }

    /** @var list<int> */
    private array $sleeps = [];

    /** @param callable(list<array<string, mixed>>): void $send */
    private function buffer(
        callable $send,
        ?BatchOptions $options = null,
        float $closeBudget = 30.0,
    ): Buffer {
        $this->sleeps = [];

        return new Buffer(
            $options ?? self::options(),
            50,
            $this->logger,
            $send,
            $closeBudget,
            new Delivery($this->logger, sleep: function (int $ms): void {
                $this->sleeps[] = $ms;
            }),
        );
    }

    /** @return callable(list<array<string, mixed>>): void */
    private static function failing(int $status): callable
    {
        return static function (array $events) use ($status): void {
            throw new IntemptApiException("Intempt API responded $status", status: $status, body: '');
        };
    }

    // -- buffering --------------------------------------------------------

    public function testBuffersUntilFlush(): void
    {
        $client = $this->batchedClient();
        $client->track('a', ['userId' => 'u1']);
        $client->track('b', ['userId' => 'u1']);
        self::assertSame(2, $client->buffered());
        self::assertSame([], self::server()->requests());

        $client->flush();
        self::assertSame(0, $client->buffered());
        self::assertCount(2, self::server()->requests()[0]['body']['track']);
    }

    public function testFlushesAtExactlyBatchSize(): void
    {
        // flushMs is 60s, so anything sent was triggered by the width check.
        $client = $this->batchedClient(['batch' => self::options(size: 3, maxQueue: 50)]);
        $client->track('a', ['userId' => 'u1']);
        $client->track('b', ['userId' => 'u1']);
        self::assertSame(2, $client->buffered());

        $client->track('c', ['userId' => 'u1']);
        self::assertSame(0, $client->buffered());
        self::assertCount(3, self::server()->requests()[0]['body']['track']);
    }

    public function testFlushIsANoOpWithoutBatching(): void
    {
        $this->client()->flush();
        self::assertSame([], self::server()->requests());
    }

    public function testDropsAndNamesTheEventWhenTheQueueIsFull(): void
    {
        // The queue is only full while a send is in flight: the batch is spliced
        // off after the send returns, so an enqueue *during* the send sees the
        // full queue. That is the real shape of this path, and it is why
        // maxQueue must be >= size for it to be reachable at all.
        $buffer = null;
        $buffer = $this->buffer(
            static function (array $events) use (&$buffer): void {
                $buffer->enqueue(self::event('overflow'));
            },
            self::options(size: 2, maxQueue: 2)
        );
        $buffer->enqueue(self::event('a'));
        $buffer->enqueue(self::event('b'));

        self::assertTrue($this->logger->has('error', 'batch queue full; event dropped'));
        self::assertSame(
            ['name' => 'overflow', 'maxQueue' => 2],
            $this->logger->context['error'][0]
        );
    }

    // -- delivery policy (INT-7436, CONTRACT-R2 §S1) ----------------------

    /** @return callable(list<array<string, mixed>>): void */
    private static function counting(int &$attempts, \Throwable ...$failures): callable
    {
        return static function (array $events) use (&$attempts, $failures): void {
            $failure = $failures[$attempts] ?? null;
            ++$attempts;
            if ($failure !== null) {
                throw $failure;
            }
        };
    }

    private static function answered(int $status, ?int $retryAfterMs = null): IntemptApiException
    {
        return new IntemptApiException(
            "Intempt API responded $status",
            status: $status,
            body: '',
            retryAfterMs: $retryAfterMs
        );
    }

    /** @return array<string, array{0: int}> */
    public static function dropStatuses(): array
    {
        return [
            '400' => [400], '401' => [401], '404' => [404], '408' => [408], '413' => [413],
            '500' => [500], '502' => [502], '504' => [504],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dropStatuses')]
    public function testEveryOtherAnsweredStatusIsSentOnceAndDropped(int $status): void
    {
        $attempts = 0;
        $buffer = $this->buffer(self::counting($attempts, self::answered($status)));
        $buffer->enqueue(self::event('a'));
        $buffer->enqueue(self::event('b'));
        $buffer->flush();

        self::assertSame(1, $attempts);
        self::assertSame(0, $buffer->size());
        self::assertSame([], $this->sleeps);
        self::assertTrue($this->logger->has('error', 'dropping 2 event(s)'));
    }

    public function test413DropsTheWholeBatchWithoutSplitting(): void
    {
        $attempts = 0;
        $buffer = $this->buffer(self::counting($attempts, self::answered(413)));
        for ($i = 0; $i < 4; ++$i) {
            $buffer->enqueue(self::event("e$i"));
        }
        $buffer->flush();

        self::assertSame(1, $attempts);
        self::assertSame(0, $buffer->size());
    }

    public function testATimeoutAfterTheRequestWasWrittenIsNotResent(): void
    {
        $attempts = 0;
        $buffer = $this->buffer(
            self::counting($attempts, new IntemptApiException('request timed out', timedOut: true))
        );
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertSame(1, $attempts);
        self::assertSame(0, $buffer->size());
        self::assertSame([], $this->sleeps);
    }

    /** @return array<string, array{0: int}> */
    public static function resendStatuses(): array
    {
        return ['429' => [429], '503' => [503]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resendStatuses')]
    public function testA503Or429IsResentExactlyOnceThenDropped(int $status): void
    {
        $attempts = 0;
        $buffer = $this->buffer(
            self::counting($attempts, self::answered($status), self::answered($status), self::answered($status))
        );
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertSame(2, $attempts);
        self::assertSame(0, $buffer->size());
        self::assertSame([1000], $this->sleeps, 'no Retry-After waits 1 s');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resendStatuses')]
    public function testTheOneResendCanSucceed(int $status): void
    {
        $attempts = 0;
        $buffer = $this->buffer(self::counting($attempts, self::answered($status, 2_000)));
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertSame(2, $attempts);
        self::assertSame(0, $buffer->size());
        self::assertSame([2000], $this->sleeps, 'Retry-After is honoured');
        self::assertFalse($this->logger->has('error', 'dropping'));
    }

    public function testAZeroRetryAfterWaitsTheOneSecondFloor(): void
    {
        $attempts = 0;
        $buffer = $this->buffer(self::counting($attempts, self::answered(503, 0)));
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertSame([1000], $this->sleeps);
    }

    public function testAWaitOfExactlyTheFiveSecondCapStillResends(): void
    {
        $attempts = 0;
        $buffer = $this->buffer(self::counting($attempts, self::answered(503, 5_000)));
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertSame(2, $attempts);
        self::assertSame([5000], $this->sleeps);
    }

    public function testAWaitPastTheFiveSecondCapDropsWithoutSleeping(): void
    {
        // A server SDK sleeps inline, inside the caller's request (CONTRACT-R2 §S1.2).
        $attempts = 0;
        $buffer = $this->buffer(self::counting($attempts, self::answered(429, 5_001)));
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertSame(1, $attempts);
        self::assertSame([], $this->sleeps);
        self::assertSame(0, $buffer->size());
        self::assertTrue($this->logger->has('error', 'dropping 1 event(s)'));
    }

    public function testANeverReachedBatchIsKeptForTheNextFlush(): void
    {
        $attempts = 0;
        $buffer = $this->buffer(
            self::counting($attempts, new IntemptApiException('connection refused', unreached: true))
        );
        $buffer->enqueue(self::event('a'));
        $buffer->enqueue(self::event('b'));
        $buffer->flush();

        self::assertSame(1, $attempts, 'kept, not looped on');
        self::assertSame(2, $buffer->size());
        self::assertSame([], $this->sleeps);

        $buffer->flush();
        self::assertSame(2, $attempts);
        self::assertSame(0, $buffer->size());
    }

    public function testADropDoesNotStopTheBatchesBehindIt(): void
    {
        $delivered = [];
        $buffer = $this->buffer(
            static function (array $events) use (&$delivered): void {
                if ($events[0]['name'] === 'e0') {
                    throw self::answered(500);
                }
                foreach ($events as $event) {
                    $delivered[] = $event['name'];
                }
            },
            self::options(size: 2, maxQueue: 100)
        );
        for ($i = 0; $i < 6; ++$i) {
            $buffer->enqueue(self::event("e$i"));
        }

        self::assertSame(['e2', 'e3', 'e4', 'e5'], $delivered);
        self::assertSame(0, $buffer->size());
    }

    // -- close budget -----------------------------------------------------

    public function testCloseReturnsInsideItsBudget(): void
    {
        $buffer = $this->buffer(self::failing(500), self::options(), 0.12);
        $buffer->enqueue(self::event('a'));

        $started = microtime(true);
        $buffer->close();
        self::assertLessThan(3.0, microtime(true) - $started);
    }

    public function testCloseSaysHowManyItAbandoned(): void
    {
        $buffer = $this->buffer(
            static function (array $events): void {
                throw new IntemptApiException('connection refused', unreached: true);
            },
            self::options(),
            0.12
        );
        for ($i = 0; $i < 4; ++$i) {
            $buffer->enqueue(self::event("e$i"));
        }
        $buffer->close();

        $gaveUp = $this->logger->matching('error', 'gave up');
        self::assertNotEmpty($gaveUp);
        self::assertStringContainsString('4 event(s) unsent', $gaveUp[0]);
    }

    public function testCloseStillDrainsEverythingWhenHealthy(): void
    {
        // The bound must not cost events that would have sent.
        $sent = 0;
        $buffer = $this->buffer(
            static function (array $events) use (&$sent): void {
                $sent += count($events);
            },
            self::options(),
            0.12
        );
        for ($i = 0; $i < 25; ++$i) {
            $buffer->enqueue(self::event("e$i"));
        }
        $buffer->close();

        self::assertSame(25, $sent);
        self::assertSame(0, $buffer->size());
        self::assertFalse($this->logger->has('error', 'gave up'));
    }

    public function testCloseStopsOnTheDeadlineEvenWhenSendsSucceedSlowly(): void
    {
        // The other guard only fires on failure; this is the case it cannot see.
        $sent = 0;
        $buffer = $this->buffer(
            static function (array $events) use (&$sent): void {
                usleep(60_000);
                $sent += count($events);
            },
            // size 100 so enqueue never auto-flushes: everything must still be
            // queued when close() starts, or there is nothing for the deadline
            // to stop.
            self::options(size: 100, maxQueue: 100),
            0.15
        );
        for ($i = 0; $i < 20; ++$i) {
            $buffer->enqueue(self::event("e$i"));
        }
        // Model a width an earlier 413 had reduced to 1, so the drain takes many
        // slow sends rather than one.
        (function (): void {
            $this->batchSize = 1;
        })->call($buffer);
        $buffer->close();

        self::assertGreaterThan(0, $sent);
        self::assertLessThan(20, $sent);
        self::assertTrue($this->logger->has('error', 'gave up'));
    }

    public function testFlushIsNotBounded(): void
    {
        // Only close() gives up. A caller mid-request has not asked to.
        $attempts = 0;
        $buffer = $this->buffer(
            self::counting($attempts, self::answered(503)),
            self::options(flushMs: 1),
            0.001
        );
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertSame(2, $attempts);
        self::assertSame(0, $buffer->size());
    }

    // -- opt-out gate -----------------------------------------------------

    public function testBufferedEventsAreDiscardedRatherThanSentAfterOptOut(): void
    {
        // A revocation between capture and flush must be honoured.
        $client = $this->batchedClient();
        $client->track('before', ['userId' => 'u1']);
        $client->optOut();
        $client->flush();

        self::assertSame([], self::server()->requests());
        self::assertSame(0, $client->buffered());
        self::assertTrue($this->logger->has('warning', 'opted out; discarding'));
    }

    public function testOptingBackInDoesNotResendDiscardedEvents(): void
    {
        $client = $this->batchedClient();
        $client->track('before', ['userId' => 'u1']);
        $client->optOut();
        $client->flush();
        $client->optIn();
        $client->track('after', ['userId' => 'u1']);
        $client->flush();

        $names = [];
        foreach (self::server()->requests() as $request) {
            foreach ($request['body']['track'] as $event) {
                $names[] = $event['name'];
            }
        }
        self::assertSame(['after'], $names);
    }
}
