<?php

declare(strict_types=1);

namespace Intempt;

/**
 * Who is being evaluated.
 *
 * `profileId` is the anonymous/device identifier. Supplying the same value before and after a
 * person signs in is what keeps their assignment stable across the transition.
 *
 * **Precedence is not the order of these arguments.** The service resolves the entity in
 * `ExperienceChooserService.buildAudienceRequest`, which tests `sourceId != null && profileId is
 * non-blank` FIRST and only falls through to `userId`. So a context carrying both, sent by a client
 * with a configured `sourceId`, segments on the PROFILE and the `userId` is never read. Supply one
 * deliberately. `EXP-ASSIGN-005` makes the choice of identifier the caller's responsibility, which
 * only works if this precedence is stated rather than discovered.
 *
 * **There is no `accountId`, and that is not an omission.** The serving endpoint's `Identification`
 * carries exactly `sourceId`, `profileId` and `userId`. An account-only request satisfies neither
 * branch above, so the service throws before it evaluates anything — and this SDK absorbs a service
 * failure by design, so such a caller would receive their default for every flag, forever, behind a
 * single WARN line. A declared parameter that silently disables the SDK is worse than no parameter,
 * so a caller who passes one now gets an immediate `Error: Unknown named parameter $accountId` at
 * the call site, which is a programming error they can fix.
 *
 * `sessionId` is optional and scopes exposure counting. Omitting it is not free: the service files
 * every exposure under a session literally named `default`, and a `ONCE_PER_VISIT` experience then
 * serves an entity once and never again, because the stored session always equals the incoming one.
 */
final class FlagContext
{
    public function __construct(
        public readonly ?string $userId = null,
        public readonly ?string $profileId = null,
        public readonly ?string $sessionId = null,
    ) {
    }
}
