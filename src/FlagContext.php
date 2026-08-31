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
 *
 * **An identity is required, and it is checked.** Removing `accountId` closed the one DOCUMENTED
 * way to build a context the service cannot answer; it did not close the others. An empty context,
 * a blank `userId`, or a `profileId` on a client with no configured `sourceId` all still reach
 * `buildAudienceRequest`, which throws — and this SDK absorbs a service failure by design, so the
 * caller would serve defaults forever and see a working integration. `hasIdentity()` states the
 * service's own condition and the flag methods assert it at the call site, which is the same rule
 * `Validate::flagKey()` already applies to a key the service would reject.
 */
final class FlagContext
{
    public function __construct(
        public readonly ?string $userId = null,
        public readonly ?string $profileId = null,
        public readonly ?string $sessionId = null,
    ) {
    }

    /**
     * Whether the service can answer this context at all.
     *
     * Mirrors `ExperienceChooserService.buildAudienceRequest`: the PROFILE branch needs a source id
     * AND a non-blank profile id, the USER branch needs a user id, and there is no third branch —
     * it throws. So this is not a style check. A context satisfying neither makes the service throw
     * before it evaluates anything, `chooseOrEmpty()` absorbs that by design, and the caller gets
     * their default for every key, forever, behind one WARN line.
     *
     * Blankness is checked, not truthiness: a run of spaces is truthy and is not an identifier, and
     * the service's own test is `isBlank()`.
     */
    public function hasIdentity(int|string|null $sourceId): bool
    {
        if (Validate::present($this->userId)) {
            return true;
        }

        return Validate::present($this->profileId)
            && Validate::present($sourceId === null ? null : (string) $sourceId);
    }
}
