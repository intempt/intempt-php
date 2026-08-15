<?php

/**
 * Batching and the retry policy.
 *
 * Every assertion here pins a decision a rewrite could silently change: which
 * statuses retry, how many attempts the breaker allows, whether backoff grows,
 * whether a reduced width recovers. This is where every bug in the Node SDK
 * lived, so it gets the same depth here.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\BatchOptions;
use Intempt\Buffer;
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

    /** @param callable(list<array<string, mixed>>): void $send */
    private function buffer(
        callable $send,
        ?BatchOptions $options = null,
        float $closeBudget = 30.0,
    ): Buffer {
        return new Buffer(
            $options ?? self::options(),
            50,
            $this->logger,
            $send,
            $closeBudget
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

    // -- retry policy -----------------------------------------------------

    public function test413HalvesTheWidthThenSucceeds(): void
    {
        $widths = [];
        $rejectWide = true;
        $buffer = $this->buffer(
            static function (array $events) use (&$widths, &$rejectWide): void {
                $widths[] = count($events);
                if ($rejectWide && count($events) > 2) {
                    throw new IntemptApiException('too large', status: 413, body: '');
                }
            },
            self::options(size: 4, maxQueue: 50)
        );
        for ($i = 0; $i < 4; ++$i) {
            $buffer->enqueue(self::event("e$i"));
        }
        $buffer->flush();

        self::assertSame(4, $widths[0]);
        self::assertSame(2, max(array_slice($widths, 1)));
        self::assertSame(0, $buffer->size());
        self::assertTrue($this->logger->has('warning', 'reducing batch size to 2'));
    }

    public function test413OnASingleEventDropsIt(): void
    {
        $buffer = $this->buffer(self::failing(413), self::options(size: 2, maxQueue: 10));
        $buffer->enqueue(self::event('a'));
        $buffer->enqueue(self::event('b'));
        $buffer->flush();

        self::assertSame(0, $buffer->size());
        self::assertTrue($this->logger->has('error', 'single event too large'));
    }

    public function test429HonoursRetryAfter(): void
    {
        $attempts = 0;
        $buffer = $this->buffer(
            static function (array $events) use (&$attempts): void {
                ++$attempts;
                if ($attempts === 1) {
                    throw new IntemptApiException(
                        'slow down',
                        status: 429,
                        body: '',
                        retryAfterMs: 200
                    );
                }
            },
            self::options(size: 1, flushMs: 10, maxQueue: 10)
        );
        $started = microtime(true);
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertTrue($this->logger->has('warning', 'retrying in 200ms'));
        self::assertGreaterThanOrEqual(0.18, microtime(true) - $started);
    }

    public function testAZeroRetryAfterIsFlooredRatherThanRetriedInstantly(): void
    {
        $attempts = 0;
        $buffer = $this->buffer(
            static function (array $events) use (&$attempts): void {
                ++$attempts;
                if ($attempts === 1) {
                    throw new IntemptApiException('x', status: 429, body: '', retryAfterMs: 0);
                }
            },
            self::options(size: 1, flushMs: 10, maxQueue: 10)
        );
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        $waits = $this->waits();
        self::assertNotEmpty($waits);
        self::assertGreaterThanOrEqual(100, $waits[0]);
    }

    public function testANonRetryableStatusDropsTheBatch(): void
    {
        $buffer = $this->buffer(self::failing(400), self::options(size: 1, maxQueue: 10));
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertSame(0, $buffer->size());
        self::assertTrue($this->logger->has('error', 'non-retryable error; dropping batch'));
    }

    public function testBreakerOpensAfterExactlyFiveAttempts(): void
    {
        // Five, not four and not six.
        $attempts = 0;
        $buffer = $this->buffer(
            static function (array $events) use (&$attempts): void {
                ++$attempts;

                throw new IntemptApiException('boom', status: 500, body: '');
            },
            self::options(size: 1, flushMs: 1, maxQueue: 10)
        );
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertSame(5, $attempts);
        self::assertTrue($this->logger->has('error', '5 consecutive failures; stopping batching'));
    }

    public function testTheStopMessageSaysHowManyAreStranded(): void
    {
        $buffer = $this->buffer(self::failing(500), self::options(size: 1, flushMs: 1, maxQueue: 10));
        $buffer->enqueue(self::event('a'));
        $buffer->enqueue(self::event('b'));
        $buffer->flush();

        $stop = $this->logger->matching('error', 'stopping batching');
        self::assertNotEmpty($stop);
        self::assertStringContainsString('event(s) remain buffered', $stop[0]);
    }

    public function testBackoffDoublesRatherThanShrinking(): void
    {
        $buffer = $this->buffer(self::failing(500), self::options(size: 1, flushMs: 60, maxQueue: 10));
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertSame([120, 240, 480], array_slice($this->waits(), 0, 3));
    }

    public function testADroppedBatchDoesNotCountTowardTheBreaker(): void
    {
        // One 400 after four 500s must not stop batching on the next blip.
        $buffer = $this->buffer(self::failing(400), self::options(size: 1, flushMs: 1, maxQueue: 20));
        for ($i = 0; $i < 8; ++$i) {
            $buffer->enqueue(self::event("e$i"));
        }
        $buffer->flush();

        self::assertSame(0, $buffer->size());
        self::assertFalse($this->logger->has('error', 'stopping batching'));
    }

    // -- width recovery ---------------------------------------------------

    public function testAReducedWidthWidensAgainAfterARunOfSuccesses(): void
    {
        $widths = [];
        $rejectWide = true;
        $buffer = $this->buffer(
            static function (array $events) use (&$widths, &$rejectWide): void {
                $widths[] = count($events);
                if ($rejectWide && count($events) > 2) {
                    throw new IntemptApiException('too large', status: 413, body: '');
                }
            },
            self::options(size: 4, maxQueue: 500)
        );
        for ($i = 0; $i < 4; ++$i) {
            $buffer->enqueue(self::event("a$i"));
        }
        $buffer->flush();
        self::assertSame(4, $widths[0]);

        $rejectWide = false;
        $before = count($widths);
        for ($i = 0; $i < 48; ++$i) {
            $buffer->enqueue(self::event("b$i"));
        }
        $buffer->flush();

        self::assertSame(
            4,
            max(array_slice($widths, $before)),
            'the width must recover, not stay halved for the life of the client'
        );
    }

    public function testWideningIgnoresSendsNarrowerThanTheCurrentWidth(): void
    {
        $widths = [];
        $rejectWide = true;
        $buffer = $this->buffer(
            static function (array $events) use (&$widths, &$rejectWide): void {
                $widths[] = count($events);
                if ($rejectWide && count($events) > 2) {
                    throw new IntemptApiException('too large', status: 413, body: '');
                }
            },
            self::options(size: 4, maxQueue: 500)
        );
        for ($i = 0; $i < 4; ++$i) {
            $buffer->enqueue(self::event("a$i"));
        }
        $buffer->flush();
        $rejectWide = false;

        $before = count($widths);
        for ($i = 0; $i < 20; ++$i) {
            $buffer->enqueue(self::event("b$i"));
            $buffer->flush();
        }

        self::assertSame([1], array_values(array_unique(array_slice($widths, $before))));
    }

    // -- drop diagnostics -------------------------------------------------

    public function testAGatewayRejectingEverythingKeepsDraining(): void
    {
        // It must not stop: stopping strands the queue and every later event.
        $buffer = $this->buffer(self::failing(413), self::options(size: 8, maxQueue: 100));
        for ($i = 0; $i < 10; ++$i) {
            $buffer->enqueue(self::event("e$i"));
        }
        $buffer->flush();

        self::assertSame(0, $buffer->size());
        self::assertFalse($this->logger->has('error', 'stopping batching'));
    }

    public function testSaysOnceThatTheGatewayLimitIsALikelyCause(): void
    {
        $buffer = $this->buffer(self::failing(413), self::options(size: 4, maxQueue: 100));
        for ($i = 0; $i < 8; ++$i) {
            $buffer->enqueue(self::event("e$i"));
        }
        $buffer->flush();

        self::assertCount(
            1,
            $this->logger->matching('error', 'rejected as too large with none accepted in between')
        );
    }

    public function testABurstOfOversizedEventsDoesNotPunishTheGoodOnes(): void
    {
        // The regression a drop-breaker caused on Node: everything stranded.
        $big = ['big0', 'big1', 'big2', 'big3', 'big4', 'big5'];
        $sent = [];
        $buffer = $this->buffer(
            static function (array $events) use (&$sent, $big): void {
                $names = array_column($events, 'name');
                if (array_intersect($names, $big) !== []) {
                    throw new IntemptApiException('too large', status: 413, body: '');
                }
                $sent = array_merge($sent, $names);
            },
            self::options(size: 8, maxQueue: 200)
        );
        foreach ($big as $name) {
            $buffer->enqueue(self::event($name));
        }
        for ($i = 0; $i < 20; ++$i) {
            $buffer->enqueue(self::event("good$i"));
        }
        $buffer->flush();

        $good = array_filter($sent, static fn (string $n) => str_starts_with($n, 'good'));
        self::assertCount(20, $good);
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
        $buffer = $this->buffer(self::failing(500), self::options(), 0.12);
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
            static function (array $events) use (&$attempts): void {
                ++$attempts;
                if ($attempts < 3) {
                    throw new IntemptApiException('boom', status: 500, body: '');
                }
            },
            self::options(flushMs: 1),
            0.001
        );
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertSame(3, $attempts);
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

    /** @return list<int> */
    private function waits(): array
    {
        $waits = [];
        foreach ($this->logger->matching('warning', 'retrying in') as $line) {
            if (preg_match('/retrying in (\d+)ms/', $line, $match) === 1) {
                $waits[] = (int) $match[1];
            }
        }

        return $waits;
    }
}
