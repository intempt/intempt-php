# Intempt PHP SDK

PHP client for the Intempt analytics platform.

## Installation

```
composer require intempt/sdk
```

## Usage

```php
use Intempt\Client;

$intempt = new Client([
    'organization' => 'my-org',
    'project' => 'my-app',
    'sourceId' => 'src_123',
    'apiKey' => getenv('INTEMPT_API_KEY'),
]);

// Track events
$intempt->track('Purchase', [
    'userId' => 'user@example.com',
    'data' => ['amount' => 35, 'currency' => 'USD'],
]);

// Identify users
$intempt->identify('user@example.com', [
    'name' => 'Jane Doe',
    'plan' => 'pro',
]);

// Group accounts
$intempt->group('acc_123', ['company_name' => 'Acme Corp']);

// Merge identities
$intempt->alias('user@example.com', 'anonymous-id');

// Flush pending events
$intempt->flush();
```

## Type-Safe Wrappers

Use the Intempt CLI to generate typed wrappers:

```
npx @intempt/cli init       # Set up intempt.yaml
npx @intempt/cli generate   # Generate IntemptTracker.php
```

## Requirements

- PHP 8.1+
- Guzzle 7+
