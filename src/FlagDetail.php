<?php

declare(strict_types=1);

namespace Intempt;

/**
 * A value and why it was returned.
 *
 * INTERNAL. Not part of the public surface -- see the note on variationDetailInternal().
 *
 * The reason would let a caller tell a deliberate off state from a request the service never
 * answered — the two used to be the same absent entry, which is why no SDK exposed assignment
 * until the serving contract could distinguish them.
 *
 * `$reason` is one of: targeted, holdout, not_targeted, off.
 */
final class FlagDetail
{
    public function __construct(
        public readonly mixed $value,
        public readonly string $reason,
    ) {
    }
}
