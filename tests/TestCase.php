<?php

/**
 * Test harness: a real HTTP server on loopback.
 *
 * Deliberately not a mocking library. Mocking curl would prove what the SDK
 * *intends* to send; a real socket proves what actually goes over the wire —
 * header framing, connection reuse, timeouts and JSON round-tripping.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\BatchOptions;
use Intempt\Intempt;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public const ORG = 'acme';
    public const PROJECT = 'web';
    /** A real 19-digit snowflake, past 2**53. Proves no numeric coercion. */
    public const SOURCE = '1841503112918048768';
    public const API_KEY = 'pfx0123456789abcdef.sec0123456789abcdef';

    protected static ?FakeServer $server = null;
    protected RecordingLogger $logger;
    /** @var list<Intempt> */
    private array $clients = [];

    public static function setUpBeforeClass(): void
    {
        if (self::$server === null) {
            self::$server = FakeServer::start();
            // One server for the whole run, torn down at exit. Without this the
            // php -S process outlives the suite and holds its port.
            register_shutdown_function(static function (): void {
                self::$server?->stop();
                self::$server = null;
            });
        }
    }

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        self::server()->reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            // Best effort: a test that already closed the client must not fail
            // here instead of where it actually asserted.
            try {
                $client->close();
            } catch (\Throwable) {
            }
        }
        $this->clients = [];
    }

    protected static function server(): FakeServer
    {
        return self::$server ?? throw new \RuntimeException('server not started');
    }

    /** @param array<string, mixed> $overrides */
    protected function client(array $overrides = []): Intempt
    {
        $client = new Intempt(array_merge([
            'org' => self::ORG,
            'project' => self::PROJECT,
            'apiKey' => self::API_KEY,
            'sourceId' => self::SOURCE,
            'host' => self::server()->host(),
            'scheme' => 'http',
            'logger' => $this->logger,
        ], $overrides));
        $this->clients[] = $client;

        return $client;
    }

    /** @param array<string, mixed> $overrides */
    protected function batchedClient(array $overrides = []): Intempt
    {
        return $this->client(array_merge([
            'batch' => new BatchOptions(
                size: 10,
                flushMs: 60_000,
                maxQueue: 1_000,
                flushOnExit: false
            ),
        ], $overrides));
    }

    protected function trackPath(): string
    {
        return sprintf('/v1/%s/projects/%s/sources/%s/track', self::ORG, self::PROJECT, self::SOURCE);
    }
}
