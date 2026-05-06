<?php
/**
 * Real integration test — uses the SDK to send actual HTTP requests
 * to a local server. No mocks, no stubs, real wire traffic.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Intempt\Client;
use Intempt\IntemptException;

$logFile = __DIR__ . '/requests.log';
if (file_exists($logFile)) unlink($logFile);

$passed = 0;
$failed = 0;

function assert_true(bool $condition, string $label): void {
    global $passed, $failed;
    if ($condition) {
        echo "  ✔ {$label}\n";
        $passed++;
    } else {
        echo "  ✘ FAIL: {$label}\n";
        $failed++;
    }
}

function read_requests(): array {
    global $logFile;
    if (!file_exists($logFile)) return [];
    $raw = file_get_contents($logFile);
    $entries = array_filter(explode("\n---\n", $raw), fn($e) => trim($e) !== '');
    return array_map(fn($e) => json_decode(trim($e), true), $entries);
}

function clear_log(): void {
    global $logFile;
    if (file_exists($logFile)) unlink($logFile);
}

// ── Create client pointing at local server ──

$client = new Client([
    'orgName' => 'test-org',
    'projectName' => 'test-project',
    'apiKey' => 'abc123.xyz789',
    'sourceId' => 'src_001',
    'baseUrl' => 'http://127.0.0.1:9876',
    'batchSize' => 50, // high threshold so we control when flush happens
]);

echo "\n=== Intempt PHP SDK — Integration Tests ===\n\n";

// ── Test 1: track() + flush() ──
echo "1. track() + flush()\n";
clear_log();

$client->track('prof_100', 'purchase', ['amount' => 99.99, 'currency' => 'USD']);
$client->track('prof_100', 'page_view', ['url' => '/checkout']);
$client->flush();

$reqs = read_requests();
assert_true(count($reqs) === 1, 'Single HTTP request sent');
assert_true($reqs[0]['method'] === 'POST', 'Method is POST');
assert_true(str_contains($reqs[0]['path'], '/v1/test-org/projects/test-project/sources/src_001/track'), 'URL path correct');
assert_true(str_contains($reqs[0]['path'], 'apiKey=abc123.xyz789'), 'API key in query string');

$body = $reqs[0]['body'];
assert_true(isset($body['track']), 'Body has track array');
assert_true(count($body['track']) === 2, '2 events in batch');
assert_true($body['track'][0]['name'] === 'purchase', 'First event is purchase');
assert_true($body['track'][1]['name'] === 'page_view', 'Second event is page_view');

$payload = $body['track'][0]['payload'][0];
assert_true($payload['profileId'] === 'prof_100', 'profileId correct');
assert_true($payload['data']['amount'] === 99.99, 'data.amount correct');
assert_true($payload['data']['currency'] === 'USD', 'data.currency correct');
assert_true(isset($payload['eventId']), 'eventId generated');
assert_true(isset($payload['timestamp']), 'timestamp generated');
assert_true(is_int($payload['timestamp']), 'timestamp is integer (ms)');

echo "\n";

// ── Test 2: identify() ──
echo "2. identify()\n";
clear_log();

$client->identify('prof_200', 'john@example.com');
$client->identify('prof_200', 'john@example.com', 'signup', ['plan' => 'enterprise']);
$client->flush();

$reqs = read_requests();
$body = $reqs[0]['body'];
assert_true($body['track'][0]['name'] === 'Identify', 'Default event name is Identify');
assert_true($body['track'][0]['payload'][0]['userId'] === 'john@example.com', 'userId set');
assert_true(!isset($body['track'][0]['payload'][0]['userAttributes']), 'No userAttributes when null');

assert_true($body['track'][1]['name'] === 'signup', 'Custom event title works');
assert_true($body['track'][1]['payload'][0]['userAttributes']['plan'] === 'enterprise', 'userAttributes passed');

echo "\n";

// ── Test 3: group() ──
echo "3. group()\n";
clear_log();

$client->group('prof_300', 'acc_acme');
$client->group('prof_300', 'acc_acme', 'join-workspace', ['domain' => 'acme.com', 'seats' => 50]);
$client->flush();

$reqs = read_requests();
$body = $reqs[0]['body'];
assert_true($body['track'][0]['name'] === 'Identify', 'Default group event name');
assert_true($body['track'][0]['payload'][0]['accountId'] === 'acc_acme', 'accountId set');
assert_true($body['track'][1]['payload'][0]['accountAttributes']['domain'] === 'acme.com', 'accountAttributes passed');
assert_true($body['track'][1]['payload'][0]['accountAttributes']['seats'] === 50, 'Numeric attributes preserved');

echo "\n";

// ── Test 4: record() ──
echo "4. record()\n";
clear_log();

$client->record('prof_400', 'battle', 'john-snow', 'stark',
    ['location' => 'winterfell', 'outcome' => 'victory'],
    ['kills' => 74],
    ['army_size' => 5000]);
$client->flush();

$reqs = read_requests();
$p = $reqs[0]['body']['track'][0]['payload'][0];
assert_true($p['profileId'] === 'prof_400', 'record profileId');
assert_true($p['userId'] === 'john-snow', 'record userId');
assert_true($p['accountId'] === 'stark', 'record accountId');
assert_true($p['data']['location'] === 'winterfell', 'record data');
assert_true($p['userAttributes']['kills'] === 74, 'record userAttributes');
assert_true($p['accountAttributes']['army_size'] === 5000, 'record accountAttributes');

echo "\n";

// ── Test 5: alias() ──
echo "5. alias()\n";
clear_log();

$client->alias('prof_500', 'john@example.com', 'aegon@example.com');
$client->flush();

$reqs = read_requests();
$p = $reqs[0]['body']['track'][0]['payload'][0];
assert_true($reqs[0]['body']['track'][0]['name'] === 'Identify', 'alias uses Identify event');
assert_true($p['userId'] === 'john@example.com', 'alias userId');
assert_true($p['anotherUserId'] === 'aegon@example.com', 'alias anotherUserId');

echo "\n";

// ── Test 6: consent() — sends immediately, not batched ──
echo "6. consent()\n";
clear_log();

$client->consent('prof_600', 'accept', 'marketing', '2025-12-31', 'john@example.com', 'Cookie banner');
$client->consent('prof_600', 'reject');

$reqs = read_requests();
assert_true(count($reqs) === 2, 'Consent sends immediately (2 separate requests)');
assert_true(str_contains($reqs[0]['path'], '/consents/data'), 'Consent endpoint path');

$c1 = $reqs[0]['body'];
assert_true($c1['action'] === 'accept', 'consent action=accept');
assert_true($c1['category'] === 'marketing', 'consent category');
assert_true($c1['profileId'] === 'prof_600', 'consent profileId');
assert_true($c1['sourceId'] === 'src_001', 'consent sourceId');
assert_true($c1['validUntil'] === '2025-12-31', 'consent validUntil');
assert_true($c1['source'] === 'PHP tracker', 'consent source identifier');
assert_true($c1['email'] === 'john@example.com', 'consent email');
assert_true($c1['message'] === 'Cookie banner', 'consent message');

$c2 = $reqs[1]['body'];
assert_true($c2['action'] === 'reject', 'second consent action=reject');
assert_true($c2['validUntil'] === 'unlimited', 'default validUntil is unlimited');

echo "\n";

// ── Test 7: productAdd / productView / productOrdered ──
echo "7. Product events\n";
clear_log();

$r1 = $client->productAdd('prof_700', 'sku_laptop', 2);
$r2 = $client->productView('prof_700', 'sku_laptop');
$r3 = $client->productOrdered('prof_700', [
    ['productId' => 'sku_laptop', 'quantity' => 1],
    ['productId' => 'sku_mouse', 'quantity' => 3],
]);
$client->flush();

assert_true($r1 === null, 'productAdd returns null on success');
assert_true($r2 === null, 'productView returns null on success');
assert_true($r3 === null, 'productOrdered returns null on success');

$body = read_requests()[0]['body'];
assert_true($body['track'][0]['name'] === 'Added to cart', 'productAdd event name');
assert_true($body['track'][0]['payload'][0]['data']['productId'] === 'sku_laptop', 'productAdd productId');
assert_true($body['track'][0]['payload'][0]['data']['quantity'] === 2, 'productAdd quantity');

assert_true($body['track'][1]['name'] === 'Product viewed', 'productView event name');
assert_true($body['track'][2]['name'] === 'Product ordered', 'productOrdered event name');
assert_true(count($body['track'][2]['payload']) === 2, 'productOrdered has 2 items');
assert_true($body['track'][2]['payload'][1]['data']['productId'] === 'sku_mouse', 'second product correct');

echo "\n";

// ── Test 8: Product validation ──
echo "8. Product validation\n";

assert_true($client->productAdd('prof_700', 'sku', 0) === ['error' => true], 'productAdd rejects quantity=0');
assert_true($client->productAdd('prof_700', 'sku', -1) === ['error' => true], 'productAdd rejects negative quantity');
assert_true($client->productAdd('', 'sku', 1) === ['error' => true], 'productAdd rejects empty profileId');
assert_true($client->productView('', 'sku') === ['error' => true], 'productView rejects empty profileId');
assert_true($client->productOrdered('prof_700', []) === ['error' => true], 'productOrdered rejects empty array');
assert_true($client->productOrdered('prof_700', [['productId' => '', 'quantity' => 1]]) === ['error' => true], 'productOrdered rejects empty productId');

echo "\n";

// ── Test 9: recommendation() ──
echo "9. recommendation()\n";
clear_log();

$result = $client->recommendation('prof_800', '848', 5, ['id', 'title', 'price'], 'sku_laptop');

$reqs = read_requests();
assert_true(str_contains($reqs[0]['path'], '/feeds/848/data'), 'recommendation endpoint');
$b = $reqs[0]['body'];
assert_true($b['profileId'] === 'prof_800', 'recommendation profileId');
assert_true($b['sourceId'] === 'src_001', 'recommendation sourceId');
assert_true($b['limit'] === 5, 'recommendation limit');
assert_true($b['fields'] === ['id', 'title', 'price'], 'recommendation fields');
assert_true($b['productId'] === 'sku_laptop', 'recommendation productId filter');
assert_true(isset($result['items']), 'recommendation returns response data');

echo "\n";

// ── Test 10: experiments/personalizations ──
echo "10. Optimization\n";
clear_log();

$exp = $client->chooseExperimentsByGroups('prof_900', ['homepage', 'checkout']);
$reqs = read_requests();
$b = $reqs[0]['body'];
assert_true(str_contains($reqs[0]['path'], '/optimization/choose-api'), 'optimization endpoint');
assert_true($b['identification']['profileId'] === 'prof_900', 'optimization profileId');
assert_true($b['identification']['sourceId'] === 'src_001', 'optimization sourceId');
assert_true($b['groups'] === ['homepage', 'checkout'], 'optimization groups');
assert_true($b['optimizationType'] === 'experiment', 'optimization type = experiment');
assert_true($b['device'] === 'all', 'optimization device = all');
assert_true(is_array($exp), 'returns choices array');

clear_log();
$client->choosePersonalizationsByNames('prof_900', ['welcome-banner']);
$b2 = read_requests()[0]['body'];
assert_true($b2['names'] === ['welcome-banner'], 'personalization names');
assert_true($b2['optimizationType'] === 'personalization', 'type = personalization');

echo "\n";

// ── Test 11: optOut / optIn ──
echo "11. optOut / optIn\n";
clear_log();

$client->optOut();
assert_true(!$client->isOptedIn(), 'isOptedIn returns false after optOut');

$client->track('prof_X', 'should-not-send', ['x' => 1]);
$client->identify('prof_X', 'user@x.com');
$client->group('prof_X', 'acc_X');
$client->record('prof_X', 'event');
$client->alias('prof_X', 'a', 'b');
$client->productAdd('prof_X', 'sku', 1);
$client->flush();

$reqs = read_requests();
assert_true(count($reqs) === 0, 'No HTTP requests when opted out');
assert_true(count($client->getPendingEvents()) === 0, 'No events queued when opted out');

$client->optIn();
assert_true($client->isOptedIn(), 'isOptedIn returns true after optIn');
$client->track('prof_X', 'back-online', ['y' => 2]);
assert_true(count($client->getPendingEvents()) === 1, 'Events queue again after optIn');

// flush before next test
clear_log();
$client->flush();

echo "\n";

// ── Test 12: auto-flush at batchSize ──
echo "12. Auto-flush at batchSize\n";
clear_log();

$smallBatch = new Client([
    'orgName' => 'test-org',
    'projectName' => 'test-project',
    'apiKey' => 'abc123.xyz789',
    'sourceId' => 'src_001',
    'baseUrl' => 'http://127.0.0.1:9876',
    'batchSize' => 3,
]);

$smallBatch->track('prof_B', 'e1', ['i' => 1]);
$smallBatch->track('prof_B', 'e2', ['i' => 2]);
assert_true(count(read_requests()) === 0, 'No flush before threshold');

$smallBatch->track('prof_B', 'e3', ['i' => 3]);
$reqs = read_requests();
assert_true(count($reqs) === 1, 'Auto-flushed at batchSize=3');
assert_true(count($reqs[0]['body']['track']) === 3, '3 events in auto-flush batch');

// prevent destructor from sending another flush
clear_log();

echo "\n";

// ── Test 13: reserved event name rejection ──
echo "13. Reserved event name\n";

$client->track('prof_R', 'Identify', ['x' => 1]);
assert_true(count($client->getPendingEvents()) === 0, 'track rejects Identify');

$client->identify('prof_R', 'user', 'Identify');
assert_true(count($client->getPendingEvents()) === 0, 'identify rejects Identify as custom title');

echo "\n";

// ── Test 14: error handling ──
echo "14. Error handling — bad server response\n";

$badClient = new Client([
    'orgName' => 'test-org',
    'projectName' => 'test-project',
    'apiKey' => 'abc123.xyz789',
    'sourceId' => 'src_001',
    'baseUrl' => 'http://127.0.0.1:1', // nothing listening
    'maxRetries' => 1,
    'timeout' => 1,
]);

$badClient->track('prof_E', 'test', []);
$threw = false;
try {
    $badClient->flush();
} catch (IntemptException $e) {
    $threw = true;
}
assert_true($threw, 'Throws IntemptException on network failure');

echo "\n";

// ── Summary ──
$total = $passed + $failed;
echo "=== Results: {$passed}/{$total} passed";
if ($failed > 0) {
    echo ", {$failed} FAILED";
}
echo " ===\n\n";

exit($failed > 0 ? 1 : 0);
