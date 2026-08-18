<?php

/**
 * PHP's built-in web server, driven by a scripted reply queue on disk.
 *
 * A real socket rather than a mocked curl: this is the only way to prove header
 * framing, timeouts and connection handling actually work. State goes through
 * files because the server runs in its own process.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

final class FakeServer
{
    /** @var resource|null */
    private $process = null;

    private function __construct(
        private readonly int $port,
        private readonly string $stateDir,
    ) {
    }

    public static function start(): self
    {
        $port = self::freePort();
        $stateDir = sys_get_temp_dir() . '/intempt-test-' . getmypid() . '-' . $port;
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0o700, true);
        }

        $server = new self($port, $stateDir);
        $server->spawn();
        $server->reset();

        return $server;
    }

    public function host(): string
    {
        return '127.0.0.1:' . $this->port;
    }

    public function reset(): void
    {
        file_put_contents($this->stateDir . '/replies.json', '[]');
        file_put_contents($this->stateDir . '/requests.jsonl', '');
    }

    /** @param array<string, string> $headers */
    public function expect(
        int $status = 200,
        string $body = '{}',
        array $headers = [],
        int $delayMs = 0,
    ): void {
        $file = $this->stateDir . '/replies.json';
        $current = json_decode((string) @file_get_contents($file), true) ?: [];
        $current[] = [
            'status' => $status,
            'body' => $body,
            'headers' => $headers,
            'delayMs' => $delayMs,
        ];
        file_put_contents($file, json_encode($current));
    }

    public function expectMany(int $count, int $status = 200, string $body = '{}'): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->expect($status, $body);
        }
    }

    /**
     * @return list<array{method:string, path:string, headers:array<string,string>,
     *                    body:mixed, remotePort:int}>
     */
    public function requests(): array
    {
        $raw = trim((string) @file_get_contents($this->stateDir . '/requests.jsonl'));
        if ($raw === '') {
            return [];
        }

        $out = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            // 15 is SIGTERM. Written as a literal, not the SIGTERM constant,
            // because that constant is only defined when the pcntl extension
            // is loaded — and pcntl is neither an ext-* requirement of this
            // package nor installed in CI (extensions: curl, json in
            // .github/workflows/tests.yml), so referencing the constant
            // fataled with "Undefined constant" in every environment that
            // matches CI exactly.
            proc_terminate($this->process, 15);
            proc_close($this->process);
            $this->process = null;
        }
    }

    private function spawn(): void
    {
        $router = __DIR__ . '/server/router.php';
        // exec so proc_terminate signals php itself rather than a wrapping shell,
        // which would otherwise leave the server running after the tests finish.
        $command = sprintf(
            'exec php -S 127.0.0.1:%d %s',
            $this->port,
            escapeshellarg($router)
        );

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $env = ['INTEMPT_TEST_STATE' => $this->stateDir] + getenv();

        $process = proc_open($command, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the test server');
        }
        $this->process = $process;
        $this->waitUntilReady();
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);

                return;
            }
            usleep(50_000);
        }

        throw new \RuntimeException('test server did not become ready on port ' . $this->port);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('could not find a free port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
