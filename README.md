# Intempt PHP SDK

Server-side PHP client for the [Intempt](https://intempt.com) analytics platform. Matches the API surface of the [Node.js SDK](https://github.com/intempt/sdk-node).

## Installation

```bash
composer require intempt/intempt-php
```

## Quick Start

```php
use Intempt\Client;

$intempt = new Client([
    'orgName'     => 'my-org',
    'projectName' => 'my-project',
    'apiKey'      => getenv('INTEMPT_API_KEY'),  // format: id.secret
    'sourceId'    => '684508596718616576',
]);

$intempt->track('prof_123', 'purchase', [
    'amount' => 99.99,
    'currency' => 'USD',
]);

$intempt->identify('prof_123', 'john@example.com', null, [
    'name' => 'John Snow',
    'plan' => 'pro',
]);

// Events auto-flush on destruct, or call manually:
$intempt->flush();
```

## Configuration

| Key | Required | Default | Description |
|-----|----------|---------|-------------|
| `orgName` | Yes | — | Organization name |
| `projectName` | Yes | — | Project name |
| `apiKey` | Yes | — | API key (`id.secret` format) |
| `sourceId` | Yes | — | Source/tracker ID |
| `baseUrl` | No | `https://api.intempt.com` | API base URL |
| `batchSize` | No | `20` | Events queued before auto-flush |
| `timeout` | No | `10` | HTTP timeout in seconds |
| `maxRetries` | No | `3` | Retries on 429/5xx errors |

## Methods

### Event Tracking

```php
// Custom event
$intempt->track(string $profileId, string $eventTitle, array $data = []);

// Identify user
$intempt->identify(string $profileId, string $userId, ?string $eventTitle = null, ?array $userAttributes = null);

// Associate with account
$intempt->group(string $profileId, string $accountId, ?string $eventTitle = null, ?array $accountAttributes = null);

// Composite event (track + identify + group in one call)
$intempt->record(string $profileId, string $eventTitle, ?string $userId = null,
    ?string $accountId = null, ?array $data = null,
    ?array $userAttributes = null, ?array $accountAttributes = null);

// Merge user identities
$intempt->alias(string $profileId, string $userId, string $anotherUserId);
```

### Consent (GDPR/CCPA)

```php
$intempt->consent(string $profileId, string $action, ?string $category = null,
    ?string $validUntil = null, ?string $email = null, ?string $message = null);

// Accept marketing consent
$intempt->consent('prof_123', 'accept', 'marketing', '2025-12-31', 'john@example.com');

// Reject all — sent with validUntil: "unlimited"
$intempt->consent('prof_123', 'reject');
```

Consent calls bypass the batch queue and send immediately.

### Product Events

```php
// Add to cart — returns null on success, ['error' => true] on validation failure
$intempt->productAdd(string $profileId, string $productId, int $quantity);

// View product
$intempt->productView(string $profileId, string $productId);

// Order placed
$intempt->productOrdered(string $profileId, [
    ['productId' => 'sku_001', 'quantity' => 2],
    ['productId' => 'sku_002', 'quantity' => 1],
]);
```

### Recommendations

```php
$items = $intempt->recommendation('prof_123', $feedId, $quantity, ['id', 'title', 'price']);
```

### Experiments & Personalizations

```php
$choices = $intempt->chooseExperimentsByGroups('prof_123', ['homepage']);
$choices = $intempt->chooseExperimentsByNames('prof_123', ['checkout-cta']);
$choices = $intempt->choosePersonalizationsByGroups('prof_123', ['onboarding']);
$choices = $intempt->choosePersonalizationsByNames('prof_123', ['welcome-banner']);
```

### Privacy Controls

```php
$intempt->optOut();                // Disable all tracking
$intempt->optIn();                 // Re-enable tracking
$intempt->isOptedIn();             // Check status
```

When opted out, `track`, `identify`, `group`, `record`, `alias`, and product methods silently no-op. Consent calls are unaffected.

### Batching

Events are queued in memory and flushed when the queue reaches `batchSize` (default 20), when `flush()` is called, or when the client is destroyed.

```php
$intempt->flush();                 // Send all queued events now
$intempt->getPendingEvents();      // Inspect the queue (for testing)
```

## Error Handling

- **4xx errors** (except 429): throw `Intempt\IntemptException` immediately
- **429 / 5xx**: retry with exponential backoff up to `maxRetries`
- **Network errors**: retry with exponential backoff
- **Recommendation / optimization failures**: return `['error' => true]` or `null`

```php
use Intempt\IntemptException;

try {
    $intempt->flush();
} catch (IntemptException $e) {
    error_log("Intempt error: {$e->getMessage()} (code: {$e->getCode()})");
}
```

## Requirements

- PHP 8.1+
- Guzzle 7+

## License

MIT
