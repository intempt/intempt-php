<?php

/**
 * Transport internals: retry-after parsing, connection reuse, and error mapping.
 *
 * Infection reported 50 uncovered mutants in Transport.php. The client tests
 * drove it only through happy-path posts, so the timeout branch, the
 * keep-alive drop, the reconnect rule and the header scanner were never
 * executed. These go at the transport directly rather than through the client.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\Config;
use Intempt\IntemptApiException;
use Intempt\Transport;

final class TransportTest extends TestCase
{
    private function config(array $overrides = []): Config
    {
        return Config::resolve($overrides + [
            'org' => self::ORG,
            'project' => self::PROJECT,
            'apiKey' => self::API_KEY,
            'host' => self::server()->host(),
            'scheme' => 'http',
            'logger' => $this->logger,
        ]);
    }

    private function transport(array $overrides = []): Transport
    {
        $config = $this->config($overrides);

        return new Transport($config, $config->credentials);
    }

    // -- parseRetryAfter --------------------------------------------------

    /** @return list<array{0: ?string, 1: ?int}> */
    public static function retryAfterSeconds(): array
    {
        return [
            [null, null],
            ['', null],
            ['   ', null],
            ['0', 0],
            ['1', 1000],
            ['30', 30_000],
            ['  5  ', 5000],
            ['1.5', 1500],
            ['-1', null],
            ['-0.001', null],
            ['not-a-date', null],
            ['NAN', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('retryAfterSeconds')]
    public function testRetryAfterSecondsAreConvertedToMilliseconds(?string $value, ?int $expected): void
    {
        self::assertSame($expected, Transport::parseRetryAfter($value));
    }

    public function testAnInfiniteRetryAfterIsRefused(): void
    {
        self::assertNull(Transport::parseRetryAfter('INF'));
    }

    public function testAnHttpDateInTheFutureBecomesAPositiveWait(): void
    {
        $parsed = Transport::parseRetryAfter(gmdate('D, d M Y H:i:s \G\M\T', time() + 120));

        self::assertNotNull($parsed);
        // Allow a second of clock drift between the two time() reads.
        self::assertGreaterThan(118_000, $parsed);
        self::assertLessThanOrEqual(120_000, $parsed);
    }

    public function testAnHttpDateInThePastClampsToZeroRatherThanGoingNegative(): void
    {
        // A negative wait would mean no wait at all, which is the opposite of
        // what the server asked for.
        self::assertSame(0, Transport::parseRetryAfter(gmdate('D, d M Y H:i:s \G\M\T', time() - 600)));
    }

    // -- retry-after off a real response -----------------------------------

    public function testRetryAfterIsReadOffTheResponseHeader(): void
    {
        self::server()->expect(429, '{}', ['Retry-After' => '7']);
        $transport = $this->transport();

        try {
            $transport->post('/x', ['a' => 1]);
            self::fail('a 429 must throw');
        } catch (IntemptApiException $error) {
            self::assertSame(429, $error->status);
            self::assertSame(7000, $error->retryAfterMs);
            self::assertTrue($error->isRetryable());
        }
    }

    public function testTheHeaderLookupIsCaseInsensitive(): void
    {
        self::server()->expect(429, '{}', ['RETRY-AFTER' => '3']);
        $transport = $this->transport();

        try {
            $transport->post('/x', []);
            self::fail('a 429 must throw');
        } catch (IntemptApiException $error) {
            self::assertSame(3000, $error->retryAfterMs);
        }
    }

    public function testAResponseWithoutRetryAfterLeavesItNull(): void
    {
        self::server()->expect(500, 'boom');
        $transport = $this->transport();

        try {
            $transport->post('/x', []);
            self::fail('a 500 must throw');
        } catch (IntemptApiException $error) {
            self::assertNull($error->retryAfterMs);
            self::assertSame('boom', $error->body);
            self::assertSame(500, $error->status);
        }
    }

    // -- status mapping ----------------------------------------------------

    public function testA204ReturnsNullRatherThanThrowing(): void
    {
        self::server()->expect(204, '');

        self::assertNull($this->transport()->post('/x', []));
    }

    public function testAJsonBodyIsDecoded(): void
    {
        self::server()->expect(200, '{"ok":true,"n":2}');

        self::assertSame(['ok' => true, 'n' => 2], $this->transport()->post('/x', []));
    }

    public function testAnUnparseableSuccessBodyIsReturnedAsText(): void
    {
        // A gateway answering 200 with an HTML error page is a successful
        // exchange carrying a body we cannot parse, not a transport failure.
        self::server()->expect(200, '<html>hi</html>');

        self::assertSame('<html>hi</html>', $this->transport()->post('/x', []));
    }

    /** @return list<array{0: int}> */
    public static function successStatuses(): array
    {
        return [[200], [201], [202], [299]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('successStatuses')]
    public function testEvery2xxIsASuccess(int $status): void
    {
        self::server()->expect($status, '{}');

        self::assertSame([], $this->transport()->post('/x', []));
    }

    /** @return list<array{0: int, 1: bool}> */
    public static function failureStatuses(): array
    {
        return [[300, false], [400, false], [408, true], [429, true], [500, true], [503, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failureStatuses')]
    public function testANon2xxThrowsAndCarriesItsRetryability(int $status, bool $retryable): void
    {
        self::server()->expect($status, 'body-text');

        try {
            $this->transport()->post('/x', []);
            self::fail("status $status must throw");
        } catch (IntemptApiException $error) {
            self::assertSame($status, $error->status);
            self::assertSame('body-text', $error->body);
            self::assertSame($retryable, $error->isRetryable());
            self::assertStringContainsString((string) $status, $error->getMessage());
        }
    }

    // -- timeouts and transport failure ------------------------------------

    public function testATimeoutSaysSoAndCarriesNoStatus(): void
    {
        self::server()->expect(200, '{}', [], delayMs: 900);
        $transport = $this->transport(['timeout' => 0.15]);

        try {
            $transport->post('/x', []);
            self::fail('a slow response must time out');
        } catch (IntemptApiException $error) {
            self::assertStringContainsString('timed out', $error->getMessage());
            self::assertStringContainsString('0.1s', $error->getMessage());
            self::assertNull($error->status, 'a timeout has no status');
            self::assertTrue($error->isRetryable(), 'nothing came back to say it was rejected');
        }
    }

    public function testAnUnreachableHostIsATransportFailure(): void
    {
        // Port 1 on loopback: nothing listens, so the connection is refused.
        $config = Config::resolve([
            'org' => self::ORG,
            'project' => self::PROJECT,
            'apiKey' => self::API_KEY,
            'host' => '127.0.0.1:1',
            'scheme' => 'http',
            'timeout' => 2.0,
        ]);
        $transport = new Transport($config, $config->credentials);

        try {
            $transport->post('/x', []);
            self::fail('an unreachable host must throw');
        } catch (IntemptApiException $error) {
            self::assertNull($error->status);
            self::assertTrue($error->isRetryable());
            self::assertNotSame('', $error->getMessage());
        }
    }

    // -- close -------------------------------------------------------------

    public function testPostingAfterCloseIsRefused(): void
    {
        $transport = $this->transport();
        $transport->close();

        $this->expectException(IntemptApiException::class);
        $this->expectExceptionMessage('client is closed');
        $transport->post('/x', []);
    }

    public function testCloseIsIdempotent(): void
    {
        $transport = $this->transport();
        $transport->close();
        $transport->close();

        $this->expectException(IntemptApiException::class);
        $transport->post('/x', []);
    }

    public function testCloseBeforeAnyRequestIsSafe(): void
    {
        $this->expectNotToPerformAssertions();
        $this->transport()->close();
    }

    // -- connection reuse --------------------------------------------------
    //
    // Asserted against the transport's own curl handle, not the socket the
    // server sees. PHP's built-in server answers every request with
    // `Connection: close`, so the remote port changes between requests whatever
    // the client asked for; an assertion on ports measures the test double
    // rather than the SDK.

    private function handleOf(Transport $transport): ?\CurlHandle
    {
        return (new \ReflectionProperty(Transport::class, 'handle'))->getValue($transport);
    }

    public function testKeepAliveHoldsOneHandleAcrossRequests(): void
    {
        self::server()->expectMany(3, 200, '{}');
        $transport = $this->transport(['keepAlive' => true]);

        $transport->post('/x', []);
        $first = $this->handleOf($transport);
        self::assertNotNull($first, 'keep-alive must retain the handle');

        $transport->post('/x', []);
        $transport->post('/x', []);

        self::assertSame($first, $this->handleOf($transport), 'the handle must be reused');
        self::assertCount(3, self::server()->requests());
    }

    public function testDisablingKeepAliveRetainsNoHandle(): void
    {
        self::server()->expectMany(3, 200, '{}');
        $transport = $this->transport(['keepAlive' => false]);

        for ($i = 0; $i < 3; ++$i) {
            $transport->post('/x', ['i' => $i]);
            self::assertNull($this->handleOf($transport), 'nothing may be held between requests');
        }

        self::assertCount(3, self::server()->requests());
    }

    public function testCloseReleasesTheHandle(): void
    {
        self::server()->expect(200, '{}');
        $transport = $this->transport(['keepAlive' => true]);
        $transport->post('/x', []);
        self::assertNotNull($this->handleOf($transport));

        $transport->close();

        self::assertNull($this->handleOf($transport));
    }

    // -- setConfig ---------------------------------------------------------

    /** @return list<array{0: string}> */
    public static function reconnectTriggers(): array
    {
        return [['host'], ['port'], ['scheme']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reconnectTriggers')]
    public function testChangingTheEndpointDropsTheHandle(string $field): void
    {
        self::server()->expect(200, '{}');
        $transport = $this->transport(['keepAlive' => true]);
        $transport->post('/x', []);
        self::assertNotNull($this->handleOf($transport));

        [$host, $rawPort] = explode(':', self::server()->host(), 2);
        $port = (int) $rawPort;
        $patch = match ($field) {
            'host' => ['host' => 'localhost:' . $port],
            'port' => ['host' => $host . ':' . ($port + 1)],
            'scheme' => ['scheme' => 'https'],
        };

        $transport->setConfig($this->config()->merge($patch));

        self::assertNull($this->handleOf($transport), "changing $field must force a reconnect");
    }

    public function testAConfigChangeThatDoesNotMoveTheEndpointKeepsTheHandle(): void
    {
        self::server()->expect(200, '{}');
        $transport = $this->transport(['keepAlive' => true]);
        $transport->post('/x', []);
        $before = $this->handleOf($transport);
        self::assertNotNull($before);

        $transport->setConfig($this->config()->merge(['debug' => true, 'timeout' => 7.0]));

        self::assertSame($before, $this->handleOf($transport), 'only host, port or scheme may reconnect');
    }

    // -- debug logging -----------------------------------------------------

    public function testDebugLogsThePathAndNeverTheBody(): void
    {
        self::server()->expect(200, '{}');
        $transport = $this->transport(['debug' => true]);
        $transport->post('/some/path', ['secret' => 'do-not-log-me']);

        $debug = implode("\n", $this->logger->calls['debug']);
        self::assertStringContainsString('/some/path', $debug);
        self::assertStringNotContainsString('do-not-log-me', $debug);
    }

    public function testDebugIsSilentWhenOff(): void
    {
        self::server()->expect(200, '{}');
        $transport = $this->transport(['debug' => false]);
        $transport->post('/some/path', []);

        self::assertSame([], $this->logger->calls['debug']);
    }

    // -- request shape -----------------------------------------------------

    public function testTheRequestCarriesTheAuthAndVersionHeaders(): void
    {
        self::server()->expect(200, '{}');
        $this->transport()->post('/x', ['a' => 1]);

        $request = self::server()->requests()[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('/x', $request['path']);
        self::assertSame('application/json', $request['headers']['content-type']);
        self::assertStringStartsWith('intempt-php/', $request['headers']['x-intempt-lib']);
        self::assertSame(
            'Basic ' . base64_encode('pfx0123456789abcdef:sec0123456789abcdef'),
            $request['headers']['authorization']
        );
        self::assertSame(['a' => 1], $request['body']);
    }

    public function testSlashesInTheBodyAreNotEscaped(): void
    {
        self::server()->expect(200, '{}');
        $this->transport()->post('/x', ['path' => 'a/b']);

        self::assertSame(['path' => 'a/b'], self::server()->requests()[0]['body']);
    }

    public function testAnUnencodableBodyFailsBeforeAnythingIsSent(): void
    {
        $transport = $this->transport();

        $this->expectException(\JsonException::class);
        $transport->post('/x', ['bad' => NAN]);
        self::assertSame([], self::server()->requests());
    }
}
