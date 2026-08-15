<?php

/**
 * Configuration resolution.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt;

final class Config
{
    public const DEFAULT_HOST = 'api.intempt.com';

    /** Options fixed at construction because the curl handle is built once. */
    private const FIXED = ['org', 'project', 'apiKey', 'sourceId', 'batch', 'keepAlive'];

    public function __construct(
        public readonly string $org,
        public readonly string $project,
        /**
         * The credential, never the raw key.
         *
         * This used to be `public readonly string $apiKey`, which meant
         * print_r() or var_dump() of a Config — or of a client holding one —
         * printed the secret in full. Holding only the encoded form removes the
         * one leak the SDK actually controls.
         */
        public readonly ApiKeyCredentials $credentials,
        public readonly string $host = self::DEFAULT_HOST,
        public readonly ?int $port = null,
        public readonly string $scheme = 'https',
        public readonly string $path = '',
        public readonly float $timeout = 10.0,
        public readonly bool $keepAlive = true,
        public readonly bool $debug = false,
        public readonly ?string $sourceId = null,
        public readonly ?BatchOptions $batch = null,
        public readonly int $maxRequestEvents = 50,
        public readonly ?Logger $logger = null,
    ) {
    }

    public function projectPath(string $suffix): string
    {
        return $this->path
            . '/v1/' . rawurlencode($this->org)
            . '/projects/' . rawurlencode($this->project)
            . $suffix;
    }

    public function baseUrl(): string
    {
        $port = $this->port !== null ? ':' . $this->port : '';

        return $this->scheme . '://' . $this->host . $port;
    }

    public function logger(): Logger
    {
        return $this->logger ?? new NullLogger();
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function resolve(array $options): self
    {
        foreach (['org', 'project', 'apiKey'] as $name) {
            $value = $options[$name] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new IntemptConfigException(sprintf('Intempt: "%s" is required', $name));
            }
        }

        $sourceId = $options['sourceId'] ?? null;
        if ($sourceId !== null) {
            // (string), never (int). A 19-digit snowflake exceeds PHP's float
            // precision on 32-bit builds and a numeric round trip silently
            // addresses a different source.
            $sourceId = (string) $sourceId;
            if (trim($sourceId) === '') {
                throw new IntemptConfigException(
                    'Intempt: "sourceId" must not be empty when provided'
                );
            }
        }

        // Null means "not supplied, use the default". An explicit empty string
        // is a mistake and is refused rather than quietly becoming the default.
        $rawHost = $options['host'] ?? null;
        [$host, $port] = self::splitHost($rawHost ?? self::DEFAULT_HOST);

        $scheme = $options['scheme'] ?? 'https';
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new IntemptConfigException(sprintf('unsupported scheme "%s"', (string) $scheme));
        }

        $timeout = $options['timeout'] ?? 10.0;
        if (!is_int($timeout) && !is_float($timeout)) {
            throw new IntemptConfigException('timeout must be a positive number of seconds');
        }
        if ($timeout <= 0) {
            throw new IntemptConfigException('timeout must be a positive number of seconds');
        }

        $maxRequestEvents = $options['maxRequestEvents'] ?? 50;
        if (!is_int($maxRequestEvents) || $maxRequestEvents < 1) {
            throw new IntemptConfigException('maxRequestEvents must be a positive integer');
        }

        $batch = $options['batch'] ?? null;
        if ($batch !== null && !$batch instanceof BatchOptions) {
            if (!is_array($batch)) {
                throw new IntemptConfigException('batch must be a BatchOptions or an array');
            }
            $batch = new BatchOptions(
                size: $batch['size'] ?? 50,
                flushMs: $batch['flushMs'] ?? 5_000,
                maxQueue: $batch['maxQueue'] ?? 10_000,
                flushOnExit: $batch['flushOnExit'] ?? true,
            );
        }

        $logger = $options['logger'] ?? null;
        if ($logger !== null && !$logger instanceof Logger) {
            throw new IntemptConfigException('logger must implement Intempt\\Logger');
        }

        return new self(
            org: $options['org'],
            project: $options['project'],
            credentials: new ApiKeyCredentials($options['apiKey']),
            host: $host,
            port: $port,
            scheme: $scheme,
            path: $options['path'] ?? '',
            timeout: (float) $timeout,
            keepAlive: (bool) ($options['keepAlive'] ?? true),
            debug: (bool) ($options['debug'] ?? false),
            sourceId: $sourceId,
            batch: $batch,
            maxRequestEvents: $maxRequestEvents,
            logger: $logger,
        );
    }

    /**
     * Apply a patch to a live client's config.
     *
     * The fixed options are refused rather than ignored. Accepting them silently
     * left a caller believing they had changed something that never moved.
     *
     * @param array<string, mixed> $patch
     */
    public function merge(array $patch): self
    {
        foreach (self::FIXED as $name) {
            if (array_key_exists($name, $patch)) {
                throw new IntemptConfigException(sprintf(
                    'setConfig: "%s" is fixed at construction because the connection is '
                    . 'built once. Pass it to the constructor instead.',
                    $name
                ));
            }
        }

        $host = $this->host;
        $port = $this->port;
        if (array_key_exists('host', $patch)) {
            // A new host with no port must clear the old one, or the next
            // request goes to new-host:old-port, a pair nobody asked for.
            [$host, $port] = self::splitHost($patch['host']);
        }

        $scheme = $patch['scheme'] ?? $this->scheme;
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new IntemptConfigException(sprintf('unsupported scheme "%s"', (string) $scheme));
        }

        $timeout = $patch['timeout'] ?? $this->timeout;
        if ((!is_int($timeout) && !is_float($timeout)) || $timeout <= 0) {
            throw new IntemptConfigException('timeout must be a positive number of seconds');
        }

        return new self(
            org: $this->org,
            project: $this->project,
            credentials: $this->credentials,
            host: $host,
            port: $port,
            scheme: $scheme,
            path: $patch['path'] ?? $this->path,
            timeout: (float) $timeout,
            keepAlive: $this->keepAlive,
            debug: (bool) ($patch['debug'] ?? $this->debug),
            sourceId: $this->sourceId,
            batch: $this->batch,
            maxRequestEvents: $patch['maxRequestEvents'] ?? $this->maxRequestEvents,
            logger: $patch['logger'] ?? $this->logger,
        );
    }

    /**
     * Accept `host` or `host:port`.
     *
     * The port is validated rather than assumed: an unparseable or zero port
     * would otherwise reach curl and fail with something unrecognisable.
     *
     * @return array{0: string, 1: int|null}
     */
    private static function splitHost(mixed $host): array
    {
        if (!is_string($host) || trim($host) === '') {
            throw new IntemptConfigException('host must not be empty');
        }
        $host = trim($host);
        $parts = explode(':', $host, 2);
        $hostname = $parts[0];
        if ($hostname === '') {
            throw new IntemptConfigException('host must not be empty');
        }
        if (!isset($parts[1]) || $parts[1] === '') {
            return [$hostname, null];
        }
        if (!ctype_digit($parts[1])) {
            throw new IntemptConfigException(sprintf('invalid port in host: %s', $host));
        }
        $port = (int) $parts[1];
        if ($port <= 0 || $port > 65535) {
            throw new IntemptConfigException(sprintf('invalid port in host: %s', $host));
        }

        return [$hostname, $port];
    }
}
