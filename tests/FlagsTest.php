<?php

/**
 * The cross-SDK flag surface, per intempt-swift/docs/SDK-API-CONTRACT.md.
 *
 * Two kinds of assertion matter here and neither is the happy path.
 *
 * The first is failure: a flag SDK is judged on what it returns when the service is unreachable.
 *
 * The second is the REQUEST. Every case below used to assert only on a canned reply, which meant
 * `device => 'all'` could be deleted with the whole suite still green — while every flag in
 * production returned its default, because the service turns a null device into the SQL predicate
 * "0" and matches nothing. A response assertion cannot see that. See docs/TESTING.md: the socket is
 * the point.
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

    /** @return array<string, mixed> */
    private function sentBody(): array
    {
        $body = self::server()->requests()[0]['body'];
        self::assertIsArray($body);

        return $body;
    }

    // -- the outgoing request ---------------------------------------------

    public function testTheRequestGoesToTheServingEndpoint(): void
    {
        $this->expectChoices([]);
        $this->client()->variation('checkout_v2', $this->context(), 'x');

        $request = self::server()->requests()[0];
        self::assertSame('POST', $request['method']);
        self::assertSame(
            sprintf('/v1/%s/projects/%s/optimization/choose-api', self::ORG, self::PROJECT),
            $request['path']
        );
    }

    public function testTheRequestCarriesDeviceAll(): void
    {
        // The single most load-bearing field in this file. ExperienceRequest splices it into the
        // serving SQL as a raw predicate: null -> "0", which matches nothing forever; ALL -> "1".
        // Deleting `'device' => 'all'` from chooseOrEmpty() must turn this red.
        $this->expectChoices([]);
        $this->client()->variation('checkout_v2', $this->context(), 'x');

        self::assertSame('all', $this->sentBody()['device']);
    }

    public function testTheRequestNamesOnlyTheKeyAskedFor(): void
    {
        $this->expectChoices([]);
        $this->client()->variation('checkout_v2', $this->context(), 'x');

        self::assertSame(['checkout_v2'], $this->sentBody()['names']);
    }

    public function testTheRequestIdentifiesTheEntity(): void
    {
        $this->expectChoices([]);
        $this->client()->variation('checkout_v2', $this->context(), 'x');

        $identification = $this->sentBody()['identification'];
        self::assertSame('u-1', $identification['userId']);
        self::assertSame('p-1', $identification['profileId']);
        // A 19-digit snowflake past 2**53: (int) would round it and address another source.
        self::assertSame(self::SOURCE, $identification['sourceId']);
        self::assertIsString($identification['sourceId']);
    }

    public function testAnAbsentIdentifierIsOmittedRatherThanSentAsNull(): void
    {
        $this->expectChoices([]);
        $this->client()->variation('checkout_v2', new FlagContext(userId: 'u-1'), 'x');

        self::assertArrayNotHasKey('profileId', $this->sentBody()['identification']);
    }

    public function testTheSessionIdIsSentWhenSupplied(): void
    {
        // Without it every exposure lands in a session the service names "default", and a
        // ONCE_PER_VISIT experience serves an entity once and then never again.
        $this->expectChoices([]);
        $this->client()->variation(
            'checkout_v2',
            new FlagContext(userId: 'u-1', sessionId: 'sess-9'),
            'x'
        );

        self::assertSame('sess-9', $this->sentBody()['sessionId']);
    }

    public function testTheSessionIdIsOmittedWhenNotSupplied(): void
    {
        $this->expectChoices([]);
        $this->client()->variation('checkout_v2', $this->context(), 'x');

        self::assertArrayNotHasKey('sessionId', $this->sentBody());
    }

    public function testAllFlagsOmitsNamesSoTheServiceReturnsEveryKey(): void
    {
        // Present as well as absent: `names => []` would ask for nothing rather than everything.
        $this->expectChoices([]);
        $this->client()->allFlags($this->context());

        $body = $this->sentBody();
        self::assertArrayNotHasKey('names', $body);
        self::assertSame('all', $body['device']);
    }

    public function testThereIsNoAccountIdParameter(): void
    {
        // An account-only identification satisfies neither branch of
        // ExperienceChooserService.buildAudienceRequest, so the service throws before evaluating
        // anything - and chooseOrEmpty()'s catch absorbs that, handing the caller their default for
        // every flag, forever, behind one WARN line. The parameter is removed rather than ignored;
        // re-adding it turns this red.
        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line -- asserting the parameter does NOT exist is the point
        new FlagContext(accountId: 'a-1');
    }

    // -- the response ------------------------------------------------------

    public function testReturnsTheServedValue(): void
    {
        // No 'reason' in this fixture: the serving response does not carry one, and a fixture that
        // invents one made the SDK's reason handling look live while being unreachable.
        $this->expectChoices([
            ['name' => 'checkout_v2', 'group' => 'B', 'body' => true],
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

    public function testRefusesAKeyTheServiceWouldReject(): void
    {
        // Backend: Set<@Pattern("^[a-zA-Z0-9_-]*$") String> names. A dot is a 400, and this SDK
        // absorbs a 400 into a silent default - so it has to fail here, at the call site.
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('^[a-zA-Z0-9_-]+$');
        $this->client()->variation('checkout.v2', $this->context(), 'x');
    }

    public function testAcceptsEveryCharacterTheServiceAllows(): void
    {
        // The reverse check. A pattern that also rejects a legal key would be invisible above.
        $this->expectChoices([['name' => 'Checkout_v2-B9', 'body' => 'served']]);

        self::assertSame(
            'served',
            $this->client()->variation('Checkout_v2-B9', $this->context(), 'x')
        );
    }

    public function testFallsBackRatherThanCoercingAWrongTypedValue(): void
    {
        // (bool) 'false' is true. A silent coercion is indistinguishable from a correct answer,
        // which is worse than returning the default the caller chose.
        $this->expectChoices([['name' => 'f', 'body' => 'false']]);

        self::assertFalse($this->client()->boolVariation('f', $this->context(), false));
    }

    public function testANumericStringIsNotANumber(): void
    {
        // is_numeric('42') is true, which is why the guard checks the type rather than the value.
        $this->expectChoices([['name' => 'f', 'body' => '42']]);

        self::assertSame(0, $this->client()->numberVariation('f', $this->context(), 0));
    }

    public function testAcceptsACorrectlyTypedValue(): void
    {
        $this->expectChoices([['name' => 'f', 'body' => 42]]);

        self::assertSame(42, $this->client()->numberVariation('f', $this->context(), 0));
    }

    public function testJsonVariationReturnsAServedObject(): void
    {
        $this->expectChoices([['name' => 'cfg', 'body' => ['tier' => 'gold', 'limit' => 5]]]);

        self::assertSame(
            ['tier' => 'gold', 'limit' => 5],
            $this->client()->jsonVariation('cfg', $this->context(), [])
        );
    }

    public function testJsonVariationReturnsAServedArrayAsAList(): void
    {
        // The reason the @param is array-key and not string: a JSON array decodes to a PHP list.
        $this->expectChoices([['name' => 'cfg', 'body' => ['a', 'b']]]);

        self::assertSame(['a', 'b'], $this->client()->jsonVariation('cfg', $this->context(), []));
    }

    public function testJsonVariationFallsBackOnAScalarBody(): void
    {
        $this->expectChoices([['name' => 'cfg', 'body' => 'not-json']]);

        self::assertSame(
            ['fallback' => true],
            $this->client()->jsonVariation('cfg', $this->context(), ['fallback' => true])
        );
    }

    public function testAllFlagsReturnsEveryKeyInOneCall(): void
    {
        $this->expectChoices([
            ['name' => 'a', 'body' => 1],
            ['name' => 'b', 'body' => 2],
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
