<?php

/**
 * Why a flag call fell back to the default (INT-7454, CONTRACT-R2 §S3).
 *
 * `kind`: auth (401/403), bad_request (other 4xx), server (5xx and 429), timeout
 * (no answer in time, or 408), network (never reached the service), parse (an
 * answer that is not the JSON object the service sends, or whose `choices` is present but
 * not a list; a missing `choices` is no choice, not an error). `key` is null for allFlags().
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt;

final class FlagError
{
    /** @param 'auth'|'bad_request'|'server'|'timeout'|'network'|'parse' $kind */
    public function __construct(
        public readonly ?string $key,
        public readonly string $kind,
        public readonly ?int $status = null,
    ) {
    }
}
