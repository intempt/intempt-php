<?php

/**
 * Router for PHP's built-in server, used as the test double.
 *
 * State lives in two files under INTEMPT_TEST_STATE: `replies.json` is a queue
 * the test writes, `requests.jsonl` is what this appends. Files rather than
 * shared memory because the server runs in a separate process, and JSON-lines
 * so a partially written file still parses line by line.
 *
 * Copyright 2026 Intempt Technologies
 * Licensed under the Apache License, Version 2.0.
 */

declare(strict_types=1);

$dir = getenv('INTEMPT_TEST_STATE') ?: sys_get_temp_dir();
$repliesFile = $dir . '/replies.json';
$requestsFile = $dir . '/requests.jsonl';
$lockFile = $dir . '/lock';

$raw = file_get_contents('php://input') ?: '';

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
    }
}
foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $from => $to) {
    if (isset($_SERVER[$from])) {
        $headers[$to] = $_SERVER[$from];
    }
}

// One lock around read-modify-write of both files: the built-in server handles
// requests serially by default, but a concurrency test must not corrupt state.
$lock = fopen($lockFile, 'c');
if ($lock !== false) {
    flock($lock, LOCK_EX);
}

file_put_contents($requestsFile, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $_SERVER['REQUEST_URI'] ?? '',
    'headers' => $headers,
    'body' => json_decode($raw, true) ?? $raw,
    'remotePort' => (int) ($_SERVER['REMOTE_PORT'] ?? 0),
]) . "\n", FILE_APPEND);

$replies = is_file($repliesFile)
    ? (json_decode((string) file_get_contents($repliesFile), true) ?: [])
    : [];
$reply = array_shift($replies);
file_put_contents($repliesFile, json_encode(array_values($replies)));

if ($lock !== false) {
    flock($lock, LOCK_UN);
    fclose($lock);
}

$reply ??= ['status' => 200, 'body' => '{}', 'headers' => [], 'delayMs' => 0];

if ((int) ($reply['delayMs'] ?? 0) > 0) {
    usleep((int) $reply['delayMs'] * 1000);
}

http_response_code((int) ($reply['status'] ?? 200));
header('Content-Type: application/json');
foreach ((array) ($reply['headers'] ?? []) as $key => $value) {
    header($key . ': ' . $value);
}
echo (string) ($reply['body'] ?? '{}');
