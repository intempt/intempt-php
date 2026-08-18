<?php

/**
 * A small dependency-free sample: one HTTP endpoint that instruments itself.
 *
 * Run it against a real project:
 *
 *     export INTEMPT_ORG=my-org
 *     export INTEMPT_PROJECT=my-project
 *     export INTEMPT_API_KEY='prefix.secret'
 *     export INTEMPT_SOURCE_ID=684508596718616576
 *     export INTEMPT_FEED_ID=5292          # optional, enables /recommend
 *     php -S 127.0.0.1:8080 examples/basic/app.php
 *
 * Then, in another shell:
 *
 *     curl -X POST localhost:8080/signup   -d 'user=ada@example.com'
 *     curl -X POST localhost:8080/purchase -d 'user=ada@example.com&sku=21&qty=2'
 *     curl        'localhost:8080/recommend?user=ada@example.com'
 *     curl -X POST localhost:8080/forget   -d 'user=ada@example.com'
 *
 * The point of the sample is the shape, not the routes: one client per request
 * lifecycle, an identifier on every call, and an explicit flush before the
 * process ends.
 *
 * PHP note that matters more here than in other languages: a typical
 * PHP-FPM process handles one request and dies, so a long-lived in-memory
 * buffer has nowhere to live. Batching is therefore OFF in this sample. Turn it
 * on only in a worker or a long-running process (Swoole, RoadRunner, a queue
 * consumer), where flush() and close() have something to drain.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

// Capture anything printed before the response is composed.
//
// Any output at all before the first header() call makes http_response_code() a
// no-op, so a single notice from any dependency silently turns a 201 into a 200
// and puts HTML in a JSON body. That is not hypothetical: a dev-only transitive
// dependency emitting deprecations during autoload is exactly how this was
// found, and `display_errors = stderr` does not help because the cli-server SAPI
// does not honour it.
//
// Buffering rather than silencing: the notices still reach the error log, they
// just stop corrupting the response.
ob_start();

// Two ways this file gets run: from within this SDK's own repo (its own
// vendor/ two levels up), or installed as a dependency in a consumer's
// project (that consumer's vendor/ four levels up, past this package's own
// examples/basic/ and vendor/intempt/intempt-php/). Try both rather than
// assuming the repo layout, which fatals under a real `composer require`.
$autoloadCandidates = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../../autoload.php',
];
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        break;
    }
}

use Intempt\ErrorLogLogger;
use Intempt\Intempt;
use Intempt\IntemptApiException;
use Intempt\IntemptConfigException;

function build_client(): Intempt
{
    $missing = [];
    foreach (['INTEMPT_ORG', 'INTEMPT_PROJECT', 'INTEMPT_API_KEY'] as $name) {
        if ((getenv($name) ?: '') === '') {
            $missing[] = $name;
        }
    }
    if ($missing !== []) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'missing environment: ' . implode(', ', $missing)]);
        exit;
    }

    return new Intempt([
        'org' => getenv('INTEMPT_ORG'),
        'project' => getenv('INTEMPT_PROJECT'),
        'apiKey' => getenv('INTEMPT_API_KEY'),
        'sourceId' => getenv('INTEMPT_SOURCE_ID') ?: null,
        // host and scheme come from the environment so this sample can be
        // pointed at a local server. A sample you cannot point elsewhere is a
        // sample nobody can test, including its author.
        'host' => getenv('INTEMPT_HOST') ?: 'api.intempt.com',
        'scheme' => getenv('INTEMPT_SCHEME') ?: 'https',
        'logger' => new ErrorLogLogger(),
    ]);
}

/** @param array<string, mixed> $payload */
function reply(int $status, array $payload): void
{
    // Drop whatever leaked into the buffer so the status line and the body are
    // ours alone.
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
}

$intempt = build_client();
$feedId = getenv('INTEMPT_FEED_ID') ?: null;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'POST') {
        parse_str(file_get_contents('php://input') ?: '', $form);
        $user = $form['user'] ?? null;
        if (!is_string($user) || $user === '') {
            reply(400, ['error' => 'user is required']);
            goto finish;
        }

        switch ($path) {
            case '/signup':
                // identify() writes traits. The platform resolves identity from
                // userId itself, so there is no id to mint here.
                $intempt->identify([
                    'userId' => $user,
                    'traits' => ['plan' => $form['plan'] ?? 'free'],
                ]);
                $intempt->track('signed_up', ['userId' => $user]);
                reply(201, ['ok' => true]);
                goto finish;

            case '/purchase':
                $sku = $form['sku'] ?? null;
                if (!is_string($sku) || $sku === '') {
                    reply(400, ['error' => 'sku is required']);
                    goto finish;
                }
                $intempt->ecommerce->ordered([
                    'userId' => $user,
                    'products' => [[
                        'productId' => $sku,
                        'quantity' => (int) ($form['qty'] ?? 1),
                    ]],
                ]);
                reply(201, ['ok' => true]);
                goto finish;

            case '/forget':
                // Revoking consent is a write like any other, gated by opt-out
                // the same way.
                $intempt->consent->revoke([
                    'userId' => $user,
                    'reason' => 'user requested deletion',
                ]);
                reply(202, ['ok' => true]);
                goto finish;
        }

        reply(404, ['error' => 'no such route']);
        goto finish;
    }

    if ($path === '/recommend') {
        if ($feedId === null) {
            reply(503, ['error' => 'set INTEMPT_FEED_ID to enable this route']);
            goto finish;
        }
        parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?: '', $query);
        $user = $query['user'] ?? null;
        if (!is_string($user) || $user === '') {
            reply(400, ['error' => 'user is required']);
            goto finish;
        }

        try {
            $feed = $intempt->recommend([
                'userId' => $user,
                'feedId' => $feedId,
                'fields' => ['id', 'title'],
                'limit' => 5,
            ]);
        } catch (IntemptApiException $exception) {
            error_log('feed lookup failed: ' . $exception->getMessage());
            // A recommendation is an enhancement. Degrade rather than fail the
            // page: an empty list is a worse experience, an error is a broken one.
            reply(200, ['items' => []]);
            goto finish;
        }

        // Always the same shape, whatever the feed returns. A caller should not
        // have to branch on whether `items` exists: an empty feed and a feed
        // that resolved to nothing are the same thing to the page rendering it.
        reply(200, ['items' => (is_array($feed) ? ($feed['items'] ?? []) : [])]);
        goto finish;
    }

    reply(404, ['error' => 'no such route']);
} catch (IntemptApiException $exception) {
    // Every method throws. Nothing is swallowed, so a real app decides here
    // whether the failure should reach the customer.
    error_log('intempt rejected the write: ' . $exception->getMessage());
    reply(502, ['error' => 'analytics write failed']);
} catch (IntemptConfigException $exception) {
    reply(400, ['error' => $exception->getMessage()]);
}

finish:
if (ob_get_level() > 0) {
    ob_end_flush();
}
// Explicit, and on every path. With batching off this is a no-op, but leaving it
// out is how a later switch to a worker process silently starts losing events.
$intempt->close();
