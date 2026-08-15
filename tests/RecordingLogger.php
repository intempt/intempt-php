<?php

/**
 * Log recorder, in its own file because PSR-4 maps one class to one file.
 *
 * It previously sat inside TestCase.php and resolved only because every test
 * that used it also extended TestCase, which pulled the file in as a side
 * effect. A test class that needs the logger without the harness — ConfigTest —
 * got "Class Intempt\\Tests\\RecordingLogger not found".
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

namespace Intempt\Tests;

use Intempt\Logger;

/** Captures log calls so tests can assert on what an operator would see. */
final class RecordingLogger implements Logger
{
    /** @var array<string, list<string>> */
    public array $calls = ['debug' => [], 'info' => [], 'warning' => [], 'error' => []];

    /** @var array<string, list<array<string, mixed>>> */
    public array $context = ['debug' => [], 'info' => [], 'warning' => [], 'error' => []];

    public function debug(string $message, array $context = []): void
    {
        $this->record('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->record('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->record('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->record('error', $message, $context);
    }

    public function has(string $level, string $needle): bool
    {
        foreach ($this->calls[$level] as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function matching(string $level, string $needle): array
    {
        return array_values(array_filter(
            $this->calls[$level],
            static fn (string $line) => str_contains($line, $needle)
        ));
    }

    /** @param array<string, mixed> $context */
    private function record(string $level, string $message, array $context): void
    {
        $this->calls[$level][] = $message;
        $this->context[$level][] = $context;
    }
}
