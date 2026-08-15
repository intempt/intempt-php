<?php

/**
 * Config resolution and merge, branch by branch.
 *
 * Infection reported 59 uncovered mutants in Config.php, more than any other
 * file: every validation branch here was reachable only through
 * `new Intempt([...])`, and the client tests only ever passed valid options. The
 * rejections were written and never executed.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\BatchOptions;
use Intempt\Config;
use Intempt\IntemptConfigException;
use Intempt\NullLogger;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class ConfigTest extends BaseTestCase
{
    /** @param array<string, mixed> $overrides */
    private static function options(array $overrides = []): array
    {
        return $overrides + [
            'org' => 'my-org',
            'project' => 'my-project',
            'apiKey' => 'prefix.secret',
        ];
    }

    // ---- required options ----

    /** @return list<array{0: string, 1: mixed}> */
    public static function missingRequired(): array
    {
        $cases = [];
        foreach (['org', 'project', 'apiKey'] as $name) {
            $cases[] = [$name, null];
            $cases[] = [$name, ''];
            $cases[] = [$name, '   '];
            $cases[] = [$name, 123];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingRequired')]
    public function testEachRequiredOptionIsRejected(string $name, mixed $value): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage(sprintf('"%s" is required', $name));
        Config::resolve(self::options([$name => $value]));
    }

    public function testTheHappyPathResolves(): void
    {
        $config = Config::resolve(self::options());

        self::assertSame('my-org', $config->org);
        self::assertSame('my-project', $config->project);
        self::assertSame(Config::DEFAULT_HOST, $config->host);
        self::assertSame('https', $config->scheme);
        self::assertNull($config->port);
        self::assertSame(10.0, $config->timeout);
        self::assertTrue($config->keepAlive);
        self::assertFalse($config->debug);
        self::assertSame(50, $config->maxRequestEvents);
        self::assertNull($config->batch);
        self::assertNull($config->sourceId);
    }

    // ---- sourceId ----

    public function testSourceIdIsKeptAsAStringSoNoDigitsAreLost(): void
    {
        // 19 digits: past float precision, so a numeric round trip addresses a
        // different source with no error.
        $config = Config::resolve(self::options(['sourceId' => '684508596718616576']));

        self::assertSame('684508596718616576', $config->sourceId);
    }

    public function testANumericSourceIdIsCoercedToString(): void
    {
        $config = Config::resolve(self::options(['sourceId' => 12345]));

        self::assertSame('12345', $config->sourceId);
    }

    public function testAnEmptySourceIdIsRejectedRatherThanTreatedAsAbsent(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('"sourceId" must not be empty when provided');
        Config::resolve(self::options(['sourceId' => '  ']));
    }

    public function testAnAbsentSourceIdStaysNull(): void
    {
        self::assertNull(Config::resolve(self::options())->sourceId);
    }

    // ---- host and port ----

    public function testAHostWithoutAPortLeavesThePortUnset(): void
    {
        $config = Config::resolve(self::options(['host' => 'example.test']));

        self::assertSame('example.test', $config->host);
        self::assertNull($config->port);
        self::assertSame('https://example.test', $config->baseUrl());
    }

    public function testAHostCarryingAPortIsSplit(): void
    {
        $config = Config::resolve(self::options(['host' => '127.0.0.1:8080']));

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(8080, $config->port);
        self::assertSame('https://127.0.0.1:8080', $config->baseUrl());
    }

    public function testASurroundingSpaceInTheHostIsTrimmed(): void
    {
        self::assertSame('example.test', Config::resolve(self::options(['host' => ' example.test ']))->host);
    }

    public function testATrailingColonWithNoPortIsNotAPort(): void
    {
        $config = Config::resolve(self::options(['host' => 'example.test:']));

        self::assertSame('example.test', $config->host);
        self::assertNull($config->port);
    }

    /** @return list<array{0: mixed}> */
    public static function badHosts(): array
    {
        return [[''], ['   '], [123], [':8080']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badHosts')]
    public function testAnEmptyHostIsRejected(mixed $host): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('host must not be empty');
        Config::resolve(self::options(['host' => $host]));
    }

    /** @return list<array{0: string}> */
    public static function badPorts(): array
    {
        return [['example.test:abc'], ['example.test:0'], ['example.test:65536'], ['example.test:8o80']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPorts')]
    public function testAnUnusablePortIsRejectedBeforeItReachesCurl(string $host): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('invalid port in host');
        Config::resolve(self::options(['host' => $host]));
    }

    public function testThePortBoundsAreInclusive(): void
    {
        self::assertSame(1, Config::resolve(self::options(['host' => 'h:1']))->port);
        self::assertSame(65535, Config::resolve(self::options(['host' => 'h:65535']))->port);
    }

    // ---- scheme ----

    public function testHttpIsAllowedForLocalTesting(): void
    {
        self::assertSame('http', Config::resolve(self::options(['scheme' => 'http']))->scheme);
    }

    /** @return list<array{0: mixed}> */
    public static function badSchemes(): array
    {
        return [['ftp'], ['HTTPS'], [''], [123]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badSchemes')]
    public function testAnUnsupportedSchemeIsRejected(mixed $scheme): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('unsupported scheme');
        Config::resolve(self::options(['scheme' => $scheme]));
    }

    // ---- timeout ----

    public function testAnIntegerTimeoutBecomesAFloat(): void
    {
        self::assertSame(5.0, Config::resolve(self::options(['timeout' => 5]))->timeout);
    }

    /** @return list<array{0: mixed}> */
    public static function badTimeouts(): array
    {
        return [[0], [0.0], [-1], [-0.5], ['5'], [true], [[]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTimeouts')]
    public function testANonPositiveOrNonNumericTimeoutIsRejected(mixed $timeout): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('timeout must be a positive number of seconds');
        Config::resolve(self::options(['timeout' => $timeout]));
    }

    public function testTheSmallestUsableTimeoutIsAccepted(): void
    {
        self::assertSame(0.001, Config::resolve(self::options(['timeout' => 0.001]))->timeout);
    }

    // ---- maxRequestEvents ----

    public function testMaxRequestEventsAcceptsOne(): void
    {
        self::assertSame(1, Config::resolve(self::options(['maxRequestEvents' => 1]))->maxRequestEvents);
    }

    /** @return list<array{0: mixed}> */
    public static function badMaxRequestEvents(): array
    {
        return [[0], [-1], [1.5], ['50']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badMaxRequestEvents')]
    public function testMaxRequestEventsMustBeAPositiveInteger(mixed $value): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('maxRequestEvents must be a positive integer');
        Config::resolve(self::options(['maxRequestEvents' => $value]));
    }

    // ---- batch ----

    public function testABatchOptionsInstanceIsUsedAsGiven(): void
    {
        $batch = new BatchOptions(size: 7, flushMs: 900, maxQueue: 40, flushOnExit: false);
        $config = Config::resolve(self::options(['batch' => $batch]));

        self::assertSame($batch, $config->batch);
    }

    public function testAnArrayBatchIsBuiltWithTheDeclaredDefaults(): void
    {
        $config = Config::resolve(self::options(['batch' => []]));

        self::assertInstanceOf(BatchOptions::class, $config->batch);
        self::assertSame(50, $config->batch->size);
        self::assertSame(5_000, $config->batch->flushMs);
        self::assertSame(10_000, $config->batch->maxQueue);
        self::assertTrue($config->batch->flushOnExit);
    }

    public function testEachArrayBatchFieldOverridesItsDefault(): void
    {
        $config = Config::resolve(self::options(['batch' => [
            'size' => 3,
            'flushMs' => 250,
            'maxQueue' => 11,
            'flushOnExit' => false,
        ]]));

        self::assertInstanceOf(BatchOptions::class, $config->batch);
        self::assertSame(3, $config->batch->size);
        self::assertSame(250, $config->batch->flushMs);
        self::assertSame(11, $config->batch->maxQueue);
        self::assertFalse($config->batch->flushOnExit);
    }

    public function testABatchThatIsNeitherAnArrayNorBatchOptionsIsRejected(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('batch must be a BatchOptions or an array');
        Config::resolve(self::options(['batch' => 'yes please']));
    }

    /** @return list<array{0: array<string, mixed>, 1: string}> */
    public static function badBatchFields(): array
    {
        return [
            [['size' => 0], 'size'],
            [['size' => -1], 'size'],
            [['flushMs' => 0], 'flushMs'],
            [['maxQueue' => 0], 'maxQueue'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badBatchFields')]
    public function testBatchOptionsValidatesItsOwnFields(array $batch, string $field): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage($field);
        Config::resolve(self::options(['batch' => $batch]));
    }

    // ---- logger ----

    public function testALoggerIsKept(): void
    {
        $logger = new RecordingLogger();
        self::assertSame($logger, Config::resolve(self::options(['logger' => $logger]))->logger);
    }

    public function testAnAbsentLoggerFallsBackToTheNullLogger(): void
    {
        self::assertInstanceOf(NullLogger::class, Config::resolve(self::options())->logger());
    }

    public function testSomethingThatIsNotALoggerIsRejected(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('logger must implement');
        Config::resolve(self::options(['logger' => new \stdClass()]));
    }

    public function testAnExplicitNullFallsBackToTheDeclaredDefault(): void
    {
        // `?? default` treats null as "not supplied". Asserting it here so the
        // distinction between null and a bad value stays deliberate.
        $config = Config::resolve(self::options([
            'host' => null,
            'scheme' => null,
            'timeout' => null,
            'maxRequestEvents' => null,
        ]));

        self::assertSame(Config::DEFAULT_HOST, $config->host);
        self::assertSame('https', $config->scheme);
        self::assertSame(10.0, $config->timeout);
        self::assertSame(50, $config->maxRequestEvents);
    }

    public function testMergeTreatsAnExplicitNullTimeoutAsUnchanged(): void
    {
        self::assertSame(3.0, Config::resolve(self::options(['timeout' => 3.0]))->merge(['timeout' => null])->timeout);
    }

    // ---- url building ----

    public function testTheProjectPathCarriesOrgAndProject(): void
    {
        $config = Config::resolve(self::options());

        self::assertSame('/v1/my-org/projects/my-project/track', $config->projectPath('/track'));
    }

    public function testOrgAndProjectAreUrlEncoded(): void
    {
        $config = Config::resolve(self::options(['org' => 'a b/c', 'project' => 'd&e']));

        self::assertSame('/v1/a%20b%2Fc/projects/d%26e/track', $config->projectPath('/track'));
    }

    public function testAPathPrefixLeadsTheProjectPath(): void
    {
        $config = Config::resolve(self::options(['path' => '/proxy']));

        self::assertSame('/proxy/v1/my-org/projects/my-project/x', $config->projectPath('/x'));
    }

    // ---- merge ----

    /** @return list<array{0: string, 1: mixed}> */
    public static function fixedOptions(): array
    {
        return [
            ['org', 'other'],
            ['project', 'other'],
            ['apiKey', 'other.key'],
            ['sourceId', '1'],
            ['batch', []],
            ['keepAlive', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fixedOptions')]
    public function testEveryFixedOptionIsRefusedByMergeRatherThanIgnored(string $name, mixed $value): void
    {
        $config = Config::resolve(self::options());

        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage(sprintf('"%s" is fixed at construction', $name));
        $config->merge([$name => $value]);
    }

    public function testAFixedOptionIsRefusedEvenWhenItsValueIsNull(): void
    {
        // array_key_exists, not isset: passing null still means "I set this".
        $config = Config::resolve(self::options());

        $this->expectException(IntemptConfigException::class);
        $config->merge(['sourceId' => null]);
    }

    public function testMergeReturnsANewConfigAndLeavesTheOriginalAlone(): void
    {
        $config = Config::resolve(self::options());
        $merged = $config->merge(['timeout' => 2.5]);

        self::assertNotSame($config, $merged);
        self::assertSame(10.0, $config->timeout);
        self::assertSame(2.5, $merged->timeout);
    }

    public function testANewHostWithNoPortClearsTheOldPort(): void
    {
        $config = Config::resolve(self::options(['host' => 'first.test:9000']));
        self::assertSame(9000, $config->port);

        $merged = $config->merge(['host' => 'second.test']);

        self::assertSame('second.test', $merged->host);
        self::assertNull($merged->port, 'a stale port would address second.test:9000');
    }

    public function testANewHostCanCarryItsOwnPort(): void
    {
        $merged = Config::resolve(self::options())->merge(['host' => 'h.test:123']);

        self::assertSame('h.test', $merged->host);
        self::assertSame(123, $merged->port);
    }

    public function testMergeKeepsTheHostWhenThePatchDoesNotMentionIt(): void
    {
        $merged = Config::resolve(self::options(['host' => 'keep.test:77']))->merge(['debug' => true]);

        self::assertSame('keep.test', $merged->host);
        self::assertSame(77, $merged->port);
    }

    public function testMergeValidatesTheSchemeItIsGiven(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('unsupported scheme');
        Config::resolve(self::options())->merge(['scheme' => 'gopher']);
    }

    /** @return list<array{0: mixed}> */
    public static function badMergeTimeouts(): array
    {
        return [[0], [-1], [0.0], ['3']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badMergeTimeouts')]
    public function testMergeValidatesTheTimeoutItIsGiven(mixed $timeout): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('timeout must be a positive number of seconds');
        Config::resolve(self::options())->merge(['timeout' => $timeout]);
    }

    public function testMergeCarriesEveryUnpatchedValueForward(): void
    {
        $logger = new RecordingLogger();
        $batch = new BatchOptions(size: 4, flushMs: 100, maxQueue: 9, flushOnExit: false);
        $config = Config::resolve(self::options([
            'sourceId' => '99',
            'batch' => $batch,
            'keepAlive' => false,
            'logger' => $logger,
            'path' => '/base',
            'scheme' => 'http',
        ]));

        $merged = $config->merge(['debug' => true]);

        self::assertSame('my-org', $merged->org);
        self::assertSame('my-project', $merged->project);
        self::assertSame($config->credentials, $merged->credentials);
        self::assertSame('99', $merged->sourceId);
        self::assertSame($batch, $merged->batch);
        self::assertFalse($merged->keepAlive);
        self::assertSame($logger, $merged->logger);
        self::assertSame('/base', $merged->path);
        self::assertSame('http', $merged->scheme);
        self::assertTrue($merged->debug);
    }

    public function testMergeCanReplaceThePathAndMaxRequestEvents(): void
    {
        $merged = Config::resolve(self::options())->merge(['path' => '/p', 'maxRequestEvents' => 3]);

        self::assertSame('/p', $merged->path);
        self::assertSame(3, $merged->maxRequestEvents);
    }

    public function testAnEmptyPatchChangesNothingObservable(): void
    {
        $config = Config::resolve(self::options(['host' => 'h.test:5', 'timeout' => 3.0]));
        $merged = $config->merge([]);

        self::assertSame($config->host, $merged->host);
        self::assertSame($config->port, $merged->port);
        self::assertSame($config->timeout, $merged->timeout);
        self::assertSame($config->scheme, $merged->scheme);
        self::assertSame($config->debug, $merged->debug);
    }
}
