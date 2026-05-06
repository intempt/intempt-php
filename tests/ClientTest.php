<?php
declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\Client;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function testConstructorRequiresConfig(): void
    {
        $client = new Client([
            'organization' => 'test-org',
            'project' => 'test-project',
            'sourceId' => 'src_123',
            'apiKey' => 'test-key',
        ]);

        $this->assertInstanceOf(Client::class, $client);
    }

    public function testConstructorThrowsOnMissingConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client([]);
    }

    public function testTrackQueuesEvent(): void
    {
        $client = new Client([
            'organization' => 'test-org',
            'project' => 'test-project',
            'sourceId' => 'src_123',
            'apiKey' => 'test-key',
        ]);

        $client->track('Purchase', [
            'userId' => 'user@test.com',
            'data' => ['amount' => 35],
        ]);

        $this->assertCount(1, $client->getPendingEvents());
    }

    public function testIdentifyQueuesEvent(): void
    {
        $client = new Client([
            'organization' => 'test-org',
            'project' => 'test-project',
            'sourceId' => 'src_123',
            'apiKey' => 'test-key',
        ]);

        $client->identify('user@test.com', ['name' => 'Jane']);
        $this->assertCount(1, $client->getPendingEvents());
    }

    public function testGroupQueuesEvent(): void
    {
        $client = new Client([
            'organization' => 'test-org',
            'project' => 'test-project',
            'sourceId' => 'src_123',
            'apiKey' => 'test-key',
        ]);

        $client->group('acc_123', ['company_name' => 'Acme']);
        $this->assertCount(1, $client->getPendingEvents());
    }

    public function testAliasQueuesEvent(): void
    {
        $client = new Client([
            'organization' => 'test-org',
            'project' => 'test-project',
            'sourceId' => 'src_123',
            'apiKey' => 'test-key',
        ]);

        $client->alias('user@test.com', 'anon-id-123');
        $this->assertCount(1, $client->getPendingEvents());
    }

    public function testBatchFlushesAtThreshold(): void
    {
        $client = new Client([
            'organization' => 'test-org',
            'project' => 'test-project',
            'sourceId' => 'src_123',
            'apiKey' => 'test-key',
            'batchSize' => 3,
            'httpClient' => new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create(
                new \GuzzleHttp\Handler\MockHandler([
                    new \GuzzleHttp\Psr7\Response(200, [], '{}'),
                ])
            )]),
        ]);

        $client->track('Event1', ['userId' => 'u1']);
        $client->track('Event2', ['userId' => 'u2']);
        $this->assertCount(2, $client->getPendingEvents());

        $client->track('Event3', ['userId' => 'u3']);
        $this->assertCount(0, $client->getPendingEvents());
    }
}
