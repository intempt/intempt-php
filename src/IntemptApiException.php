<?php

/**
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt;

/**
 * The API answered, or the transport failed.
 *
 * A null status means a transport failure or a timeout, which is why
 * isRetryable() treats it as retryable: nothing came back to say the request was
 * rejected on its merits.
 */
class IntemptApiException extends IntemptException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $body = null,
        public readonly ?int $retryAfterMs = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** True for statuses worth retrying: 408, 429 and any 5xx. */
    public function isRetryable(): bool
    {
        if ($this->status === null) {
            return true;
        }

        return $this->status === 408 || $this->status === 429 || $this->status >= 500;
    }
}
