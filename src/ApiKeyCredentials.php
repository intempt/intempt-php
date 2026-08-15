<?php

/**
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt;

/**
 * Holds the key and hands out only the encoded header.
 *
 * The secret lives on a private property and every method that would otherwise
 * print it is overridden. PHP has no true private state against var_dump of an
 * object graph, so this is a guardrail rather than a wall — but it keeps the
 * credential out of a stack trace, a log line and a debugger dump, which is
 * where it actually leaks.
 */
final class ApiKeyCredentials
{
    /**
     * The encoded header, held outside the object.
     *
     * `__debugInfo()` redacts print_r() and var_dump(), but **not**
     * var_export(), which walks private properties directly and emits them in a
     * `__set_state()` call. A guard test caught that: the credential was clean
     * in two dump paths and leaked in the third.
     *
     * A WeakMap keyed on the instance keeps the secret off the object entirely,
     * so no reflection-based printer can reach it, and the entry is collected
     * with the object rather than leaking memory.
     */
    private static ?\WeakMap $headers = null;

    private readonly string $prefix;

    public function __construct(string $apiKey)
    {
        $parts = explode('.', $apiKey, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new IntemptConfigException(
                'apiKey must be a public API key in "<prefix>.<secret>" form'
            );
        }
        $this->prefix = $parts[0];
        self::$headers ??= new \WeakMap();
        self::$headers[$this] = 'Basic ' . base64_encode($parts[0] . ':' . $parts[1]);
    }

    public function authorizationHeader(): string
    {
        return self::$headers[$this]
            ?? throw new IntemptConfigException('credentials were not initialised');
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['prefix' => $this->prefix, 'secret' => '<redacted>'];
    }

    public function __toString(): string
    {
        return sprintf('ApiKeyCredentials(prefix=%s, secret=<redacted>)', $this->prefix);
    }

    public function __serialize(): array
    {
        throw new IntemptConfigException('ApiKeyCredentials is not serialisable');
    }
}
