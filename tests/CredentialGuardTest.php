<?php

/**
 * The credential never appears in a dump of anything the SDK hands back.
 *
 * This exists because the same defect appeared independently in two SDKs: this
 * one exposed the raw key as `public readonly string $apiKey`, and the Python
 * SDK exposed it on its resolved config. Both printed the secret through an
 * ordinary print_r/repr of an object a caller legitimately holds. Node was clean
 * by luck of its type shape rather than by design, and luck is not a control —
 * so the equivalent of this file now exists in all three repos.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\ApiKeyCredentials;
use Intempt\Intempt;
use Intempt\IntemptConfigException;

final class CredentialGuardTest extends TestCase
{
    private const SECRET = 'sec0123456789abcdef';

    /** @return list<string> Every ordinary way a value ends up in a log. */
    private static function viewsOf(mixed $value): array
    {
        return [
            print_r($value, true),
            var_export($value, true),
            json_encode($value) ?: '',
            is_object($value) && method_exists($value, '__toString') ? (string) $value : '',
        ];
    }

    public function testNoViewOfTheConfigContainsTheSecret(): void
    {
        foreach (self::viewsOf($this->client()->config()) as $view) {
            self::assertStringNotContainsString(self::SECRET, $view);
        }
    }

    public function testTheConfigDoesNotExposeARawKeyProperty(): void
    {
        // The regression this guards: `public readonly string $apiKey` on Config,
        // which print_r() then prints in full.
        self::assertFalse(property_exists($this->client()->config(), 'apiKey'));
    }

    public function testTheConfigStillCarriesAUsableCredential(): void
    {
        // The fix must not have removed the credential, only the raw form.
        self::assertStringStartsWith(
            'Basic ',
            $this->client()->config()->credentials->authorizationHeader()
        );
    }

    public function testNoViewOfTheCredentialsContainsTheSecret(): void
    {
        foreach (self::viewsOf(new ApiKeyCredentials(self::API_KEY)) as $view) {
            self::assertStringNotContainsString(self::SECRET, $view);
        }
    }

    public function testItSaysItIsRedactedRatherThanLookingEmpty(): void
    {
        // An empty-looking dump reads as a bug; "redacted" reads as a decision.
        self::assertStringContainsString(
            'redacted',
            print_r(new ApiKeyCredentials(self::API_KEY), true)
        );
    }

    public function testThePrefixSurvivesForSupportPurposes(): void
    {
        // Enough to identify which key is in play without revealing it.
        self::assertStringContainsString(
            'pfx0123456789abcdef',
            (string) new ApiKeyCredentials(self::API_KEY)
        );
    }

    public function testTheEncodedFormDoesNotLeakEither(): void
    {
        // Base64 is not encryption; the header is as sensitive as the key.
        $encoded = base64_encode('pfx0123456789abcdef:' . self::SECRET);
        foreach (self::viewsOf($this->client()->config()) as $view) {
            self::assertStringNotContainsString($encoded, $view);
        }
    }

    public function testCredentialsRefuseToSerialise(): void
    {
        $this->expectException(IntemptConfigException::class);
        $this->expectExceptionMessage('not serialisable');
        serialize(new ApiKeyCredentials(self::API_KEY));
    }

    public function testAConfigErrorNeverEchoesTheKey(): void
    {
        // Error messages name the field, never the value: the value may be the
        // credential, and messages end up in logs.
        try {
            new Intempt(['org' => 'o', 'project' => 'p', 'apiKey' => 'no-dot-here']);
            self::fail('expected IntemptConfigException');
        } catch (IntemptConfigException $exception) {
            self::assertStringNotContainsString('no-dot-here', $exception->getMessage());
            self::assertStringContainsString('<prefix>.<secret>', $exception->getMessage());
        }
    }

    /**
     * PHP copies call arguments into every stack trace unless
     * zend.exception_ignore_args=1, so ANY function receiving the key as a
     * string has it in the trace of every later exception — including
     * getTraceAsString() and print_r() of the exception object.
     *
     * An SDK cannot fix that from inside: the caller's own
     * `new Intempt(['apiKey' => ...])` frame carries it. This documents the
     * exposure and proves the recommended setting removes it, so the README can
     * state the mitigation as measured fact rather than folklore.
     */
    public function testStackTracesCarryCallArgsUnlessPhpIsConfiguredOtherwise(): void
    {
        if (ini_get('zend.exception_ignore_args')) {
            self::markTestSkipped('zend.exception_ignore_args is already on');
        }

        $leaked = static function (string $secret): string {
            try {
                throw new \RuntimeException('boom');
            } catch (\Throwable $exception) {
                return $exception->getTraceAsString();
            }
        };

        self::assertStringContainsString(
            'SENTINELVALUE',
            $leaked('SENTINELVALUE'),
            'PHP captured the argument, which is why the README tells operators '
            . 'to set zend.exception_ignore_args=1'
        );
    }
}
