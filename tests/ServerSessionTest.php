<?php

/**
 * A server SDK has no session (INT-7446, CONTRACT-R2 §S5): no write carries a sessionId, even
 * when a caller passes one, and the SDK never invents one. A caller-supplied sessionId is accepted
 * on flag calls only, where it scopes exposure counting.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\FlagContext;

final class ServerSessionTest extends TestCase
{
    /** @param mixed $value */
    private static function assertNoSessionId(mixed $value, string $where): void
    {
        $json = (string) json_encode($value);
        self::assertStringNotContainsStringIgnoringCase('session', $json, $where);
    }

    public function testNoWriteCarriesASessionIdEvenWhenOneIsPassed(): void
    {
        self::server()->expectMany(8);
        $client = $this->client();
        $ids = ['userId' => 'u1', 'sessionId' => 'caller-session'];

        $client->track('purchase', $ids);
        $client->trackBatch([['event' => 'a'] + $ids]);
        $client->identify($ids);
        $client->group($ids + ['accountId' => 'acme']);
        $client->ecommerce->productViewed($ids + ['productId' => 'sku-1']);
        $client->consent->grant($ids);

        $requests = self::server()->requests();
        self::assertCount(6, $requests);
        foreach ($requests as $request) {
            self::assertNoSessionId($request['body'], $request['path']);
        }
    }

    public function testBatchedWritesCarryNoSessionIdEither(): void
    {
        self::server()->expect(200);
        $client = $this->batchedClient();
        $client->track('purchase', ['userId' => 'u1', 'sessionId' => 'caller-session']);
        $client->flush();

        self::assertNoSessionId(self::server()->requests()[0]['body'], 'batched track');
    }

    public function testAFlagCallForwardsTheCallersSessionIdAndInventsNone(): void
    {
        self::server()->expect(200, '{"choices":[]}');
        self::server()->expect(200, '{"choices":[]}');
        $client = $this->client();
        $client->variation('k', new FlagContext(userId: 'u1', sessionId: 'sess-9'), 'd');
        $client->variation('k', new FlagContext(userId: 'u1'), 'd');

        [$with, $without] = self::server()->requests();
        self::assertSame('sess-9', $with['body']['sessionId']);
        self::assertArrayNotHasKey('sessionId', $without['body']);
    }
}
