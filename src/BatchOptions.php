<?php

/**
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt;

/** Buffering behaviour. Null for `batch` disables buffering entirely. */
final class BatchOptions
{
    public function __construct(
        public readonly int $size = 50,
        public readonly int $flushMs = 5_000,
        public readonly int $maxQueue = 10_000,
        public readonly bool $flushOnExit = true,
    ) {
        if ($size < 1) {
            throw new IntemptConfigException('batch.size must be at least 1');
        }
        if ($flushMs < 1) {
            throw new IntemptConfigException('batch.flushMs must be at least 1');
        }
        if ($maxQueue < $size) {
            throw new IntemptConfigException('batch.maxQueue must be at least batch.size');
        }
    }
}
