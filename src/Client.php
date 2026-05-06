<?php
declare(strict_types=1);

namespace Intempt;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;

final class Client
{
    private readonly string $orgName;
    private readonly string $projectName;
    private readonly string $apiKey;
    private readonly string $sourceId;
    private readonly string $baseUrl;
    private readonly int $batchSize;
    private readonly int $timeout;
    private readonly int $maxRetries;
    private ClientInterface $http;
    private bool $doNotTrack = false;

    /** @var list<array{name: string, payload: list<array<string, mixed>>}> */
    private array $queue = [];

    private const RESERVED_EVENT = 'Identify';
    private const CONSENT_SOURCE = 'PHP tracker';

    /**
     * @param array{
     *   orgName: string,
     *   projectName: string,
     *   apiKey: string,
     *   sourceId: string,
     *   baseUrl?: string,
     *   batchSize?: int,
     *   timeout?: int,
     *   maxRetries?: int,
     *   httpClient?: ClientInterface,
     * } $config
     */
    public function __construct(array $config)
    {
        foreach (['orgName', 'projectName', 'apiKey', 'sourceId'] as $key) {
            if (empty($config[$key])) {
                throw new \InvalidArgumentException("Missing required config: {$key}");
            }
        }

        $this->orgName = $config['orgName'];
        $this->projectName = $config['projectName'];
        $this->apiKey = $config['apiKey'];
        $this->sourceId = $config['sourceId'];
        $this->baseUrl = rtrim($config['baseUrl'] ?? 'https://api.intempt.com', '/');
        $this->batchSize = $config['batchSize'] ?? 20;
        $this->timeout = $config['timeout'] ?? 10;
        $this->maxRetries = $config['maxRetries'] ?? 3;
        $this->http = $config['httpClient'] ?? new GuzzleClient();
    }

    public function track(string $profileId, string $eventTitle, array $data = []): void
    {
        if (!$profileId || !$eventTitle || !$this->verifyEventTitle($eventTitle)) {
            return;
        }

        $this->enqueueTrack($eventTitle, [
            'eventId' => $this->uuid(),
            'timestamp' => $this->timestamp(),
            'profileId' => $profileId,
            'data' => $data ?: null,
        ]);
    }

    public function identify(
        string $profileId,
        string $userId,
        ?string $eventTitle = null,
        ?array $userAttributes = null,
    ): void {
        if (!$profileId || !$userId) {
            return;
        }
        if ($eventTitle !== null && !$this->verifyEventTitle($eventTitle)) {
            return;
        }

        $this->enqueueTrack($eventTitle ?? self::RESERVED_EVENT, [
            'eventId' => $this->uuid(),
            'timestamp' => $this->timestamp(),
            'profileId' => $profileId,
            'userId' => $userId,
            'userAttributes' => $userAttributes,
        ]);
    }

    public function group(
        string $profileId,
        string $accountId,
        ?string $eventTitle = null,
        ?array $accountAttributes = null,
    ): void {
        if (!$profileId || !$accountId) {
            return;
        }
        if ($eventTitle !== null && !$this->verifyEventTitle($eventTitle)) {
            return;
        }

        $this->enqueueTrack($eventTitle ?? self::RESERVED_EVENT, [
            'eventId' => $this->uuid(),
            'timestamp' => $this->timestamp(),
            'profileId' => $profileId,
            'accountId' => $accountId,
            'accountAttributes' => $accountAttributes,
        ]);
    }

    public function record(
        string $profileId,
        string $eventTitle,
        ?string $userId = null,
        ?string $accountId = null,
        ?array $data = null,
        ?array $userAttributes = null,
        ?array $accountAttributes = null,
    ): void {
        if (!$profileId || !$eventTitle || !$this->verifyEventTitle($eventTitle)) {
            return;
        }

        $this->enqueueTrack($eventTitle, [
            'eventId' => $this->uuid(),
            'timestamp' => $this->timestamp(),
            'profileId' => $profileId,
            'userId' => $userId,
            'accountId' => $accountId,
            'data' => $data,
            'userAttributes' => $userAttributes,
            'accountAttributes' => $accountAttributes,
        ]);
    }

