<?php

/**
 * Contract test against a real Intempt environment.
 *
 * The unit suite proves the SDK sends what it intends to. This proves the
 * platform accepts it — a different question, and the one that bites. A 401 here
 * means the Basic auth header is rejected; a 400 means a payload shape the
 * loopback tests were happy with is not what ingestion wants.
 *
 * Mirrors the Node SDK's `scripts/e2e.mjs` and the Python SDK's `scripts/e2e.py`
 * step for step, so a divergence between the three shows up as a different
 * PASS/FAIL table rather than as a support ticket.
 *
 * Reads credentials from the environment, or from a gitignored `.env.local` at
 * the repository root:
 *
 *     INTEMPT_E2E_API_KEY     a PUBLIC key for a throwaway staging project
 *     INTEMPT_E2E_ORG
 *     INTEMPT_E2E_PROJECT
 *     INTEMPT_E2E_SOURCE_ID
 *     INTEMPT_E2E_USER_ID     a stable pre-existing test profile
 *     INTEMPT_E2E_ACCOUNT_ID  a stable test account            (optional)
 *     INTEMPT_E2E_FEED_ID     a real feed id                   (optional)
 *     INTEMPT_E2E_FEED_FIELDS comma-separated                  (optional)
 *     INTEMPT_E2E_PRODUCT_ID  an id that exists in the catalog (optional)
 *     INTEMPT_E2E_HOST        defaults to api.staging.intempt.com
 *     INTEMPT_E2E_SCHEME      defaults to https
 *
 * Exit codes: 0 every step passed or skipped, 1 at least one failed, 2 no
 * credential so nothing ran.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

use Intempt\BatchOptions;
use Intempt\Intempt;
use Intempt\IntemptApiException;

require __DIR__ . '/../vendor/autoload.php';

// --- .env.local -------------------------------------------------------------

/** Fill the environment from a KEY=value file. Existing values win. */
function loadEnvFile(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }
    $loaded = 0;
    foreach (explode("\n", (string) file_get_contents($path)) as $raw) {
        $line = trim($raw);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "'\"");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            ++$loaded;
        }
    }

    return $loaded;
}

$loadedCount = loadEnvFile(__DIR__ . '/../.env.local');

// --- inputs -----------------------------------------------------------------

$env = static fn (string $name): ?string => (getenv($name) ?: null);

$apiKey = $env('INTEMPT_E2E_API_KEY');
$org = $env('INTEMPT_E2E_ORG');
$project = $env('INTEMPT_E2E_PROJECT');
$sourceId = $env('INTEMPT_E2E_SOURCE_ID');
$stableUserId = $env('INTEMPT_E2E_USER_ID');
$accountId = $env('INTEMPT_E2E_ACCOUNT_ID');
$feedId = $env('INTEMPT_E2E_FEED_ID');
$productId = $env('INTEMPT_E2E_PRODUCT_ID');
$host = $env('INTEMPT_E2E_HOST') ?? 'api.staging.intempt.com';
$scheme = $env('INTEMPT_E2E_SCHEME') ?? 'https';
$feedFields = array_values(array_filter(array_map(
    'trim',
    explode(',', $env('INTEMPT_E2E_FEED_FIELDS') ?? 'id')
), static fn (string $f): bool => $f !== ''));

if ($apiKey === null || $org === null || $project === null) {
    fwrite(STDERR, "INTEMPT_E2E_API_KEY, INTEMPT_E2E_ORG and INTEMPT_E2E_PROJECT are required.\n"
        . "Set them in the environment or in a gitignored .env.local at the repo root.\n");
    exit(2);
}

// An ephemeral id still exercises every write path; a stable one keeps the test
// project from filling up with single-event profiles.
$userId = $stableUserId ?? ('sdk-e2e-' . bin2hex(random_bytes(6)) . '@example.com');

// --- readiness --------------------------------------------------------------

