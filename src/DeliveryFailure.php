<?php

/**
 * A batch's final delivery outcome, passed to `onDeliveryFailure` (CONTRACT-R2 §S2).
 *
 * `kind`:
 * - `transport`: the server was never reached and the batch is kept for the next
 *   flush (terminal false). Reported once per batch, not on every flush.
 * - `terminal`: the server answered a drop status, or the one 503/429 resend failed.
 * - `dropped`: dropped by the SDK — timeout after the request was written, queue
 *   full, a Retry-After past the 5 s cap, abandoned at close(), or never reached
 *   in direct mode, which has no queue to keep it in.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt;

final class DeliveryFailure
{
    /**
     * @param 'transport'|'terminal'|'dropped' $kind
     * @param list<string>                     $eventIds
     * @param float|null                       $retryAfter seconds, when the server sent Retry-After
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?int $status,
        public readonly int $eventCount,
        public readonly array $eventIds,
        public readonly ?float $retryAfter,
        public readonly bool $terminal,
    ) {
    }
}