    public function alias(string $profileId, string $userId, string $anotherUserId): void
    {
        if (!$profileId || !$userId || !$anotherUserId) {
            return;
        }

        $this->enqueueTrack(self::RESERVED_EVENT, [
            'eventId' => $this->uuid(),
            'timestamp' => $this->timestamp(),
            'profileId' => $profileId,
            'userId' => $userId,
            'anotherUserId' => $anotherUserId,
        ]);
    }

    public function consent(
        string $profileId,
        string $action,
        ?string $category = null,
        ?string $validUntil = null,
        ?string $email = null,
        ?string $message = null,
    ): void {
        if (!$profileId || !in_array($action, ['accept', 'reject'], true)) {
            return;
        }

        $this->sendRequest('consents/data', [
            'action' => $action,
            'category' => $category,
            'timestamp' => $this->timestamp(),
            'profileId' => $profileId,
            'sourceId' => $this->sourceId,
            'validUntil' => $validUntil ?? 'unlimited',
            'source' => self::CONSENT_SOURCE,
            'email' => $email,
            'message' => $message,
        ]);
    }

    /** @return array{error: true}|null */
    public function productAdd(string $profileId, string $productId, int $quantity): ?array
    {
        if (!$profileId || !$productId || $quantity <= 0) {
            return ['error' => true];
        }

        return $this->productTrack('Added to cart', $profileId, [
            ['productId' => $productId, 'quantity' => $quantity],
        ]);
    }

    /** @return array{error: true}|null */
    public function productView(string $profileId, string $productId): ?array
    {
        if (!$profileId || !$productId) {
            return ['error' => true];
        }

        return $this->productTrack('Product viewed', $profileId, [
            ['productId' => $productId],
        ]);
    }

    /**
     * @param list<array{productId: string, quantity: int}> $products
     * @return array{error: true}|null
     */
    public function productOrdered(string $profileId, array $products): ?array
    {
        if (!$profileId || empty($products)) {
            return ['error' => true];
        }

        foreach ($products as $p) {
            if (empty($p['productId']) || (isset($p['quantity']) && $p['quantity'] <= 0)) {
                return ['error' => true];
            }
        }

        return $this->productTrack('Product ordered', $profileId, $products);
    }

    /**
     * @param list<string> $fields
     * @return array<mixed>|array{error: true}
     */
    public function recommendation(
        string $profileId,
        string $feedId,
        int $quantity,
        array $fields,
        ?string $productId = null,
    ): array {
        $body = [
            'profileId' => $profileId,
            'fields' => $fields,
            'sourceId' => $this->sourceId,
            'limit' => $quantity,
        ];

        if ($productId !== null) {
            $body['productId'] = $productId;
        }

        try {
            return $this->sendRequest("feeds/{$feedId}/data", $body);
        } catch (\Throwable) {
            return ['error' => true];
        }
    }

    /** @param list<string>|null $groups */
    public function chooseExperimentsByGroups(string $profileId, ?array $groups = null): ?array
    {
        if (!$profileId) return null;
        return $this->chooseOptimization($profileId, 'experiment', $groups);
    }

    /** @param list<string>|null $names */
    public function chooseExperimentsByNames(string $profileId, ?array $names = null): ?array
    {
        if (!$profileId) return null;
        return $this->chooseOptimization($profileId, 'experiment', null, $names);
    }

    /** @param list<string>|null $groups */
    public function choosePersonalizationsByGroups(string $profileId, ?array $groups = null): ?array
    {
        if (!$profileId) return null;
        return $this->chooseOptimization($profileId, 'personalization', $groups);
    }

    /** @param list<string>|null $names */
    public function choosePersonalizationsByNames(string $profileId, ?array $names = null): ?array
    {
        if (!$profileId) return null;
        return $this->chooseOptimization($profileId, 'personalization', null, $names);
    }

