<?php

/**
 * Evaluation is separate from exposure (INT-7456, CONTRACT-R2 §S4).
 *
 * allFlags() evaluates without recording an exposure (`exposure: false`), so one call at request
 * start no longer enrols the caller in every running experiment. A single-flag read records the
 * exposure as before (field absent, so an old backend behaves exactly as today), unless the caller
 * opts out per call with `disableExposure: true` — for a pre-render or a prefetch.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\FlagContext;

final class ExposureTest extends TestCase
{
    private function context(): FlagContext
    {
        return new FlagContext(userId: 'u-1');
    }

    /** @return array<string, mixed> */
    private function sentBody(): array
    {
        $body = self::server()->requests()[0]['body'];
        self::assertIsArray($body);

        return $body;
    }

    public function testAllFlagsEvaluatesWithoutRecordingAnExposure(): void
    {
        self::server()->expect(200, '{"choices":[]}');
        $this->client()->allFlags($this->context());

        self::assertFalse($this->sentBody()['exposure']);
    }

    public function testASingleReadRecordsTheExposureAsBefore(): void
    {
        self::server()->expect(200, '{"choices":[]}');
        $this->client()->variation('k', $this->context(), 'd');

        self::assertArrayNotHasKey('exposure', $this->sentBody(), 'absent = record, so old backends are unchanged');
    }

    public function testASingleReadCanOptOutOfTheExposure(): void
    {
        self::server()->expect(200, '{"choices":[{"name":"k","body":"v"}]}');
        $value = $this->client()->variation('k', $this->context(), 'd', disableExposure: true);

        self::assertSame('v', $value);
        self::assertFalse($this->sentBody()['exposure']);
        self::assertSame(['k'], $this->sentBody()['names']);
    }

    public function testVariationDetailCanOptOutOfTheExposure(): void
    {
        self::server()->expect(200, '{"choices":[]}');
        $this->client()->variationDetail('k', $this->context(), 'd', disableExposure: true);

        self::assertFalse($this->sentBody()['exposure']);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function typedReads(): array
    {
        return [
            'bool' => ['boolVariation', false],
            'string' => ['stringVariation', 'd'],
            'number' => ['numberVariation', 1],
            'json' => ['jsonVariation', []],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('typedReads')]
    public function testTypedReadsCanOptOutOfTheExposure(string $method, mixed $default): void
    {
        self::server()->expect(200, '{"choices":[]}');
        $this->client()->{$method}('k', $this->context(), $default, disableExposure: true);

        self::assertFalse($this->sentBody()['exposure']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('typedReads')]
    public function testTypedReadsRecordByDefault(string $method, mixed $default): void
    {
        self::server()->expect(200, '{"choices":[]}');
        $this->client()->{$method}('k', $this->context(), $default);

        self::assertArrayNotHasKey('exposure', $this->sentBody());
    }
}
