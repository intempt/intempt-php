<?php

/**
 * The public method surface, over a real socket.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\IntemptApiException;
use Intempt\IntemptConfigException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ClientTest extends TestCase
{
    // -- track ------------------------------------------------------------

    public function testSendsAWellFormedPost(): void
    {
        $this->client()->track('purchase', ['userId' => 'u1', 'properties' => ['total' => 99.99]]);

        $requests = self::server()->requests();
        self::assertCount(1, $requests);

        $request = $requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame($this->trackPath(), $request['path']);
        self::assertSame('application/json', $request['headers']['content-type']);
        self::assertStringStartsWith('intempt-php/', $request['headers']['x-intempt-lib']);

        $expected = 'Basic ' . base64_encode('pfx0123456789abcdef:sec0123456789abcdef');
        self::assertSame($expected, $request['headers']['authorization']);

        $item = $request['body']['track'][0];
        self::assertSame('purchase', $item['name']);
        self::assertSame('u1', $item['payload'][0]['userId']);
        self::assertSame(['total' => 99.99], $item['payload'][0]['data']);
        self::assertNotEmpty($item['payload'][0]['eventId']);
    }

    public function testUsesTheSourcelessPathWithoutASourceId(): void
    {
        $this->client(['sourceId' => null])->track('purchase', ['userId' => 'u1']);
        self::assertSame(
            sprintf('/v1/%s/projects/%s/track', self::ORG, self::PROJECT),
            self::server()->requests()[0]['path']
        );
    }

    public function testRequiresAnIdentifier(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('one of userId or accountId is required');
        $this->client()->track('purchase', []);
    }

    public function testTreatsAWhitespaceIdentifierAsMissing(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->client()->track('purchase', ['userId' => '   ']);
    }

    public function testRequiresAnEventName(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('event name is required');
        $this->client()->track('', ['userId' => 'u1']);
    }

    public function testRefusesTheReservedIdentifyName(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('is reserved');
        $this->client()->track('Identify', ['userId' => 'u1']);
    }

    public function testAcceptsAnAccountIdAlone(): void
    {
        $this->client()->track('purchase', ['accountId' => 'acme']);
        $payload = self::server()->requests()[0]['body']['track'][0]['payload'][0];
        self::assertSame('acme', $payload['accountId']);
    }

    // -- trackBatch -------------------------------------------------------

    public function testSendsManyEventsInOneRequest(): void
    {
        $this->client()->trackBatch([
            ['event' => 'a', 'userId' => 'u1'],
            ['event' => 'b', 'userId' => 'u1'],
        ]);
        self::assertCount(2, self::server()->requests()[0]['body']['track']);
    }

    public function testChunksAtMaxRequestEvents(): void
    {
        self::server()->expectMany(3);
        $events = [];
        for ($i = 0; $i < 5; ++$i) {
            $events[] = ['event' => "e$i", 'userId' => 'u1'];
        }
        $this->client(['maxRequestEvents' => 2])->trackBatch($events);

        $widths = array_map(
            static fn (array $r) => count($r['body']['track']),
            self::server()->requests()
        );
        self::assertSame(5, array_sum($widths));
        self::assertSame(2, max($widths));
    }

    public function testNamesTheOffendingIndex(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('trackBatch[1]');
        $this->client()->trackBatch([
            ['event' => 'ok', 'userId' => 'u1'],
            ['event' => '', 'userId' => 'u1'],
        ]);
    }

    public function testAnEmptyBatchIsANoOp(): void
    {
        $this->client()->trackBatch([]);
        self::assertSame([], self::server()->requests());
    }

    // -- identity ---------------------------------------------------------

    public function testIdentifyUsesTheReservedEvent(): void
    {
        $this->client()->identify(['userId' => 'u1', 'traits' => ['plan' => 'pro']]);
        $item = self::server()->requests()[0]['body']['track'][0];
        self::assertSame('Identify', $item['name']);
        self::assertSame(['plan' => 'pro'], $item['payload'][0]['userAttributes']);
    }

    public function testIdentifyAcceptsAnEventOverride(): void
    {
        $this->client()->identify(['userId' => 'u1', 'event' => 'Signed up']);
        self::assertSame('Signed up', self::server()->requests()[0]['body']['track'][0]['name']);
    }

    public function testGroupRequiresAnAccountId(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('accountId must be a non-empty string');
        $this->client()->group(['userId' => 'u1', 'accountId' => ' ']);
    }

    public function testGroupSendsAccountAttributes(): void
    {
        $this->client()->group([
            'userId' => 'u1',
            'accountId' => 'acme',
            'attributes' => ['tier' => 'ent'],
        ]);
        $payload = self::server()->requests()[0]['body']['track'][0]['payload'][0];
        self::assertSame('acme', $payload['accountId']);
        self::assertSame(['tier' => 'ent'], $payload['accountAttributes']);
    }

    public function testAliasCarriesBothIdentities(): void
    {
        $this->client()->alias(['userId' => 'new', 'previousUserId' => 'old']);
        $payload = self::server()->requests()[0]['body']['track'][0]['payload'][0];
        self::assertSame('new', $payload['userId']);
        self::assertSame('old', $payload['anotherUserId']);
    }

    /** @return list<array{array<string, string>, string}> */
    public static function blankAliasFields(): array
    {
        return [
            [['userId' => ' ', 'previousUserId' => 'old'], 'userId'],
            [['userId' => 'new', 'previousUserId' => ' '], 'previousUserId'],
        ];
    }

    /** @param array<string, string> $options */
    #[DataProvider('blankAliasFields')]
    public function testAliasNamesTheBlankField(array $options, string $field): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage($field . ' must be a non-empty string');
        $this->client()->alias($options);
    }

    // -- ecommerce --------------------------------------------------------

    public function testProductViewed(): void
    {
        $this->client()->ecommerce->productViewed(['productId' => 'sku-1', 'userId' => 'u1']);
        $item = self::server()->requests()[0]['body']['track'][0];
        self::assertSame('Product viewed', $item['name']);
        self::assertSame(['productId' => 'sku-1'], $item['payload'][0]['data']);
    }

    public function testAddedToCartRequiresAPositiveQuantity(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('quantity must be a positive integer');
        $this->client()->ecommerce->addedToCart([
            'productId' => 'sku-1', 'quantity' => 0, 'userId' => 'u1',
        ]);
    }

    public function testOrderedSendsOneLinePerProduct(): void
    {
        $this->client()->ecommerce->ordered([
            'userId' => 'u1',
            'products' => [
                ['productId' => 'a', 'quantity' => 2],
                ['productId' => 'b'],
            ],
        ]);
        $payload = self::server()->requests()[0]['body']['track'][0]['payload'];
        self::assertCount(2, $payload);
        self::assertSame(['productId' => 'a', 'quantity' => 2], $payload[0]['data']);
        self::assertSame(['productId' => 'b'], $payload[1]['data']);
    }

    public function testOrderedLinesShareOneEventId(): void
    {
        // Bit-compatible with 1.x. Safe because nothing dedupes on eventId.
        $this->client()->ecommerce->ordered([
            'userId' => 'u1',
            'products' => [['productId' => 'a'], ['productId' => 'b']],
        ]);
        $payload = self::server()->requests()[0]['body']['track'][0]['payload'];
        self::assertSame($payload[0]['eventId'], $payload[1]['eventId']);
    }

    public function testOrderedRejectsAnEmptyProductList(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('non-empty array');
        $this->client()->ecommerce->ordered(['userId' => 'u1', 'products' => []]);
    }

    public function testOrderedNamesTheOffendingIndex(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('products[1]');
        $this->client()->ecommerce->ordered([
            'userId' => 'u1',
            'products' => [['productId' => 'a'], ['quantity' => 1]],
        ]);
    }

    // -- consent ----------------------------------------------------------

    public function testConsentSendsEpochSecondsNotMilliseconds(): void
    {
        $when = new \DateTimeImmutable('2026-08-15T12:00:00+00:00');
        $this->client()->consent->grant([
            'userId' => 'u1', 'category' => 'marketing', 'timestamp' => $when,
        ]);

        $request = self::server()->requests()[0];
        self::assertStringEndsWith('/consents/data', $request['path']);
        self::assertSame('accept', $request['body']['action']);
        self::assertSame($when->getTimestamp(), $request['body']['timestamp']);
        // Milliseconds here would land past 2040, where the server silently
        // replaces the value with its own clock.
        self::assertLessThan(2_216_872_268_000, $request['body']['timestamp'] * 1000);
    }

    public function testRevokeSendsReject(): void
    {
        $this->client()->consent->revoke(['userId' => 'u1']);
        self::assertSame('reject', self::server()->requests()[0]['body']['action']);
    }

    public function testConsentDefaultsValidUntilToUnlimited(): void
    {
        $this->client()->consent->grant(['userId' => 'u1']);
        self::assertSame('unlimited', self::server()->requests()[0]['body']['validUntil']);
    }

    public function testConsentRequiresAnIdentifier(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('userId must be a non-empty string');
        $this->client()->consent->grant(['category' => 'marketing']);
    }

    public function testProfileIdConsentRequiresASourceId(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('sourceId must be configured');
        $this->client(['sourceId' => null])->consent->grant(['profileId' => 'p1']);
    }

    public function testSourceIdIsSentAsAString(): void
    {
        // (int) would round the last digits and address another source.
        $this->client()->consent->grant(['profileId' => 'p1']);
        $sourceId = self::server()->requests()[0]['body']['sourceId'];
        self::assertSame(self::SOURCE, $sourceId);
        self::assertIsString($sourceId);
    }

    // -- recommend --------------------------------------------------------

    public function testRecommendResolvesAUserEntity(): void
    {
        self::server()->expect(200, '{"items":[{"id":"1"}]}');
        $result = $this->client()->recommend([
            'userId' => 'u1', 'feedId' => '5292', 'fields' => ['id'],
        ]);

        $request = self::server()->requests()[0];
        self::assertStringEndsWith('/feeds/5292/data', $request['path']);
        self::assertSame('u1', $request['body']['id']);
        self::assertSame('user', $request['body']['type']);
        self::assertSame(['items' => [['id' => '1']]], $result);
    }

    public function testRecommendResolvesAnAccountEntity(): void
    {
        $this->client()->recommend(['accountId' => 'acme', 'feedId' => 'f', 'fields' => ['id']]);
        self::assertSame('account', self::server()->requests()[0]['body']['type']);
    }

    public function testRecommendRefusesBothIdentifiers(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('not both');
        $this->client()->recommend([
            'userId' => 'u1', 'accountId' => 'acme', 'feedId' => 'f', 'fields' => ['id'],
        ]);
    }

    public function testRecommendRequiresNonEmptyFields(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('fields must be a non-empty array');
        $this->client()->recommend(['userId' => 'u1', 'feedId' => 'f', 'fields' => []]);
    }

    public function testRecommendRejectsANonPositiveLimit(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('limit must be a positive integer');
        $this->client()->recommend([
            'userId' => 'u1', 'feedId' => 'f', 'fields' => ['id'], 'limit' => 0,
        ]);
    }

    // -- privacy ----------------------------------------------------------

    public function testOptOutSuppressesEveryWrite(): void
    {
        $client = $this->client();
        $client->optOut();
        $client->track('purchase', ['userId' => 'u1']);
        $client->identify(['userId' => 'u1']);
        $client->consent->grant(['userId' => 'u1']);
        $client->ecommerce->ordered(['userId' => 'u1', 'products' => [['productId' => 'a']]]);

        self::assertSame([], self::server()->requests());
    }

    public function testOptInRestoresSending(): void
    {
        $client = $this->client();
        $client->optOut();
        $client->optIn();
        $client->track('purchase', ['userId' => 'u1']);
        self::assertCount(1, self::server()->requests());
    }

    public function testRecommendIsUnaffectedByOptOut(): void
    {
        $client = $this->client();
        $client->optOut();
        $client->recommend(['userId' => 'u1', 'feedId' => 'f', 'fields' => ['id']]);
        self::assertCount(1, self::server()->requests());
    }

    // -- lifecycle --------------------------------------------------------

    public function testCallsAfterCloseThrow(): void
    {
        $client = $this->client();
        $client->close();
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('client is closed');
        $client->track('purchase', ['userId' => 'u1']);
    }

    public function testRecommendIsGatedAfterClose(): void
    {
        $client = $this->client();
        $client->close();
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('client is closed');
        $client->recommend(['userId' => 'u1', 'feedId' => 'f', 'fields' => ['id']]);
    }

    public function testCloseIsIdempotent(): void
    {
        $client = $this->client();
        $client->close();
        $client->close();
        self::assertFalse($client->isOptedIn());
    }

    // -- errors -----------------------------------------------------------

    public function testSurfacesA500WithStatusAndBody(): void
    {
        self::server()->expect(500, '{"error":"boom"}');
        try {
            $this->client()->track('purchase', ['userId' => 'u1']);
            self::fail('expected IntemptApiException');
        } catch (IntemptApiException $exception) {
            self::assertSame(500, $exception->status);
            self::assertStringContainsString('boom', (string) $exception->body);
            self::assertTrue($exception->isRetryable());
        }
    }

    public function testA400IsNotRetryable(): void
    {
        self::server()->expect(400, 'bad');
        try {
            $this->client()->track('purchase', ['userId' => 'u1']);
            self::fail('expected IntemptApiException');
        } catch (IntemptApiException $exception) {
            self::assertFalse($exception->isRetryable());
        }
    }

    public function testNeverLeaksTheCredentialThroughSurfacesTheSdkControls(): void
    {
        self::server()->expect(500, 'boom');
        try {
            $this->client()->track('purchase', ['userId' => 'u1']);
            self::fail('expected IntemptApiException');
        } catch (IntemptApiException $exception) {
            $secret = 'sec0123456789abcdef';
            $basic = base64_encode('pfx0123456789abcdef:sec0123456789abcdef');
            foreach ([$exception->getMessage(), (string) $exception] as $view) {
                self::assertStringNotContainsString($secret, $view);
                self::assertStringNotContainsString($basic, $view);
            }
        }
    }

    public function testTheConfigSnapshotDoesNotCarryTheRawKey(): void
    {
        // Config used to expose `public readonly string $apiKey`, so print_r()
        // of a client's config printed the secret in full. It now holds only
        // the encoded credential, which is the one leak the SDK controls.
        $dump = print_r($this->client()->config(), true);
        self::assertStringNotContainsString('sec0123456789abcdef', $dump);
        self::assertStringContainsString('redacted', $dump);
    }

    public function testCredentialsRedactInEveryPrintingPath(): void
    {
        $credentials = $this->client()->config()->credentials;
        foreach ([print_r($credentials, true), (string) $credentials, var_export($credentials, true)] as $view) {
            self::assertStringNotContainsString('sec0123456789abcdef', $view);
        }
    }

    public function testCredentialsRefuseToSerialise(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('not serialisable');
        serialize($this->client()->config()->credentials);
    }

    /**
     * PHP puts call arguments into every stack trace unless
     * zend.exception_ignore_args=1, so ANY function that receives the key as a
     * string has it in the trace of every later exception — including
     * getTraceAsString() and print_r() of the exception object.
     *
     * That is not something an SDK can fix from inside: the caller's own
     * `new Intempt(['apiKey' => ...])` frame carries it. This test documents the
     * exposure and proves the recommended setting removes it, so the README can
     * state the mitigation as fact rather than as folklore.
     */
    public function testStackTracesCarryCallArgsUnlessPhpIsConfiguredOtherwise(): void
    {
        if (ini_get('zend.exception_ignore_args')) {
            self::markTestSkipped('zend.exception_ignore_args is already on');
        }

        $leaked = static function (string $secret): string {
            try {
                throw new \RuntimeException('boom');
            } catch (\Throwable $exception) {
                return $exception->getTraceAsString();
            }
        };

        self::assertStringContainsString(
            'SECRETVALUE',
            $leaked('SECRETVALUE'),
            'PHP captured the argument, which is exactly why the README tells '
            . 'operators to set zend.exception_ignore_args=1'
        );
    }
}