    public function optIn(): void
    {
        $this->doNotTrack = false;
    }

    public function optOut(): void
    {
        $this->doNotTrack = true;
    }

    public function isOptedIn(): bool
    {
        return !$this->doNotTrack;
    }

    public function flush(): void
    {
        if (empty($this->queue)) return;

        $batch = $this->queue;
        $this->queue = [];

        $this->sendRequest("sources/{$this->sourceId}/track", ['track' => $batch]);
    }

    /** @return list<array{name: string, payload: list<array<string, mixed>>}> */
    public function getPendingEvents(): array
    {
        return $this->queue;
    }

    public function __destruct()
    {
        try {
            $this->flush();
        } catch (\Throwable) {
        }
    }

    private function enqueueTrack(string $name, array $payload): void
    {
        if ($this->doNotTrack) return;

        $payload = array_filter($payload, fn($v) => $v !== null);

        $this->queue[] = [
            'name' => $name,
            'payload' => [$payload],
        ];

        if (count($this->queue) >= $this->batchSize) {
            $this->flush();
        }
    }

    /** @return array{error: true}|null */
    private function productTrack(string $name, string $profileId, array $products): ?array
    {
        if ($this->doNotTrack) return null;

        $eventId = $this->uuid();
        $timestamp = $this->timestamp();

        $payload = array_map(fn(array $product) => [
            'eventId' => $eventId,
            'timestamp' => $timestamp,
            'profileId' => $profileId,
            'data' => $product,
        ], $products);

        $this->queue[] = [
            'name' => $name,
            'payload' => $payload,
        ];

        if (count($this->queue) >= $this->batchSize) {
            $this->flush();
        }

        return null;
    }

    private function chooseOptimization(
        string $profileId,
        string $type,
        ?array $groups = null,
        ?array $names = null,
    ): ?array {
        $body = [
            'identification' => [
                'profileId' => $profileId,
                'sourceId' => $this->sourceId,
            ],
            'groups' => $groups,
            'names' => $names,
            'optimizationType' => $type,
            'device' => 'all',
        ];

        try {
            $response = $this->sendRequest('optimization/choose-api', $body);
            return $response['choices'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<mixed> */
    private function sendRequest(string $path, array $body): array
    {
        $url = sprintf(
            '%s/v1/%s/projects/%s/%s?apiKey=%s',
            $this->baseUrl,
            $this->orgName,
            $this->projectName,
            $path,
            $this->apiKey,
        );

        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = $this->http->request('POST', $url, [
                    'json' => $body,
                    'timeout' => $this->timeout,
                    'http_errors' => false,
                ]);

                $statusCode = $response->getStatusCode();

                if ($statusCode >= 200 && $statusCode < 300) {
                    $responseBody = (string) $response->getBody();
                    return json_decode($responseBody, true) ?: [];
                }

                if ($statusCode === 429 || $statusCode >= 500) {
                    $lastException = new IntemptException(
                        "Request failed with status {$statusCode}",
                        $statusCode,
                    );
                    if ($attempt < $this->maxRetries) {
                        usleep($this->backoffDelay($attempt));
                    }
                    continue;
                }

                throw new IntemptException(
                    "Request failed with status {$statusCode}",
                    $statusCode,
                );
            } catch (ConnectException $e) {
                $lastException = new IntemptException(
                    "Network error: {$e->getMessage()}",
                    0,
                    $e,
                );
                if ($attempt < $this->maxRetries) {
                    usleep($this->backoffDelay($attempt));
                }
            } catch (IntemptException $e) {
                throw $e;
            } catch (GuzzleException $e) {
                throw new IntemptException($e->getMessage(), 0, $e);
            }
        }

        throw $lastException ?? new IntemptException('Request failed after retries');
    }

    private function backoffDelay(int $attempt): int
    {
        return (int) (200_000 * pow(2, $attempt - 1));
    }

    private function verifyEventTitle(string $eventTitle): bool
    {
        return $eventTitle !== self::RESERVED_EVENT;
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

    private function timestamp(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
