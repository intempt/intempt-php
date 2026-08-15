<?php

/**
 * Runs `examples/bare/send.php` as a real process against the loopback server.
 *
 * The sample is documentation that executes, so it is tested like code. A sample
 * that has drifted from the SDK teaches the wrong thing to whoever copies it,
 * and the only way to know it still works is to run it.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

final class BareSampleTest extends TestCase
{
    private const USER = 'bare-test@example.com';

    /** @param array<string, string> $overrides */
    private function runSample(array $overrides = []): array
    {
        $env = $overrides + [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'INTEMPT_ORG' => self::ORG,
            'INTEMPT_PROJECT' => self::PROJECT,
            'INTEMPT_API_KEY' => self::API_KEY,
            'INTEMPT_SOURCE_ID' => self::SOURCE,
            'INTEMPT_USER_ID' => self::USER,
            'INTEMPT_HOST' => self::server()->host(),
            'INTEMPT_SCHEME' => 'http',
        ];
        $env = array_filter($env, static fn ($v): bool => $v !== '');

        $sample = dirname(__DIR__) . '/examples/bare/send.php';

        // Files rather than pipes, deliberately. Reading one pipe to EOF while
        // the child writes to the other deadlocks as soon as the second pipe's
        // 64KB buffer fills, and on PHP 8.5 it does: composer's `files`
        // autoload pulls in a dev dependency that emits a deprecation notice per
        // function to stderr, so every run of this sample produces enough output
        // to hang a naive reader.
        $out = (string) tempnam(sys_get_temp_dir(), 'intempt-out');
        $err = (string) tempnam(sys_get_temp_dir(), 'intempt-err');
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $out, 'w'],
            2 => ['file', $err, 'w'],
        ];

        $process = proc_open(
            sprintf('exec php %s', escapeshellarg($sample)),
            $descriptors,
            $pipes,
            null,
            $env
        );
        self::assertIsResource($process, 'could not start the sample');
        $status = proc_close($process);

        $stdout = (string) file_get_contents($out);
        $stderr = (string) file_get_contents($err);
        @unlink($out);
        @unlink($err);

        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function testTheFileTheReadmeNamesExists(): void
    {
        self::assertFileExists(dirname(__DIR__) . '/examples/bare/send.php');
    }

    public function testItExitsZeroAndSendsEveryCall(): void
    {
        self::server()->expectMany(20, 200, '{}');

        $result = $this->runSample();

        self::assertSame(0, $result['status'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString(self::USER, $result['stdout']);
        self::assertNotEmpty(self::server()->requests(), 'the sample sent nothing');
    }

    public function testEveryRequestCarriesTheCredentialAndTheVersion(): void
    {
        self::server()->expectMany(20, 200, '{}');

        $this->runSample();

        $requests = self::server()->requests();
        self::assertNotEmpty($requests);
        foreach ($requests as $request) {
            self::assertStringStartsWith('Basic ', $request['headers']['authorization']);
            self::assertStringStartsWith('intempt-php/', $request['headers']['x-intempt-lib']);
        }
    }

    public function testItSendsTheIdentifyTrackGroupAndCommerceCalls(): void
    {
        self::server()->expectMany(20, 200, '{}');

        $this->runSample();

        $blob = '';
        foreach (self::server()->requests() as $request) {
            $blob .= json_encode($request['body']) . $request['path'];
        }
        foreach (['purchase', 'acme-inc', 'sku-1', 'marketing'] as $expected) {
            self::assertStringContainsString($expected, $blob, "$expected never reached the wire");
        }
    }

    public function testTheSourceIdKeepsAllNineteenDigits(): void
    {
        self::server()->expectMany(20, 200, '{}');

        $this->runSample();

        $blob = '';
        foreach (self::server()->requests() as $request) {
            $blob .= $request['path'];
        }
        self::assertStringContainsString(
            self::SOURCE,
            $blob,
            'a numeric round trip would have dropped the last digits'
        );
    }

    public function testMissingEnvironmentExitsTwoAndSaysWhatIsMissing(): void
    {
        $result = $this->runSample(['INTEMPT_API_KEY' => '']);

        self::assertSame(2, $result['status']);
        self::assertStringContainsString('INTEMPT_API_KEY', $result['stderr']);
        self::assertSame([], self::server()->requests(), 'nothing may be sent without a credential');
    }

    public function testABadArgumentExitsTwoRatherThanOne(): void
    {
        $result = $this->runSample(['INTEMPT_ORG' => '   ']);

        self::assertSame(2, $result['status']);
        self::assertStringContainsString('bad arguments', $result['stderr']);
        self::assertSame([], self::server()->requests());
    }

    public function testAnApiFailureExitsOneAndReportsTheStatus(): void
    {
        self::server()->expectMany(20, 500, '{"error":"nope"}');

        $result = $this->runSample();

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('status=500', $result['stderr']);
        self::assertStringContainsString('retryable=true', $result['stderr']);
    }

    public function testA401IsReportedAsNotRetryable(): void
    {
        self::server()->expectMany(20, 401, '{}');

        $result = $this->runSample();

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('status=401', $result['stderr']);
        self::assertStringContainsString('retryable=false', $result['stderr']);
    }

    public function testRecommendIsSkippedWithoutAFeedId(): void
    {
        self::server()->expectMany(20, 200, '{}');

        $result = $this->runSample();

        self::assertStringNotContainsString('recommend', $result['stdout']);
        foreach (self::server()->requests() as $request) {
            self::assertStringNotContainsString('/feeds', $request['path']);
        }
    }

    public function testAFeedIdTurnsRecommendOn(): void
    {
        self::server()->expectMany(20, 200, '{"items":[]}');

        $result = $this->runSample(['INTEMPT_FEED_ID' => '5292']);

        self::assertSame(0, $result['status'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('recommend', $result['stdout']);

        $paths = array_column(self::server()->requests(), 'path');
        self::assertNotEmpty(
            array_filter($paths, static fn (string $p): bool => str_contains($p, '/feeds/5292/')),
            'the feed was never read'
        );
    }

    public function testAFailingRecommendDegradesInsteadOfFailingTheRun(): void
    {
        // Every other call succeeds; only the feed read fails. The sample must
        // still exit 0, because a recommendation is an enhancement.
        //
        // The sample makes exactly 8 requests: six track calls, one consent
        // record, then the feed. Counted from the server rather than assumed.
        self::server()->expectMany(7, 200, '{}');
        self::server()->expectMany(4, 503, '{}');

        $result = $this->runSample(['INTEMPT_FEED_ID' => '5292']);

        self::assertSame(0, $result['status'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('default order', $result['stdout']);
    }

    public function testTheSampleMakesExactlyEightRequestsWithAFeed(): void
    {
        // Pins the count the two tests above depend on, so a new call in the
        // sample fails here rather than silently shifting which reply the feed
        // read receives.
        self::server()->expectMany(20, 200, '{}');

        $this->runSample(['INTEMPT_FEED_ID' => '5292']);

        self::assertCount(8, self::server()->requests());
    }
}
