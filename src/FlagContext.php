<?php

declare(strict_types=1);

namespace Intempt;

/**
 * Who is being evaluated.
 *
 * `profileId` is the anonymous/device identifier. Supplying the same value before and after a
 * person signs in is what keeps their assignment stable across the transition.
 */
final class FlagContext
{
    public function __construct(
        public readonly ?string $userId = null,
        public readonly ?string $accountId = null,
        public readonly ?string $profileId = null,
    ) {
    }
}
