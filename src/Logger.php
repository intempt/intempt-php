<?php

/**
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt;

/**
 * Minimal logger contract.
 *
 * Deliberately not PSR-3: requiring psr/log would put a dependency in every
 * consumer's tree for four methods. A PSR-3 logger satisfies this shape anyway,
 * so passing one works without an adapter.
 */
interface Logger
{
    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void;
}
