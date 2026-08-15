<?php

/**
 * HTTP transport: one curl handle per client, credentials never logged.
 *
 * Portions derived from mixpanel-php (Apache License 2.0), as recorded in
 * NOTICE: the curl consumer and the response error mapping follow its
 * CurlConsumer. Changed to add a per-request timeout, to distinguish retryable
 * from non-retryable statuses, and to keep the credential out of every error
 * surface.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt;

final class Transport
{
    private \CurlHandle|null $handle = null;
    private bool $closed = false;

    public function __construct(
        private Config $config,
        private readonly ApiKeyCredentials $credentials,
    ) {
    }

    public function setConfig(Config $config): void
    {
        $reconnect = $config->host !== $this->config->host
            || $config->port !== $this->config->port
            || $config->scheme !== $this->config->scheme;
        $this->config = $config;
        if ($reconnect) {
            $this->dropHandle();
        }
    }

    /**
     * Seconds or an HTTP-date, in milliseconds. Null when unusable.
     *
     * A negative or unparseable value yields null rather than a negative wait,
     * which would mean no wait at all.
     */
    public static function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (is_numeric($value)) {
            $seconds = (float) $value;
            if (!is_finite($seconds) || $seconds < 0) {
                return null;
            }

            return (int) ($seconds * 1000);
        }
        $parsed = strtotime($value);
        if ($parsed === false) {
            return null;
        }

        return max(0, (int) (($parsed - time()) * 1000));
    }

    /**
     * POST JSON and return the decoded body.
     *
     * @throws IntemptApiException
     */
    public function post(string $path, mixed $body): mixed
    {
        if ($this->closed) {
            throw new IntemptApiException('client is closed');
        }

        $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $url = $this->config->baseUrl() . $path;

        if ($this->config->debug) {
            $this->config->logger()->debug('[intempt] POST ' . $path);
        }

        $handle = $this->handle();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT_MS => (int) ($this->config->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->config->timeout * 1000),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
                'Authorization: ' . $this->credentials->authorizationHeader(),
                'X-Intempt-Lib: intempt-php/' . Intempt::VERSION,
                'Expect:',
            ],
        ]);

        $raw = curl_exec($handle);
        if ($raw === false) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            $this->dropHandle();
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new IntemptApiException(
                    sprintf('request timed out after %.1fs', $this->config->timeout)
                );
            }

            throw new IntemptApiException($error !== '' ? $error : 'transport failure');
        }

        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headers = substr((string) $raw, 0, $headerSize);
        $bodyText = substr((string) $raw, $headerSize);

        // No drop for the keep-alive-off case: handle() never stored the handle
        // in the first place, so there is nothing here to release. `keepAlive`
        // is fixed at construction — merge() refuses it and carries it forward
        // unchanged — so the two can never disagree.

        if ($status < 200 || $status >= 300) {
            throw new IntemptApiException(
                sprintf('Intempt API responded %d', $status),
                status: $status,
                body: $bodyText,
                retryAfterMs: self::parseRetryAfter(self::headerValue($headers, 'retry-after')),
            );
        }

        if ($bodyText === '') {
            return null;
        }
        try {
            return json_decode($bodyText, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // A gateway can answer 200 with an HTML error page. That is a
            // successful exchange carrying a body we cannot parse, not a
            // failure to report.
            return $bodyText;
        }
    }

    public function close(): void
    {
        $this->closed = true;
        $this->dropHandle();
    }

    private function handle(): \CurlHandle
    {
        if ($this->handle !== null && $this->config->keepAlive) {
            return $this->handle;
        }
        $handle = curl_init();
        if ($handle === false) {
            throw new IntemptApiException('could not initialise curl');
        }
        if ($this->config->keepAlive) {
            $this->handle = $handle;
        }

        return $handle;
    }

    private function dropHandle(): void
    {
        // No curl_close(): it has been a no-op since PHP 8.0, where CurlHandle
        // became a proper object freed by refcount, and it is deprecated as of
        // 8.5. Dropping the reference is what actually closes the connection.
        $this->handle = null;
    }

    private static function headerValue(string $headers, string $name): ?string
    {
        foreach (explode("\r\n", $headers) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2 && strtolower(trim($parts[0])) === $name) {
                return trim($parts[1]);
            }
        }

        return null;
    }
}
