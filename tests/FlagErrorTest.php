<?php

/**
 * Flag errors are visible (INT-7454, CONTRACT-R2 §S3): the default is still returned and nothing
 * throws, but onFlagError hears what went wrong, 401/403 log at error level once, and
 * variationDetail() says `reason: 'error'` instead of passing an outage off as "off".
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\FlagContext;
use Intempt\FlagError;
use Intempt\Intempt;
use Intempt\IntemptConfigException;

final class FlagErrorTest extends TestCase
{
    /** @var list<FlagError> */
    private array $errors = [];

    /** @param array<string, mixed> $overrides */
    private function flagClient(array $overrides = []): Intempt
    {
        $this->errors = [];

        return $this->client($overrides + ['onFlagError' => function (FlagError $error): void {
            $this->errors[] = $error;
        }]);
    }

    private function context(): FlagContext
    {
        return new FlagContext(userId: 'u-1');
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function statuses(): array
    {
        return [
            '401' => [401, 'auth'],
            '403' => [403, 'auth'],
            '400' => [400, 'bad_request'],
            '404' => [404, 'bad_request'],
            '500' => [500, 'server'],
            '503' => [503, 'server'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statuses')]
    public function testAnErrorStatusReachesTheHookAndTheDefaultIsReturned(int $status, string $kind): void
    {
        self::server()->expect($status, '{}');
        $value = $this->flagClient()->variation('checkout_v2', $this->context(), 'fallback');

        self::assertSame('fallback', $value);
        self::assertCount(1, $this->errors);
        self::assertSame('checkout_v2', $this->errors[0]->key);
        self::assertSame($kind, $this->errors[0]->kind);
        self::assertSame($status, $this->errors[0]->status);
    }

    public function testATimeoutIsReportedAsTimeout(): void
    {
        self::server()->expect(200, '{}', [], delayMs: 900);
        $this->flagClient(['timeout' => 0.15])->variation('k', $this->context(), false);

        self::assertSame('timeout', $this->errors[0]->kind);
        self::assertNull($this->errors[0]->status);
    }

    public function testAnUnreachableServiceIsReportedAsNetwork(): void
    {
        $this->flagClient(['host' => '127.0.0.1:1'])->variation('k', $this->context(), false);

        self::assertSame('network', $this->errors[0]->kind);
    }

    public function testAnUnparseableAnswerIsReportedAsParse(): void
    {
        self::server()->expect(200, '<html>gateway</html>');
        $value = $this->flagClient()->variation('k', $this->context(), 'd');

        self::assertSame('d', $value);
        self::assertSame('parse', $this->errors[0]->kind);
    }

    /** @return array<string, array{0: string}> */
    public static function choicesThatAreNotAList(): array
    {
        return [
            'a string' => ['{"choices":"x"}'],
            'an object' => ['{"choices":{"a":1}}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('choicesThatAreNotAList')]
    public function testChoicesThatAreNotAListAreReportedAsParse(string $body): void
    {
        self::server()->expect(200, $body);
        $detail = $this->flagClient()->variationDetail('k', $this->context(), 'd');

        self::assertSame('d', $detail->value);
        self::assertSame('error', $detail->reason);
        self::assertCount(1, $this->errors);
        self::assertSame('k', $this->errors[0]->key);
        self::assertSame('parse', $this->errors[0]->kind);
        self::assertNull($this->errors[0]->status, 'parity with intempt-node: parse carries no status');
        self::assertTrue($this->logger->has('warning', 'flag evaluation failed'));
    }

    public function testAllFlagsReportsChoicesThatAreNotAListWithANullKey(): void
    {
        self::server()->expect(200, '{"choices":"x"}');
        self::assertSame([], $this->flagClient()->allFlags($this->context()));

        self::assertCount(1, $this->errors);
        self::assertNull($this->errors[0]->key);
        self::assertSame('parse', $this->errors[0]->kind);
    }

    public function testAnAnswerWithNoChoicesFieldIsNotAnError(): void
    {
        self::server()->expect(200, '{}');
        $detail = $this->flagClient()->variationDetail('k', $this->context(), 'd');

        self::assertSame([], $this->errors);
        self::assertSame('off', $detail->reason);
    }

    public function testAllFlagsReportsWithANullKey(): void
    {
        self::server()->expect(500, '{}');
        self::assertSame([], $this->flagClient()->allFlags($this->context()));

        self::assertNull($this->errors[0]->key);
        self::assertSame('server', $this->errors[0]->kind);
    }

    public function testAnAnswerWithNoChoiceIsNotAnError(): void
    {
        self::server()->expect(200, '{"choices":[]}');
        $detail = $this->flagClient()->variationDetail('k', $this->context(), 'd');

        self::assertSame([], $this->errors);
        self::assertSame('d', $detail->value);
        self::assertSame('off', $detail->reason, 'INT-7457: the backend sends no reason');
        self::assertNull($detail->error);
    }

    public function testVariationDetailSaysErrorOnFailure(): void
    {
        self::server()->expect(401, '{}');
        $detail = $this->flagClient()->variationDetail('k', $this->context(), 'd');

        self::assertSame('d', $detail->value);
        self::assertSame('error', $detail->reason);
        self::assertNotNull($detail->error);
        self::assertSame('auth', $detail->error->kind);
        self::assertSame(401, $detail->error->status);
    }

    public function testVariationDetailReturnsAServedValue(): void
    {
        self::server()->expect(200, '{"choices":[{"name":"k","body":"blue"}]}');
        $detail = $this->flagClient()->variationDetail('k', $this->context(), 'd');

        self::assertSame('blue', $detail->value);
        self::assertNull($detail->error);
    }

    public function testAnAuthFailureLogsAtErrorOnceThenAtDebug(): void
    {
        self::server()->expect(401, '{}');
        self::server()->expect(401, '{}');
        $client = $this->flagClient();
        $client->variation('a', $this->context(), false);
        $client->variation('b', $this->context(), false);

        $errors = $this->logger->matching('error', 'rejected the credentials');
        self::assertCount(1, $errors, 'once per client instance');
        self::assertStringContainsString('pfx0123456789abcdef', $errors[0], 'names the key by its prefix');
        self::assertStringContainsString(self::SOURCE, $errors[0]);
        self::assertStringNotContainsString('sec0123456789abcdef', $errors[0]);
        self::assertCount(1, $this->logger->matching('debug', 'rejected the credentials'));
    }

    public function testAHookThatThrowsIsSwallowedAndLogged(): void
    {
        self::server()->expect(500, '{}');
        $client = $this->client(['onFlagError' => static function (): void {
            throw new \RuntimeException('hook bug');
        }]);

        self::assertSame('d', $client->variation('k', $this->context(), 'd'));
        self::assertTrue($this->logger->has('error', 'onFlagError hook threw'));
    }

    public function testANonCallableHookIsRefused(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('onFlagError must be callable');
        $this->client(['onFlagError' => 42]);
    }
}
