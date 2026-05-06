<?php
declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\Client;
use Intempt\IntemptException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function client(array $responses = [], array $config = []): Client
    {
        $this->history = [];
        $mock = new MockHandler($responses ?: [new Response(200, [], '{}')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        return new Client(array_merge([
            'orgName' => 'test-org',
            'projectName' => 'test-project',
            'apiKey' => 'abc123.xyz789',
            'sourceId' => 'src_001',
            'httpClient' => new GuzzleClient(['handler' => $stack]),
        ], $config));
    }

    private function lastBody(): array
    {
        $req = end($this->history)['request'];
        return json_decode((string) $req->getBody(), true);
    }

    private function lastUrl(): string
    {
        return (string) end($this->history)['request']->getUri();
    }

    // ── Construction ──

    public function testRequiresAllConfig(): void
    {
        foreach (['orgName', 'projectName', 'apiKey', 'sourceId'] as $key) {
            try {
                $cfg = ['orgName' => 'o', 'projectName' => 'p', 'apiKey' => 'k', 'sourceId' => 's'];
                unset($cfg[$key]);
                new Client($cfg);
                $this->fail("Expected InvalidArgumentException for missing {$key}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    public function testConstructsWithValidConfig(): void
    {
        $client = $this->client();
        $this->assertInstanceOf(Client::class, $client);
        $this->assertTrue($client->isOptedIn());
    }

    // ── track() ──

    public function testTrackQueuesEvent(): void
    {
        $c = $this->client();
        $c->track('prof_1', 'purchase', ['amount' => 99.99]);

        $events = $c->getPendingEvents();
        $this->assertCount(1, $events);
        $this->assertSame('purchase', $events[0]['name']);

        $p = $events[0]['payload'][0];
        $this->assertSame('prof_1', $p['profileId']);
        $this->assertSame(['amount' => 99.99], $p['data']);
        $this->assertArrayHasKey('eventId', $p);
        $this->assertArrayHasKey('timestamp', $p);
    }

    public function testTrackRejectsReservedName(): void
    {
        $c = $this->client();
        $c->track('prof_1', 'Identify', ['x' => 1]);
        $this->assertCount(0, $c->getPendingEvents());
    }

    public function testTrackRejectsEmptyProfileId(): void
    {
        $c = $this->client();
        $c->track('', 'purchase', ['amount' => 10]);
        $this->assertCount(0, $c->getPendingEvents());
    }

    public function testTrackRejectsEmptyEventTitle(): void
    {
        $c = $this->client();
        $c->track('prof_1', '', ['amount' => 10]);
        $this->assertCount(0, $c->getPendingEvents());
    }

    public function testTrackStripsNullData(): void
    {
        $c = $this->client();
        $c->track('prof_1', 'pageview');

        $p = $c->getPendingEvents()[0]['payload'][0];
        $this->assertArrayNotHasKey('data', $p);
    }

    // ── identify() ──

    public function testIdentifyDefaultEvent(): void
    {
        $c = $this->client();
        $c->identify('prof_1', 'john@example.com');

        $events = $c->getPendingEvents();
        $this->assertCount(1, $events);
        $this->assertSame('Identify', $events[0]['name']);
        $this->assertSame('john@example.com', $events[0]['payload'][0]['userId']);
    }

    public function testIdentifyCustomEventWithAttributes(): void
    {
        $c = $this->client();
        $c->identify('prof_1', 'john@example.com', 'signup', ['plan' => 'pro']);

        $e = $c->getPendingEvents()[0];
        $this->assertSame('signup', $e['name']);
        $this->assertSame(['plan' => 'pro'], $e['payload'][0]['userAttributes']);
    }

    public function testIdentifyRejectsReservedTitle(): void
    {
        $c = $this->client();
        $c->identify('prof_1', 'john@example.com', 'Identify');
        $this->assertCount(0, $c->getPendingEvents());
    }

    public function testIdentifyRejectsEmptyUserId(): void
    {
        $c = $this->client();
        $c->identify('prof_1', '');
        $this->assertCount(0, $c->getPendingEvents());
    }

    // ── group() ──

    public function testGroupDefaultEvent(): void
    {
        $c = $this->client();
        $c->group('prof_1', 'acc_456');

        $events = $c->getPendingEvents();
        $this->assertSame('Identify', $events[0]['name']);
        $this->assertSame('acc_456', $events[0]['payload'][0]['accountId']);
    }

    public function testGroupCustomEventWithAttributes(): void
    {
        $c = $this->client();
        $c->group('prof_1', 'acc_456', 'join-org', ['domain' => 'acme.com']);

        $e = $c->getPendingEvents()[0];
        $this->assertSame('join-org', $e['name']);
        $this->assertSame(['domain' => 'acme.com'], $e['payload'][0]['accountAttributes']);
    }

    public function testGroupRejectsEmptyAccountId(): void
    {
        $c = $this->client();
        $c->group('prof_1', '');
        $this->assertCount(0, $c->getPendingEvents());
    }

    // ── record() ──

    public function testRecordComposite(): void
    {
        $c = $this->client();
        $c->record('prof_1', 'battle', 'john', 'stark',
            ['winner' => 'stark'], ['rank' => 'king'], ['status' => 'victory']);

        $p = $c->getPendingEvents()[0]['payload'][0];
        $this->assertSame('john', $p['userId']);
        $this->assertSame('stark', $p['accountId']);
        $this->assertSame(['winner' => 'stark'], $p['data']);
        $this->assertSame(['rank' => 'king'], $p['userAttributes']);
        $this->assertSame(['status' => 'victory'], $p['accountAttributes']);
    }

    public function testRecordStripsNullFields(): void
    {
        $c = $this->client();
        $c->record('prof_1', 'simple');

        $p = $c->getPendingEvents()[0]['payload'][0];
        $this->assertArrayNotHasKey('userId', $p);
        $this->assertArrayNotHasKey('accountId', $p);
        $this->assertArrayNotHasKey('data', $p);
    }

    // ── alias() ──

    public function testAlias(): void
    {
        $c = $this->client();
        $c->alias('prof_1', 'john@example.com', 'aegon@example.com');

        $e = $c->getPendingEvents()[0];
        $this->assertSame('Identify', $e['name']);
        $this->assertSame('john@example.com', $e['payload'][0]['userId']);
        $this->assertSame('aegon@example.com', $e['payload'][0]['anotherUserId']);
    }

    public function testAliasRejectsEmptyParams(): void
    {
        $c = $this->client();
        $c->alias('', 'a', 'b');
        $c->alias('p', '', 'b');
        $c->alias('p', 'a', '');
        $this->assertCount(0, $c->getPendingEvents());
    }

    // ── consent() ──

    public function testConsentSendsImmediately(): void
    {
        $c = $this->client([new Response(200, [], '{}')]);
        $c->consent('prof_1', 'accept', 'marketing', '2025-12-31', 'john@example.com', 'Cookie consent');

        $this->assertCount(1, $this->history);
        $body = $this->lastBody();
        $this->assertSame('accept', $body['action']);
        $this->assertSame('marketing', $body['category']);
        $this->assertSame('prof_1', $body['profileId']);
        $this->assertSame('src_001', $body['sourceId']);
        $this->assertSame('2025-12-31', $body['validUntil']);
        $this->assertSame('PHP tracker', $body['source']);
        $this->assertSame('john@example.com', $body['email']);
        $this->assertSame('Cookie consent', $body['message']);
        $this->assertStringContainsString('/consents/data', $this->lastUrl());
    }

    public function testConsentDefaultsToUnlimited(): void
    {
        $c = $this->client([new Response(200, [], '{}')]);
        $c->consent('prof_1', 'reject');
        $this->assertSame('unlimited', $this->lastBody()['validUntil']);
    }

    public function testConsentRejectsInvalidAction(): void
    {
        $c = $this->client();
        $c->consent('prof_1', 'maybe');
        $this->assertCount(0, $this->history);
    }

    // ── Product tracking ──

    public function testProductAdd(): void
    {
        $c = $this->client();
        $result = $c->productAdd('prof_1', 'sku_001', 2);

        $this->assertNull($result);
        $e = $c->getPendingEvents()[0];
        $this->assertSame('Added to cart', $e['name']);
        $this->assertSame('sku_001', $e['payload'][0]['data']['productId']);
        $this->assertSame(2, $e['payload'][0]['data']['quantity']);
        $this->assertArrayHasKey('eventId', $e['payload'][0]);
    }

    public function testProductAddRejectsZeroQuantity(): void
    {
        $c = $this->client();
        $this->assertSame(['error' => true], $c->productAdd('prof_1', 'sku_001', 0));
        $this->assertCount(0, $c->getPendingEvents());
    }

    public function testProductAddRejectsNegativeQuantity(): void
    {
        $c = $this->client();
        $this->assertSame(['error' => true], $c->productAdd('prof_1', 'sku_001', -1));
    }

    public function testProductView(): void
    {
        $c = $this->client();
        $this->assertNull($c->productView('prof_1', 'sku_001'));
        $this->assertSame('Product viewed', $c->getPendingEvents()[0]['name']);
    }

    public function testProductViewRejectsEmpty(): void
    {
        $c = $this->client();
        $this->assertSame(['error' => true], $c->productView('', 'sku_001'));
        $this->assertSame(['error' => true], $c->productView('prof_1', ''));
    }

    public function testProductOrdered(): void
    {
        $c = $this->client();
        $result = $c->productOrdered('prof_1', [
            ['productId' => 'sku_001', 'quantity' => 2],
            ['productId' => 'sku_002', 'quantity' => 1],
        ]);

        $this->assertNull($result);
        $e = $c->getPendingEvents()[0];
        $this->assertSame('Product ordered', $e['name']);
        $this->assertCount(2, $e['payload']);
        $this->assertSame('sku_001', $e['payload'][0]['data']['productId']);
        $this->assertSame('sku_002', $e['payload'][1]['data']['productId']);
    }

    public function testProductOrderedRejectsEmpty(): void
    {
        $c = $this->client();
        $this->assertSame(['error' => true], $c->productOrdered('prof_1', []));
    }

    public function testProductOrderedRejectsInvalidProduct(): void
    {
        $c = $this->client();
        $this->assertSame(['error' => true], $c->productOrdered('prof_1', [
            ['productId' => '', 'quantity' => 1],
        ]));
    }

    // ── Batching ──

    public function testFlushSendsCorrectPayload(): void
    {
        $c = $this->client([new Response(200, [], '{}')]);
        $c->track('prof_1', 'purchase', ['amount' => 50]);
        $c->identify('prof_1', 'john@example.com');
        $c->flush();

        $body = $this->lastBody();
        $this->assertArrayHasKey('track', $body);
        $this->assertCount(2, $body['track']);
        $this->assertSame('purchase', $body['track'][0]['name']);
        $this->assertSame('Identify', $body['track'][1]['name']);
    }

    public function testFlushSendsToCorrectUrl(): void
    {
        $c = $this->client([new Response(200, [], '{}')]);
        $c->track('prof_1', 'test', []);
        $c->flush();

        $url = $this->lastUrl();
        $this->assertStringContainsString('/v1/test-org/projects/test-project/sources/src_001/track', $url);
        $this->assertStringContainsString('apiKey=abc123.xyz789', $url);
    }

    public function testAutoFlushAtBatchSize(): void
    {
        $c = $this->client([new Response(200, [], '{}')], ['batchSize' => 3]);

        $c->track('prof_1', 'e1', ['a' => 1]);
        $c->track('prof_1', 'e2', ['b' => 2]);
        $this->assertCount(2, $c->getPendingEvents());

        $c->track('prof_1', 'e3', ['c' => 3]);
        $this->assertCount(0, $c->getPendingEvents());
        $this->assertCount(1, $this->history);
    }

    public function testFlushNoopsWhenEmpty(): void
    {
        $c = $this->client();
        $c->flush();
        $this->assertCount(0, $this->history);
    }

    // ── Opt in/out ──

    public function testOptOutPreventsAllTracking(): void
    {
        $c = $this->client();
        $c->optOut();
        $this->assertFalse($c->isOptedIn());

        $c->track('prof_1', 'purchase', ['x' => 1]);
        $c->identify('prof_1', 'john@example.com');
        $c->group('prof_1', 'acc_456');
        $c->record('prof_1', 'event');
        $c->alias('prof_1', 'a', 'b');
        $c->productAdd('prof_1', 'sku', 1);
        $c->productView('prof_1', 'sku');
        $c->productOrdered('prof_1', [['productId' => 'sku', 'quantity' => 1]]);

        $this->assertCount(0, $c->getPendingEvents());
    }

    public function testOptInReenables(): void
    {
        $c = $this->client();
        $c->optOut();
        $c->optIn();
        $this->assertTrue($c->isOptedIn());

        $c->track('prof_1', 'purchase', ['x' => 1]);
        $this->assertCount(1, $c->getPendingEvents());
    }

    // ── Recommendation ──

    public function testRecommendation(): void
    {
        $data = ['items' => [['id' => '1', 'title' => 'Widget']]];
        $c = $this->client([new Response(200, [], json_encode($data))]);

        $result = $c->recommendation('prof_1', '848', 5, ['id', 'title']);

        $body = $this->lastBody();
        $this->assertSame('prof_1', $body['profileId']);
        $this->assertSame('src_001', $body['sourceId']);
        $this->assertSame(5, $body['limit']);
        $this->assertSame(['id', 'title'], $body['fields']);
        $this->assertStringContainsString('/feeds/848/data', $this->lastUrl());
        $this->assertSame($data, $result);
    }

    public function testRecommendationWithProductId(): void
    {
        $c = $this->client([new Response(200, [], '{}')]);
        $c->recommendation('prof_1', '848', 5, ['id'], 'prod_123');

        $this->assertSame('prod_123', $this->lastBody()['productId']);
    }

    public function testRecommendationReturnsErrorOnFailure(): void
    {
        $c = $this->client([new Response(500)], ['maxRetries' => 1]);
        $result = $c->recommendation('prof_1', '848', 5, ['id']);
        $this->assertSame(['error' => true], $result);
    }

    // ── Optimization ──

    public function testChooseExperimentsByGroups(): void
    {
        $data = ['choices' => [['name' => 'exp1', 'variant' => 'A']]];
        $c = $this->client([new Response(200, [], json_encode($data))]);

        $result = $c->chooseExperimentsByGroups('prof_1', ['group-1']);

        $body = $this->lastBody();
        $this->assertSame('prof_1', $body['identification']['profileId']);
        $this->assertSame('src_001', $body['identification']['sourceId']);
        $this->assertSame(['group-1'], $body['groups']);
        $this->assertSame('experiment', $body['optimizationType']);
        $this->assertSame('all', $body['device']);
        $this->assertStringContainsString('/optimization/choose-api', $this->lastUrl());
        $this->assertCount(1, $result);
    }

    public function testChooseExperimentsByNames(): void
    {
        $c = $this->client([new Response(200, [], '{"choices": []}')]);
        $c->chooseExperimentsByNames('prof_1', ['exp-1']);

        $body = $this->lastBody();
        $this->assertSame(['exp-1'], $body['names']);
        $this->assertSame('experiment', $body['optimizationType']);
    }

    public function testChoosePersonalizationsByGroups(): void
    {
        $c = $this->client([new Response(200, [], '{"choices": []}')]);
        $c->choosePersonalizationsByGroups('prof_1', ['g1']);
        $this->assertSame('personalization', $this->lastBody()['optimizationType']);
    }

    public function testChoosePersonalizationsByNames(): void
    {
        $c = $this->client([new Response(200, [], '{"choices": []}')]);
        $c->choosePersonalizationsByNames('prof_1', ['p1']);

        $body = $this->lastBody();
        $this->assertSame(['p1'], $body['names']);
        $this->assertSame('personalization', $body['optimizationType']);
    }

    public function testChooseReturnsNullForEmptyProfileId(): void
    {
        $c = $this->client();
        $this->assertNull($c->chooseExperimentsByGroups(''));
        $this->assertNull($c->chooseExperimentsByNames(''));
        $this->assertNull($c->choosePersonalizationsByGroups(''));
        $this->assertNull($c->choosePersonalizationsByNames(''));
        $this->assertCount(0, $this->history);
    }

    // ── Retry ──

    public function testRetriesOn500(): void
    {
        $c = $this->client([
            new Response(500),
            new Response(500),
            new Response(200, [], '{}'),
        ], ['maxRetries' => 3]);

        $c->track('prof_1', 'test', []);
        $c->flush();

        $this->assertCount(3, $this->history);
    }

    public function testRetriesOn429(): void
    {
        $c = $this->client([
            new Response(429),
            new Response(200, [], '{}'),
        ], ['maxRetries' => 3]);

        $c->track('prof_1', 'test', []);
        $c->flush();

        $this->assertCount(2, $this->history);
    }

    public function testThrowsAfterMaxRetries(): void
    {
        $c = $this->client([
            new Response(500),
            new Response(500),
            new Response(500),
        ], ['maxRetries' => 3]);

        $c->track('prof_1', 'test', []);

        $this->expectException(IntemptException::class);
        $this->expectExceptionMessage('status 500');
        $c->flush();
    }

    public function testDoesNotRetryClientErrors(): void
    {
        $c = $this->client([new Response(400)]);
        $c->track('prof_1', 'test', []);

        try {
            $c->flush();
            $this->fail('Expected IntemptException');
        } catch (IntemptException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertCount(1, $this->history);
        }
    }

    // ── Null stripping ──

    public function testPayloadsExcludeNullValues(): void
    {
        $c = $this->client();
        $c->identify('prof_1', 'john@example.com');

        $p = $c->getPendingEvents()[0]['payload'][0];
        $this->assertArrayNotHasKey('accountId', $p);
        $this->assertArrayNotHasKey('data', $p);
        $this->assertArrayNotHasKey('userAttributes', $p);
        $this->assertArrayNotHasKey('accountAttributes', $p);
        $this->assertArrayNotHasKey('anotherUserId', $p);
    }
}
