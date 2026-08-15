<?php

/**
 * The sample app is executed, not just shipped.
 *
 * A sample nobody runs is documentation that rots. This starts the real file
 * under PHP's built-in server, points it at the loopback test server, drives
 * every route, and asserts the events actually arrived.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

final class ExampleAppTest extends TestCase
{
    /** @var resource|null */
    private static $appProcess = null;
    private static int $appPort = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$appProcess === null) {
            self::startApp();
        }
        self::server()->reset();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$appProcess)) {
            proc_terminate(self::$appProcess, SIGTERM);
            proc_close(self::$appProcess);
            self::$appProcess = null;
        }
    }

    private static function startApp(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        self::$appPort = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($socket);

        $app = dirname(__DIR__) . '/examples/basic/app.php';
        $command = sprintf('exec php -S 127.0.0.1:%d %s', self::$appPort, escapeshellarg($app));

        $env = [
            'INTEMPT_ORG' => self::ORG,
            'INTEMPT_PROJECT' => self::PROJECT,
            'INTEMPT_API_KEY' => self::API_KEY,
            'INTEMPT_SOURCE_ID' => self::SOURCE,
            'INTEMPT_FEED_ID' => '5292',
            // The sample defaults to api.intempt.com; these point it at the
            // test server. A sample that cannot be pointed elsewhere cannot be
            // tested by its author or by a customer.
            'INTEMPT_HOST' => self::server()->host(),
            'INTEMPT_SCHEME' => 'http',
        ] + getenv();

        $process = proc_open(
            $command,
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env
        );
        if (!is_resource($process)) {
            self::fail('could not start the sample app');
        }
        self::$appProcess = $process;

        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', self::$appPort, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);

                return;
            }
            usleep(50_000);
        }
        self::fail('sample app never became ready');
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function call(string $method, string $path, string $body = ''): array
    {
        $ch = curl_init(sprintf('http://127.0.0.1:%d%s', self::$appPort, $path));
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, json_decode((string) $raw, true) ?: []];
    }

    /** @return list<string> */
    private static function eventNames(): array
    {
        $names = [];
        foreach (self::server()->requests() as $request) {
            foreach ($request['body']['track'] ?? [] as $event) {
                $names[] = $event['name'];
            }
        }

        return $names;
    }

    public function testSignupSendsIdentifyAndTrack(): void
    {
        [$status, $body] = self::call('POST', '/signup', 'user=ada@example.com&plan=pro');
        self::assertSame(201, $status);
        self::assertSame(['ok' => true], $body);

        $names = self::eventNames();
        self::assertContains('Identify', $names);
        self::assertContains('signed_up', $names);
    }

    public function testPurchaseSendsACommerceEvent(): void
    {
        [$status] = self::call('POST', '/purchase', 'user=ada@example.com&sku=21&qty=2');
        self::assertSame(201, $status);
        self::assertContains('Product ordered', self::eventNames());
    }

    public function testPurchaseWithoutASkuIsRejectedBeforeSending(): void
    {
        [$status, $body] = self::call('POST', '/purchase', 'user=ada@example.com');
        self::assertSame(400, $status);
        self::assertStringContainsString('sku', $body['error']);
        self::assertSame([], self::server()->requests());
    }

    public function testMissingUserIsRejected(): void
    {
        [$status, $body] = self::call('POST', '/signup', 'plan=pro');
        self::assertSame(400, $status);
        self::assertStringContainsString('user', $body['error']);
    }

    public function testForgetRecordsAConsentRevocation(): void
    {
        [$status] = self::call('POST', '/forget', 'user=ada@example.com');
        self::assertSame(202, $status);

        $consent = array_values(array_filter(
            self::server()->requests(),
            static fn (array $r) => str_ends_with($r['path'], '/consents/data')
        ));
        self::assertNotEmpty($consent);
        self::assertSame('reject', $consent[0]['body']['action']);
    }

    public function testRecommendDegradesRatherThanFailingThePage(): void
    {
        [$status, $body] = self::call('GET', '/recommend?user=ada@example.com');
        self::assertSame(200, $status);
        self::assertArrayHasKey('items', $body);
    }

    public function testUnknownRouteIsA404(): void
    {
        [$status] = self::call('POST', '/nope', 'user=x');
        self::assertSame(404, $status);
    }
}
