<?php
declare(strict_types=1);

namespace Intempt;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Client as GuzzleClient;

class Client
{
    private string $organization;
    private string $project;
    private string $sourceId;
    private string $apiKey;
    private string $baseUrl;
    private int $batchSize;
    private ClientInterface $http;
    /** @var array<array{name: string, payload: array<mixed>}> */
    private array $queue = [];

    /**
     * @param array{
     *   organization: string,
     *   project: string,
     *   sourceId: string,
     *   apiKey: string,
     *   baseUrl?: string,
     *   batchSize?: int,
     *   httpClient?: ClientInterface,
     * } $config
     */
    public function __construct(array $config)
    {
        foreach (['organization', 'project', 'sourceId', 'apiKey'] as $key) {
            if (empty($config[$key])) {
                throw new \InvalidArgumentException("Missing required config: {$key}");
            }
        }

        $this->organization = $config['organization'];
        $this->project = $config['project'];
        $this->sourceId = $config['sourceId'];
        $this->apiKey = $config['apiKey'];
        $this->baseUrl = $config['baseUrl'] ?? 'https://api.intempt.com';
        $this->batchSize = $config['batchSize'] ?? 20;
        $this->http = $config['httpClient'] ?? new GuzzleClient();
    }

    public function track(string $eventName, array $payload = []): void
    {
        $this->enqueue($eventName, array_merge([
            'eventId' => $this->uuid(),
            'timestamp' => (int)(microtime(true) * 1000),
        ], $payload));
    }

    public function identify(string $userId, array $userAttributes = []): void
    {
        $this->enqueue('Identify user', [
            'eventId' => $this->uuid(),
            'userId' => $userId,
            'timestamp' => (int)(microtime(true) * 1000),
            'userAttributes' => json_encode($userAttributes),
        ]);
    }

    public function group(string $accountId, array $accountAttributes = []): void
    {
        $this->enqueue('Identify account', [
            'eventId' => $this->uuid(),
            'accountId' => $accountId,
            'timestamp' => (int)(microtime(true) * 1000),
            'accountAttributes' => json_encode($accountAttributes),
        ]);
    }

    public function alias(string $userId, string $anotherUserId): void
    {
        $this->enqueue('Alias', [
            'eventId' => $this->uuid(),
            'userId' => $userId,
            'anotherUserId' => $anotherUserId,
            'timestamp' => (int)(microtime(true) * 1000),
        ]);
    }

    public function flush(): void
    {
        if (empty($this->queue)) return;

        $grouped = [];
        foreach ($this->queue as $item) {
            $grouped[$item['name']][] = $item['payload'];
        }

        $track = [];
        foreach ($grouped as $name => $payloads) {
            $track[] = ['name' => $name, 'payload' => $payloads];
        }

        $url = sprintf(
            '%s/%s/projects/%s/sources/%s/track?apiKey=%s',
            $this->baseUrl,
            $this->organization,
            $this->project,
            $this->sourceId,
            $this->apiKey,
        );

        $this->http->request('POST', $url, [
            'json' => ['track' => $track],
            'timeout' => 10,
        ]);

        $this->queue = [];
    }

    /** @return array<array{name: string, payload: array<mixed>}> */
    public function getPendingEvents(): array
    {
        return $this->queue;
    }

    private function enqueue(string $name, array $payload): void
    {
        $this->queue[] = ['name' => $name, 'payload' => $payload];

        if (count($this->queue) >= $this->batchSize) {
            $this->flush();
        }
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        );
    }

    public function __destruct()
    {
        $this->flush();
    }
}