printf("\nIntempt PHP SDK contract test — %s\n", $host);
if ($loadedCount > 0) {
    printf("  loaded %d value(s) from .env.local\n", $loadedCount);
}
printf("  profile: %s%s\n\n", $userId, $stableUserId !== null ? ' (stable)' : ' (ephemeral)');
echo "  project inputs\n  " . str_repeat('-', 76) . "\n";
foreach ([
    ['stable userId', $stableUserId, 'identify, track, group, alias, consent'],
    ['accountId (optional)', $accountId, 'group — created automatically if absent'],
    ['feed id', $feedId, 'recommend'],
    ['productId', $productId, 'ecommerce.*'],
] as [$name, $value, $usedBy]) {
    printf("  %s  %-22s %s\n", $value !== null ? 'have' : 'MISS', $name, $usedBy);
}
echo '  ' . str_repeat('-', 76) . "\n\n";

$intempt = new Intempt([
    'org' => $org,
    'project' => $project,
    'apiKey' => $apiKey,
    'sourceId' => $sourceId,
    'host' => $host,
    'scheme' => $scheme,
]);

// --- harness ----------------------------------------------------------------

/** @var list<array{name:string,state:string,ms:int,note:string}> $results */
$results = [];

$step = static function (string $name, callable $fn) use (&$results): void {
    $started = microtime(true);
    try {
        $value = $fn();
        $ms = (int) ((microtime(true) - $started) * 1000);
        $note = $value === null ? '2xx' : substr((string) $value, 0, 60);
        $results[] = ['name' => $name, 'state' => 'PASS', 'ms' => $ms, 'note' => $note];
        printf("  PASS  %-46s %5dms  %s\n", $name, $ms, $note);
    } catch (IntemptApiException $error) {
        $ms = (int) ((microtime(true) - $started) * 1000);
        $status = $error->status === null ? 'transport' : (string) $error->status;
        $body = substr((string) $error->body, 0, 160);
        $results[] = ['name' => $name, 'state' => 'FAIL', 'ms' => $ms, 'note' => "$status: $body"];
        printf("  FAIL  %-46s %5dms  %s %s\n", $name, $ms, $status, $body);
    } catch (\Throwable $error) {
        $ms = (int) ((microtime(true) - $started) * 1000);
        $note = substr($error->getMessage(), 0, 160);
        $results[] = ['name' => $name, 'state' => 'FAIL', 'ms' => $ms, 'note' => $note];
        printf("  FAIL  %-46s %5dms  %s\n", $name, $ms, $note);
    }
};

$skip = static function (string $name, string $why) use (&$results): void {
    $results[] = ['name' => $name, 'state' => 'SKIP', 'ms' => 0, 'note' => $why];
    printf("  SKIP  %-46s         %s\n", $name, $why);
};

// --- writes -----------------------------------------------------------------

// A 401 here means the Basic auth header is rejected, which is the single most
// important thing this test exists to prove.
$step('identify (proves Basic auth is accepted)', static function () use ($intempt, $userId) {
    $intempt->identify(['userId' => $userId, 'traits' => ['source' => 'sdk-e2e']]);
});

$step('track', static function () use ($intempt, $userId) {
    $intempt->track('sdk_e2e_event', ['userId' => $userId, 'properties' => ['runner' => 'php']]);
});

// Inside the 2010..2040 window ingestion accepts. Outside it the low end is
// rejected and the high end is silently replaced with the server clock — so a
// pass here is also evidence the SDK is sending milliseconds, not seconds.
$step('track with an explicit timestamp', static function () use ($intempt, $userId) {
    $intempt->track('sdk_e2e_backfill', [
        'userId' => $userId,
        'timestamp' => new \DateTimeImmutable('-1 day'),
    ]);
});

$step('trackBatch (2 events, 1 request)', static function () use ($intempt, $userId) {
    $intempt->trackBatch([
        ['event' => 'sdk_e2e_batch_a', 'userId' => $userId],
        ['event' => 'sdk_e2e_batch_b', 'userId' => $userId],
    ]);
});

$step('group (creates the account if absent)', static function () use ($intempt, $userId, $accountId) {
    $intempt->group([
        'userId' => $userId,
        'accountId' => $accountId ?? 'sdk-e2e-account',
        'attributes' => ['tier' => 'e2e'],
    ]);
});

$step('alias', static function () use ($intempt, $userId) {
    $intempt->alias([
        'userId' => $userId,
        'previousUserId' => 'sdk-e2e-prev-' . bin2hex(random_bytes(4)),
    ]);
});

// --- commerce ---------------------------------------------------------------

