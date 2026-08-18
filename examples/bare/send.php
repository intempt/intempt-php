<?php

/**
 * The smallest thing that works: one client, one of each call, then close.
 *
 * No web server, no framework, no batching. If you are evaluating the SDK or
 * debugging credentials, start here — every line is a call you would make in
 * real code, and the whole file runs in about a second.
 *
 *     export INTEMPT_ORG=my-org
 *     export INTEMPT_PROJECT=my-project
 *     export INTEMPT_API_KEY='prefix.secret'
 *     export INTEMPT_SOURCE_ID=684508596718616576   # optional
 *     export INTEMPT_FEED_ID=5292                   # optional, enables recommend
 *     php examples/bare/send.php
 *
 * Every call sends one request and returns when the server answers, so the
 * events are in the console by the time this exits. Open Sources -> your source
 * -> Live events and look for the user id printed at the end.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

use Intempt\Intempt;
use Intempt\IntemptApiException;
use Intempt\IntemptConfigException;

// Two ways this file gets run: from within this SDK's own repo (its own
// vendor/ two levels up), or installed as a dependency in a consumer's
// project (that consumer's vendor/ four levels up, past this package's own
// examples/bare/ and vendor/intempt/intempt-php/). Try both rather than
// assuming the repo layout, which fatals under a real `composer require`.
$autoloadCandidates = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../../autoload.php',
];
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        require $candidate;
        break;
    }
}
if (!class_exists(Intempt::class)) {
    fwrite(STDERR, 'could not locate vendor/autoload.php — run "composer install" first' . PHP_EOL);
    exit(2);
}

$missing = [];
foreach (['INTEMPT_ORG', 'INTEMPT_PROJECT', 'INTEMPT_API_KEY'] as $name) {
    if (getenv($name) === false || getenv($name) === '') {
        $missing[] = $name;
    }
}
if ($missing !== []) {
    fwrite(STDERR, 'missing environment: ' . implode(', ', $missing) . PHP_EOL);
    exit(2);
}

$userId = getenv('INTEMPT_USER_ID') ?: 'bare-sample@example.com';

// host and scheme come from the environment so this file can be pointed at a
// local server. A sample nobody can point elsewhere is a sample nobody can test,
// including whoever wrote it.
//
// No `batch` here on purpose: under PHP-FPM a process handles one request and
// dies, so an in-memory buffer has nowhere to live. Turn batching on only in a
// worker or queue consumer.
try {
    $intempt = new Intempt([
        'org' => getenv('INTEMPT_ORG'),
        'project' => getenv('INTEMPT_PROJECT'),
        'apiKey' => getenv('INTEMPT_API_KEY'),
        'sourceId' => getenv('INTEMPT_SOURCE_ID') ?: null,
        'host' => getenv('INTEMPT_HOST') ?: 'api.intempt.com',
        'scheme' => getenv('INTEMPT_SCHEME') ?: 'https',
    ]);
} catch (IntemptConfigException $error) {
    fwrite(STDERR, 'bad arguments: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}

try {
    // Who this is, and what you know about them.
    $intempt->identify(['userId' => $userId, 'traits' => ['plan' => 'pro']]);

    // Something they did. `properties` is yours to shape.
    $intempt->track('purchase', [
        'userId' => $userId,
        'properties' => ['total' => 99.99, 'currency' => 'USD'],
    ]);

    // The company they belong to, if you sell to companies.
    $intempt->group([
        'userId' => $userId,
        'accountId' => 'acme-inc',
        'attributes' => ['tier' => 'enterprise'],
    ]);

    // Commerce events use reserved names the platform reports on, so they
    // cannot be typo'd into a name nothing aggregates.
    $intempt->ecommerce->productViewed(['userId' => $userId, 'productId' => 'sku-1']);
    $intempt->ecommerce->addedToCart([
        'userId' => $userId,
        'productId' => 'sku-1',
        'quantity' => 2,
    ]);
    $intempt->ecommerce->ordered([
        'userId' => $userId,
        'products' => [['productId' => 'sku-1', 'quantity' => 2]],
    ]);

    // A consent record is explicit and separate from optOut(), which only gates
    // this client.
    $intempt->consent->grant(['userId' => $userId, 'category' => 'marketing']);

    $feedId = getenv('INTEMPT_FEED_ID');
    if ($feedId !== false && $feedId !== '') {
        // Treat a recommendation as an enhancement: if it fails, fall back to
        // your own ordering rather than failing the page.
        try {
            $feed = $intempt->recommend([
                'userId' => $userId,
                'feedId' => $feedId,
                'fields' => ['id'],
                'limit' => 3,
            ]);
            echo 'recommend  -> ' . json_encode($feed) . PHP_EOL;
        } catch (IntemptApiException $error) {
            echo 'recommend  -> unavailable (' . $error->getMessage() . '), using the default order' . PHP_EOL;
        }
    }
} catch (IntemptConfigException $error) {
    // Bad arguments. Never retried, because retrying cannot help.
    fwrite(STDERR, 'bad arguments: ' . $error->getMessage() . PHP_EOL);
    $intempt->close();
    exit(2);
} catch (IntemptApiException $error) {
    fwrite(
        STDERR,
        sprintf(
            'API error: status=%s retryable=%s%s',
            $error->status === null ? 'none' : (string) $error->status,
            $error->isRetryable() ? 'true' : 'false',
            PHP_EOL
        )
    );
    fwrite(STDERR, 'body: ' . (string) $error->body . PHP_EOL);
    $intempt->close();
    exit(1);
}

$intempt->close();

printf('sent. look for user id "%s" in Sources -> Live events.%s', $userId, PHP_EOL);
