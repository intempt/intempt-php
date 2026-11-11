<?php

declare(strict_types=1);

namespace Intempt;

/**
 * A value and why it was returned, from variationDetail().
 *
 * `$reason` is `off` or `error`. The service sends no reason (INT-7457), so a served value and
 * an unassigned key both read `off`; only `error` is a real distinction: the default was returned
 * because the call failed, and `$error` says how.
 */
final class FlagDetail
{
    public function __construct(
        public readonly mixed $value,
        public readonly string $reason,
        public readonly ?FlagError $error = null,
    ) {
    }
}
