<?php

/**
 * Delivery failure reporting (INT-7444, CONTRACT-R2 §S2).
 *
 * track() never throws for a delivery problem and says whether the event was
 * accepted; one onDeliveryFailure listener hears each batch's FINAL outcome
 * once; flush() and close() say how many were sent and how many failed.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\BatchOptions;
use Intempt\Buffer;
use Intempt\Delivery;
use Intempt\DeliveryFailure;
use Intempt\IntemptApiException;
use Intempt\IntemptConfigException;

final class DeliveryFailureTest extends TestCase
{
    /** @var list<DeliveryFailure> */
    private array $failures = [];

    /** @return array<string, mixed> */
    private static function event(string $id): array
    {
        return ['name' => "n-$id", 'payload' => [['eventId' => $id, 'timestamp' => 1, 'userId' => 'u1']]];
    }

    /** @param callable(list<array<string, mixed>>): void $send */
    private function buffer(callable $send, int $size = 10, int $maxQueue = 100, float $budget = 30.0): Buffer
    {
        $this->failures = [];

        return new Buffer(
            new BatchOptions(size: $size, flushMs: 60_000, maxQueue: $maxQueue, flushOnExit: false),
            50,
            $this->logger,
            $send,
            $budget,
            new Delivery(
                $this->logger,
                static function (int $ms): void {
                },
                function (DeliveryFailure $failure): void {
                    $this->failures[] = $failure;
                },
            ),
        );
    }

    /** @return callable(list<array<string, mixed>>): void */
    private static function throwing(\Throwable $error): callable
    {
        return static function (array $events) use ($error): void {
            throw $error;
        };
    }

    // -- the listener ------------------------------------------------------

    public function testA503ThatFailsTwiceReportsOnceAsTerminal(): void
    {
        $buffer = $this->buffer(self::throwing(
            new IntemptApiException('x', status: 503, body: '', retryAfterMs: 2_000)
        ));
        $buffer->enqueue(self::event('a'));
        $buffer->enqueue(self::event('b'));
        $result = $buffer->flush();

        self::assertCount(1, $this->failures, 'once per batch, never per attempt');
        $failure = $this->failures[0];
        self::assertSame('terminal', $failure->kind);
        self::assertSame(503, $failure->status);
        self::assertSame(2, $failure->eventCount);
        self::assertSame(['a', 'b'], $failure->eventIds);
        self::assertSame(2.0, $failure->retryAfter);
        self::assertTrue($failure->terminal);
        self::assertSame(['sent' => 0, 'failed' => 2], $result);
    }

    public function testAnAnsweredDropStatusIsTerminal(): void
    {
        $buffer = $this->buffer(self::throwing(new IntemptApiException('x', status: 400, body: '')));
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertCount(1, $this->failures);
        self::assertSame('terminal', $this->failures[0]->kind);
        self::assertSame(400, $this->failures[0]->status);
        self::assertNull($this->failures[0]->retryAfter);
    }

    public function testATimeoutAfterWriteIsDroppedClientSide(): void
    {
        $buffer = $this->buffer(self::throwing(new IntemptApiException('t', timedOut: true)));
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertSame('dropped', $this->failures[0]->kind);
        self::assertNull($this->failures[0]->status);
        self::assertTrue($this->failures[0]->terminal);
    }

    public function testAWaitPastTheCapIsDroppedAndCarriesTheAdvisedWait(): void
    {
        $buffer = $this->buffer(self::throwing(
            new IntemptApiException('x', status: 429, body: '', retryAfterMs: 6_000)
        ));
        $buffer->enqueue(self::event('a'));
        $buffer->flush();

        self::assertCount(1, $this->failures);
        self::assertSame('dropped', $this->failures[0]->kind);
        self::assertSame(429, $this->failures[0]->status);
        self::assertSame(6.0, $this->failures[0]->retryAfter);
    }

    public function testAnOfflineBatchReportsTransportOnceAcrossFlushes(): void
    {
        $buffer = $this->buffer(self::throwing(new IntemptApiException('refused', unreached: true)));
        $buffer->enqueue(self::event('a'));
        $first = $buffer->flush();
        $buffer->flush();
        $buffer->flush();

        self::assertCount(1, $this->failures, 'an offline host must not refire every flush');
        self::assertSame('transport', $this->failures[0]->kind);
        self::assertFalse($this->failures[0]->terminal);
        self::assertSame(['sent' => 0, 'failed' => 0], $first, 'kept is neither sent nor failed');
        self::assertSame(1, $buffer->size());
    }

    public function testAKeptBatchAbandonedAtCloseIsReportedAsDropped(): void
    {
        $buffer = $this->buffer(
            self::throwing(new IntemptApiException('refused', unreached: true)),
            budget: 0.05
        );
        $buffer->enqueue(self::event('a'));
        $buffer->enqueue(self::event('b'));
        $result = $buffer->close();

        self::assertSame(['transport', 'dropped'], array_map(static fn ($f) => $f->kind, $this->failures));
        self::assertSame(['a', 'b'], $this->failures[1]->eventIds);
        self::assertSame(['sent' => 0, 'failed' => 2], $result);
    }

    public function testAFullQueueReportsTheDroppedEvent(): void
    {
        $buffer = null;
        $buffer = $this->buffer(
            static function (array $events) use (&$buffer): void {
                $buffer->enqueue(self::event('overflow'));
            },
            size: 2,
            maxQueue: 2
        );
        $buffer->enqueue(self::event('a'));
        $buffer->enqueue(self::event('b'));

        self::assertCount(1, $this->failures);
        self::assertSame('dropped', $this->failures[0]->kind);
        self::assertSame(['overflow'], $this->failures[0]->eventIds);
    }

    public function testAListenerThatThrowsIsCaughtAndLogged(): void
    {
        $client = $this->client(['onDeliveryFailure' => static function (): void {
            throw new \RuntimeException('listener bug');
        }]);
        self::server()->expect(400, 'bad');

        self::assertFalse($client->track('purchase', ['userId' => 'u1']));
        self::assertTrue($this->logger->has('error', 'onDeliveryFailure listener threw'));
    }

    public function testANonCallableListenerIsRefused(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('onDeliveryFailure must be callable');
        $this->client(['onDeliveryFailure' => 'not a function']);
    }

    // -- track() says whether the event was accepted ----------------------

    public function testDirectModeTrackSaysWhetherTheServerTookIt(): void
    {
        $seen = [];
        $client = $this->client(['onDeliveryFailure' => static function (DeliveryFailure $f) use (&$seen): void {
            $seen[] = $f;
        }]);
        self::server()->expect(200);
        self::server()->expect(500, 'boom');

        self::assertTrue($client->track('a', ['userId' => 'u1']));
        self::assertFalse($client->track('b', ['userId' => 'u1']));
        self::assertCount(1, $seen);
        self::assertSame(500, $seen[0]->status);
        self::assertSame(1, $seen[0]->eventCount);
    }

    public function testDirectModeNeverReachedIsDroppedNotKept(): void
    {
        $seen = [];
        $client = $this->client([
            'host' => '127.0.0.1:1',
            'onDeliveryFailure' => static function (DeliveryFailure $f) use (&$seen): void {
                $seen[] = $f;
            },
        ]);

        self::assertFalse($client->track('a', ['userId' => 'u1']));
        self::assertSame('dropped', $seen[0]->kind);
        self::assertTrue($seen[0]->terminal);
    }

    public function testBatchedTrackSaysItWasQueued(): void
    {
        $client = $this->batchedClient();
        self::assertTrue($client->track('a', ['userId' => 'u1']));
        $client->optOut();
        self::assertFalse($client->track('b', ['userId' => 'u1']));
    }

    // -- flush() / close() results ----------------------------------------

    public function testFlushAndCloseReturnSentAndFailed(): void
    {
        $client = $this->batchedClient();
        self::server()->expect(200);
        $client->track('a', ['userId' => 'u1']);
        $client->track('b', ['userId' => 'u1']);

        self::assertSame(['sent' => 2, 'failed' => 0], $client->flush());
        self::assertSame(['sent' => 0, 'failed' => 0], $client->close());
    }

    public function testDirectModeFlushHasNothingToReport(): void
    {
        self::assertSame(['sent' => 0, 'failed' => 0], $this->client()->flush());
    }
}
