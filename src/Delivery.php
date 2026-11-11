<?php

/**
 * The one delivery policy, shared by the buffer, direct mode and consent.
 *
 * CONTRACT-R2 §S1 (INT-7436): send once; 503 and 429 get exactly one resend
 * after Retry-After clamped to [1 s, 60 s]; every other answered status, and a
 * timeout after the request was written, drops. A wait past 5 s drops instead of
 * sleeping, because a PHP SDK sleeps inside the caller's web request. Only a
 * request that never reached the server may be kept, and only where there is a
 * queue to keep it in.
 *
 * Nothing here throws to the caller: a delivery problem is reported, never
 * raised (DECISIONS 7444).
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 *
 * @internal
 */

declare(strict_types=1);

namespace Intempt;

final class Delivery
{
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const KEPT = 'kept';

    private const MIN_WAIT_MS = 1_000;
    private const MAX_WAIT_MS = 60_000;
    private const SERVER_WAIT_CAP_MS = 5_000;

    private readonly \Closure $sleep;

    /** @var array<string, true> event ids whose batch already reported `transport` */
    private array $transportReported = [];

    /**
     * @param (\Closure(int): void)|null             $sleep     milliseconds; injectable so tests do not sleep
     * @param (\Closure(DeliveryFailure): void)|null $onFailure
     */
    public function __construct(
        private readonly Logger $logger,
        ?\Closure $sleep = null,
        private readonly ?\Closure $onFailure = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * @param \Closure(): mixed           $send  throws on failure
     * @param list<array<string, mixed>> $batch what $send carries, for the report
     * @param bool                       $canKeep false where there is no queue (direct mode)
     *
     * @return self::SENT|self::FAILED|self::KEPT
     */
    public function deliver(\Closure $send, array $batch, bool $canKeep, ?float $deadline = null): string
    {
        try {
            $send();

            return $this->settled($batch, self::SENT);
        } catch (\Throwable $error) {
            $first = $error;
        }

        if (self::isResendable($first)) {
            $waitMs = self::waitMs($first);
            if ($waitMs > self::SERVER_WAIT_CAP_MS
                || ($deadline !== null && microtime(true) + $waitMs / 1000 >= $deadline)) {
                return $this->drop($batch, $first, sprintf('wait of %dms is past the cap', $waitMs), 'dropped');
            }
            ($this->sleep)($waitMs);
            try {
                $send();

                return $this->settled($batch, self::SENT);
            } catch (\Throwable $error) {
                $first = $error;
                if (self::isResendable($first)) {
                    return $this->drop($batch, $first, 'the one resend failed', 'terminal');
                }
            }
        }

        if ($first instanceof IntemptApiException && $first->unreached) {
            if ($canKeep) {
                $this->logger->warning(sprintf(
                    '[intempt] server not reached; keeping %d event(s) for the next flush',
                    count($batch)
                ));
                $this->reportTransportOnce($batch);

                return self::KEPT;
            }

            return $this->drop($batch, $first, 'server not reached', 'dropped');
        }

        $answered = $first instanceof IntemptApiException && $first->status !== null;

        return $this->drop($batch, $first, 'not resent', $answered ? 'terminal' : 'dropped');
    }

    /**
     * Events the SDK itself discards: queue full, abandoned at close().
     *
     * @param list<array<string, mixed>> $batch
     */
    public function reportDropped(array $batch): void
    {
        if ($batch === []) {
            return;
        }
        $this->settled($batch, self::FAILED);
        $this->notify(new DeliveryFailure('dropped', null, count($batch), self::eventIds($batch), null, true));
    }

    /** @param list<array<string, mixed>> $batch */
    private function reportTransportOnce(array $batch): void
    {
        $ids = self::eventIds($batch);
        foreach ($ids as $id) {
            if (isset($this->transportReported[$id])) {
                return;
            }
        }
        foreach ($ids as $id) {
            $this->transportReported[$id] = true;
        }
        $this->notify(new DeliveryFailure('transport', null, count($batch), $ids, null, false));
    }

    /**
     * @param list<array<string, mixed>> $batch
     * @param self::SENT|self::FAILED    $outcome
     *
     * @return self::SENT|self::FAILED
     */
    private function settled(array $batch, string $outcome): string
    {
        foreach (self::eventIds($batch) as $id) {
            unset($this->transportReported[$id]);
        }

        return $outcome;
    }

    /**
     * @param list<array<string, mixed>> $batch
     *
     * @return list<string>
     */
    private static function eventIds(array $batch): array
    {
        $ids = [];
        foreach ($batch as $event) {
            $payload = $event['payload'] ?? null;
            if (!is_array($payload)) {
                continue;
            }
            foreach ($payload as $item) {
                $id = is_array($item) ? ($item['eventId'] ?? null) : null;
                if (is_string($id)) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    private function notify(DeliveryFailure $failure): void
    {
        if ($this->onFailure === null) {
            return;
        }
        try {
            ($this->onFailure)($failure);
        } catch (\Throwable $error) {
            $this->logger->error('[intempt] onDeliveryFailure listener threw; ignored', [
                'error' => $error->getMessage(),
            ]);
        }
    }

    private static function isResendable(\Throwable $error): bool
    {
        return $error instanceof IntemptApiException
            && ($error->status === 503 || $error->status === 429);
    }

    private static function waitMs(\Throwable $error): int
    {
        $advised = $error instanceof IntemptApiException ? $error->retryAfterMs : null;

        return min(self::MAX_WAIT_MS, max(self::MIN_WAIT_MS, $advised ?? self::MIN_WAIT_MS));
    }

    /**
     * @param list<array<string, mixed>> $batch
     * @param 'terminal'|'dropped'       $kind
     */
    private function drop(array $batch, \Throwable $error, string $why, string $kind): string
    {
        $apiError = $error instanceof IntemptApiException ? $error : null;
        $this->logger->error(
            sprintf('[intempt] delivery failed (%s); dropping %d event(s)', $why, count($batch)),
            [
                'status' => $apiError?->status,
                'body' => $apiError?->body,
                'error' => $error->getMessage(),
                'count' => count($batch),
            ]
        );
        $this->settled($batch, self::FAILED);
        $retryAfterMs = $apiError?->retryAfterMs;
        $this->notify(new DeliveryFailure(
            $kind,
            $apiError?->status,
            count($batch),
            self::eventIds($batch),
            $retryAfterMs !== null ? $retryAfterMs / 1000 : null,
            true,
        ));

        return self::FAILED;
    }
}
