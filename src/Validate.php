<?php

/**
 * Small helpers.
 *
 * Portions derived from mixpanel-php (Apache License 2.0), as recorded in
 * NOTICE: timestamp() follows its timestamp normalisation and chunk() follows
 * its batching helper. Both were changed to reject bad input rather than coerce
 * it.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt;

final class Validate
{
    public static function present(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** Return a non-blank string or throw, naming the method and the field. */
    public static function nonBlank(mixed $value, string $method, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new IntemptConfigException(
                sprintf('%s: %s must be a non-empty string', $method, $field)
            );
        }

        return $value;
    }

    /**
     * A flag key the serving endpoint will accept.
     *
     * The backend declares `Set<@Pattern(regexp = "^[a-zA-Z0-9_-]*$") String> names`, so a key with
     * a dot or a space is a 400 — which this SDK's flag path absorbs into a silent default, exactly
     * the class of caller mistake this repo's own rule says must fail loudly at the call site
     * instead. Blankness is already rejected by nonBlank(), so the pattern here is `+`, not `*`.
     */
    public static function flagKey(mixed $value, string $method): string
    {
        $key = self::nonBlank($value, $method, 'key');
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $key) !== 1) {
            throw new IntemptConfigException(sprintf(
                '%s: key must match ^[a-zA-Z0-9_-]+$ (letters, digits, underscore, hyphen); '
                    . 'the service rejects anything else with a 400',
                $method
            ));
        }

        return $key;
    }

    /**
     * At least one of userId or accountId must be present and non-blank.
     *
     * Truthiness is not enough: a run of spaces is truthy and is not an
     * identifier.
     *
     * @param array<string, mixed> $options
     */
    public static function identifier(array $options, string $method): void
    {
        foreach (['userId', 'accountId', 'profileId'] as $field) {
            if (self::present($options[$field] ?? null)) {
                return;
            }
        }

        throw new IntemptConfigException(
            sprintf('%s: one of userId or accountId is required', $method)
        );
    }

    /**
     * Epoch milliseconds from a DateTimeInterface or a number.
     *
     * Takes `mixed` on purpose: the value arrives from a caller-supplied array,
     * so validating it here is the point. No `is_bool` guard — unlike Python,
     * where `bool` is a subclass of `int` and `true` would sail through an
     * int check, PHP treats them as distinct and `!is_int(true)` already
     * rejects it. The guard was ported from the Python SDK and is dead here.
     */
    public static function timestamp(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return (int) round((float) $value->format('U.u') * 1000);
        }
        if (!is_int($value) && !is_float($value)) {
            throw new IntemptConfigException(
                'timestamp must be a DateTimeInterface or epoch milliseconds'
            );
        }
        if (is_float($value) && !is_finite($value)) {
            throw new IntemptConfigException('timestamp must be a finite number of milliseconds');
        }

        return (int) $value;
    }

    public static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Split into equal-sized chunks, last chunk being the remainder.
     *
     * A size below one is rejected: the loop would never advance, so clamping
     * it silently would hang instead of failing.
     *
     * @param list<mixed> $items
     *
     * @return list<list<mixed>>
     */
    public static function chunk(array $items, int $size): array
    {
        if ($size < 1) {
            throw new IntemptConfigException('chunk size must be at least 1');
        }

        return array_values(array_map('array_values', array_chunk($items, $size)));
    }

    /**
     * Drop keys whose value is null so they never reach the wire.
     *
     * Only null is dropped. false, 0 and "" are values the caller chose.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public static function compact(array $values): array
    {
        return array_filter($values, static fn ($value) => $value !== null);
    }

    /** RFC 4122 v4, without pulling in a uuid dependency for one function. */
    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
