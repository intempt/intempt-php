<?php

/**
 * The failure path with no logger configured.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\FlagContext;
use Intempt\Intempt;

final class FlagLoggerTest extends TestCase
{
    public function testAFailedEvaluationWithNoLoggerStillReturnsTheDefault(): void
    {
        // The logger is optional. Reaching for the nullable property rather than the accessor made
        // the error handler itself throw, turning a recoverable 503 into a fatal - the opposite of
        // what the catch exists to do. Every other test in this suite passes a logger, so none of
        // them could see it; the sample gate did.
        self::server()->expect(503, '{}');

        $client = new Intempt([
            'org' => self::ORG,
            'project' => self::PROJECT,
            'apiKey' => self::API_KEY,
            'sourceId' => self::SOURCE,
            'host' => self::server()->host(),
            'scheme' => 'http',
            // No 'logger' key, deliberately.
        ]);

        $value = $client->stringVariation('k', new FlagContext(userId: 'u-1'), 'safe');

        self::assertSame('safe', $value);
    }
}
