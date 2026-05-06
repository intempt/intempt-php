<?php
// Minimal HTTP server that captures and logs all incoming POST requests.
// Run with: php -S 127.0.0.1:9876 tests/integration/server.php

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['REQUEST_URI'];
$body = file_get_contents('php://input');

$logFile = __DIR__ . '/requests.log';

$entry = json_encode([
    'method' => $method,
    'path' => $path,
    'body' => json_decode($body, true),
    'headers' => [
        'content-type' => $_SERVER['HTTP_CONTENT_TYPE'] ?? null,
    ],
], JSON_PRETTY_PRINT) . "\n---\n";

file_put_contents($logFile, $entry, FILE_APPEND);

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'choices' => [['name' => 'test-exp', 'variant' => 'A']], 'items' => [['id' => '1', 'title' => 'Widget']]]);
