<?php

/**
 * The cross-SDK flag surface, per intempt-swift/docs/SDK-API-CONTRACT.md.
 *
 * The assertions that matter are the failure ones. A flag SDK is judged on what it returns when
 * the service is unreachable, not on the happy path.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\FlagContext;
use Intempt\IntemptConfigException;

final class FlagsTest extends TestCase
{
    private function context(): FlagContext
    {
        return new FlagContext(userId: 'u-1', profileId: 'p-1');
    }

    /** @param array<int, array<string, mixed>> $choices */
    private function expectChoices(array $choices): void
    {
        self::server()->expect(200, json_encode(['choices' => $choices], JSON_THROW_ON_ERROR));
    }

    public function testReturnsTheServedValue(): void
    {
        // variation(), not variationDetail() -- the detail method is internal until the platform
        // sends a reason. Note this fixture supplies 'group' and 'reason' and the serving response
        // carries NEITHER today, which is exactly why asserting on them proved nothing.
        $this->expectChoices([
            ['name' => 'checkout_v2', 'group' => 'B', 'body' => true, 'reason' => 'targeted'],
        ]);

        self::assertTrue($this->client()->variation('checkout_v2', $this->context(), false));
    }

    public function testReturnsTheDefaultWhenTheServedBodyIsNull(): void
    {
        // NOT the holdout case, which cannot be asserted: a held-back person's experience is
        // absent from the response entirely rather than present with a cause. Telling a holdout
        // from an outage needs a reason the platform does not send.
        $this->expectChoices([
            ['name' => 'checkout_v2', 'body' => null],
        ]);

        self::assertSame(
            'fallback',
            $this->client()->variation('checkout_v2', $this->context(), 'fallback')
        );
    }

    public function testReturnsTheDefaultWhenTheServiceIsUnreachable(): void
    {
        self::server()->expect(500, '{}');

        self::assertSame(
            'safe',
            $this->client()->variation('checkout_v2', $this->context(), 'safe')
        );
    }

    public function testReturnsTheDefaultWhenTheKeyIsUnknown(): void
    {
        $this->expectChoices([]);

        self::assertSame(
            'safe',
            $this->client()->variation('never_created', $this->context(), 'safe')
        );
    }

    public function testRefusesAnEmptyKey(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->client()->variation('   ', $this->context(), 'x');
    }

    public function testFallsBackRatherThanCoercingAWrongTypedValue(): void
    {
        // (bool) 'false' is true. A silent coercion is indistinguishable from a correct answer,
        // which is worse than returning the default the caller chose.
        $this->expectChoices([['name' => 'f', 'body' => 'false', 'reason' => 'targeted']]);

        self::assertFalse($this->client()->boolVariation('f', $this->context(), false));
    }

    public function testANumericStringIsNotANumber(): void
    {
        // is_numeric('42') is true, which is why the guard checks the type rather than the value.
        $this->expectChoices([['name' => 'f', 'body' => '42', 'reason' => 'targeted']]);

        self::assertSame(0, $this->client()->numberVariation('f', $this->context(), 0));
    }

    public function testAcceptsACorrectlyTypedValue(): void
    {
        $this->expectChoices([['name' => 'f', 'body' => 42, 'reason' => 'targeted']]);

        self::assertSame(42, $this->client()->numberVariation('f', $this->context(), 0));
    }

    public function testAllFlagsReturnsEveryKeyInOneCall(): void
    {
        $this->expectChoices([
            ['name' => 'a', 'body' => 1, 'reason' => 'targeted'],
            ['name' => 'b', 'body' => 2, 'reason' => 'targeted'],
        ]);

        self::assertSame(['a' => 1, 'b' => 2], $this->client()->allFlags($this->context()));
    }

    public function testWaitForInitializationReturnsImmediately(): void
    {
        $this->client()->waitForInitialization(5000);

        // Evaluation is remote, so there is no local store to wait for and no request is made.
        self::assertCount(0, self::server()->requests());
    }
}