// Ingestion answers 201 for a product id that does not exist, so a pass with a
// made-up id proves only that the request was well formed. With a real catalog
// id it proves the line actually resolves.
$commerceProduct = $productId ?? 'sdk-e2e-product';
$suffix = $productId !== null ? ' (catalog product)' : '';

$step("ecommerce.productViewed$suffix", static function () use ($intempt, $userId, $commerceProduct) {
    $intempt->ecommerce->productViewed(['userId' => $userId, 'productId' => $commerceProduct]);
});
$step("ecommerce.addedToCart$suffix", static function () use ($intempt, $userId, $commerceProduct) {
    $intempt->ecommerce->addedToCart([
        'userId' => $userId,
        'productId' => $commerceProduct,
        'quantity' => 2,
    ]);
});
$step("ecommerce.ordered$suffix (1 line)", static function () use ($intempt, $userId, $commerceProduct) {
    $intempt->ecommerce->ordered([
        'userId' => $userId,
        'products' => [['productId' => $commerceProduct, 'quantity' => 1]],
    ]);
});

// --- consent ----------------------------------------------------------------

// /consents/data takes epoch SECONDS while /track takes milliseconds. Sending
// milliseconds here lands past 2040 and is silently rewritten, so this step is
// the only proof the SDK gets the unit right.
$step('consent.grant (proves epoch-seconds timestamps)', static function () use ($intempt, $userId) {
    $intempt->consent->grant(['userId' => $userId, 'category' => 'marketing']);
});
$step('consent.revoke', static function () use ($intempt, $userId) {
    $intempt->consent->revoke(['userId' => $userId, 'reason' => 'sdk-e2e teardown']);
});

// --- reads ------------------------------------------------------------------

if ($feedId !== null) {
    $step('recommend (real feed resolves)', static function () use ($intempt, $userId, $feedId, $feedFields) {
        $feed = $intempt->recommend([
            'userId' => $userId,
            'feedId' => $feedId,
            'fields' => $feedFields,
            'limit' => 3,
        ]);

        return sprintf('%d item(s)', is_countable($feed) ? count($feed) : 0);
    });

    // The negative case matters as much: if an unknown feed also returns 200,
    // a typo'd feed id degrades silently to an empty page forever.
    $step('recommend (unknown feed is rejected)', static function () use ($intempt, $userId, $feedFields) {
        try {
            $intempt->recommend([
                'userId' => $userId,
                'feedId' => '000000000',
                'fields' => $feedFields,
                'limit' => 1,
            ]);
        } catch (IntemptApiException $error) {
            return sprintf('rejected with %s, as it should be', (string) $error->status);
        }

        throw new \RuntimeException('an unknown feed id returned success');
    });
} else {
    $skip('recommend', 'no INTEMPT_E2E_FEED_ID');
}

// --- buffered mode ----------------------------------------------------------

$step('flush (5 events buffered, 1 request)', static function () use ($org, $project, $apiKey, $sourceId, $host, $scheme, $userId) {
    $buffered = new Intempt([
        'org' => $org,
        'project' => $project,
        'apiKey' => $apiKey,
        'sourceId' => $sourceId,
        'host' => $host,
        'scheme' => $scheme,
        'batch' => new BatchOptions(size: 50, flushMs: 60_000, maxQueue: 1_000),
    ]);
    try {
        for ($i = 0; $i < 5; ++$i) {
            $buffered->track('sdk_e2e_buffered', ['userId' => $userId, 'properties' => ['i' => $i]]);
        }
        $buffered->flush();

        return '5 events, 1 request';
    } finally {
        $buffered->close();
    }
});

$intempt->close();

// --- report -----------------------------------------------------------------

$count = static fn (string $state): int => count(array_filter(
    $results,
    static fn (array $r): bool => $r['state'] === $state
));

$failed = $count('FAIL');

echo "\n  " . str_repeat('-', 76) . "\n";
printf("  %d passed, %d failed, %d skipped\n", $count('PASS'), $failed, $count('SKIP'));
echo '  ' . str_repeat('-', 76) . "\n\n";

if ($failed > 0) {
    echo "  failures:\n";
    foreach ($results as $result) {
        if ($result['state'] === 'FAIL') {
            printf("    %s: %s\n", $result['name'], $result['note']);
        }
    }
    echo "\n";
}

exit($failed > 0 ? 1 : 0);
