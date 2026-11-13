<?php

/**
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt;

/** Consent records. Timestamps here are epoch **seconds**, not milliseconds. */
final class Consent
{
    public function __construct(private readonly Intempt $client)
    {
    }

    /**
     * True when the server took the record. A delivery failure returns false, is logged at error
     * level and reaches onDeliveryFailure once (ruling R2); only a caller mistake throws.
     *
     * @param array<string, mixed> $options
     */
    public function grant(array $options): bool
    {
        return $this->record('accept', $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @see self::grant() for the return value
     */
    public function revoke(array $options): bool
    {
        return $this->record('reject', $options);
    }

    /** @param array<string, mixed> $options */
    private function record(string $action, array $options): bool
    {
        $name = $action === 'accept' ? 'consent.grant' : 'consent.revoke';
        $userId = $options['userId'] ?? null;
        $profileId = $options['profileId'] ?? null;
        if (!Validate::present($userId) && !Validate::present($profileId)) {
            throw new IntemptConfigException(
                sprintf('%s: userId must be a non-empty string', $name)
            );
        }

        // Deliberately NOT gated on opt-out (ruling D-5, INT-7437): a withdrawal must reach the
        // server precisely when the person has opted out. intempt-js consent() does the same.
        $this->client->assertOpen();

        $config = $this->client->config();
        if (Validate::present($profileId) && $config->sourceId === null) {
            throw new IntemptConfigException(
                'consent: sourceId must be configured to record consent by profileId; '
                . 'pass userId instead, or set sourceId on the client'
            );
        }

        $raw = $options['timestamp'] ?? null;
        $millis = $raw !== null ? Validate::timestamp($raw) : Validate::nowMs();

        $body = Validate::compact([
            'action' => $action,
            // Seconds, not milliseconds. The consent endpoint compares
            // timestamp * 1000 against millisecond bounds, so sending
            // milliseconds here puts the value far past 2040, where the server
            // silently replaces it with its own clock.
            'timestamp' => intdiv($millis, 1000),
            'userId' => $userId,
            'profileId' => $profileId,
            'category' => $options['category'] ?? null,
            'validUntil' => $options['validUntil'] ?? 'unlimited',
            'email' => $options['email'] ?? null,
            'message' => $options['message'] ?? null,
            'reason' => $options['reason'] ?? null,
            'method' => $options['method'] ?? null,
            'deviceInfo' => $options['deviceInfo'] ?? null,
            'source' => 'PHP tracker',
            // (string), never (int): a 19-digit snowflake loses precision.
            'sourceId' => Validate::present($profileId) && $config->sourceId !== null
                ? (string) $config->sourceId
                : null,
        ]);

        return $this->client->deliverRecord($config->projectPath('/consents/data'), $body);
    }
}
