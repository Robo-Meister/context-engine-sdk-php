<?php

declare(strict_types=1);

// Opt-in test against an isolated CE + Redis instance. It creates records and stream entries.
require_once __DIR__ . '/../ContextEngineClient.php';
require_once __DIR__ . '/../ContextEngineRedisClient.php';
require_once __DIR__ . '/assertions.php';

use ContextEngine\ContextEngineClient;
use ContextEngine\ContextEngineRedisClient;

if (getenv('CE_TEST_ISOLATED') !== '1' || !getenv('CE_TEST_BASE_URL') || !getenv('CE_TEST_REDIS_PORT')) {
    throw new RuntimeException('Set CE_TEST_ISOLATED=1, CE_TEST_BASE_URL and CE_TEST_REDIS_PORT for a disposable local CE instance');
}
$url = getenv('CE_TEST_BASE_URL');
if (!in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Smoke test only supports a local disposable instance');
}
$client = new ContextEngineClient($url, timeout: 3, enforceHttps: false);
$redis = new Redis();
$redis->connect('127.0.0.1', (int) getenv('CE_TEST_REDIS_PORT'));
$id = 'sdk-' . bin2hex(random_bytes(6));
$scopes = [['scopeType' => 'session', 'scopeId' => $id], ['scopeType' => 'organisation', 'scopeId' => $id]];
$keys = ['choice', 'fallback', 'short', 'zero', 'flag', 'nullable', 'nested', 'bridge', 'ticket', 'context_id', 'display_name', 'object_type', 'name', 'event_flag'];

function eventually(callable $check, string $label): mixed
{
    $deadline = microtime(true) + 8;
    do {
        $result = $check();
        if ($result !== false) return $result;
        usleep(50_000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Timed out: ' . $label);
}

try {
    $client->setDefaultContext('organisation', $id, ['choice' => 'organisation', 'fallback' => 'org-default']);
    $client->setDefaultContext('session', $id, ['choice' => 'session-default', 'short' => 'default']);
    $client->setActiveContext('session', $id, ['choice' => 'cache'], 'CACHE', 60);
    $client->setActiveContext('session', $id, ['choice' => 'state', 'flag' => false, 'zero' => 0, 'nullable' => null, 'nested' => ['items' => [1, 2]]], 'STATE', 60, 'sdk-user');
    $resolved = $client->resolve(['choice', 'fallback', 'flag', 'zero', 'nullable', 'nested', 'missing'], $scopes);
    same('state', $resolved['values']['choice'], 'STATE precedes CACHE and default');
    same('org-default', $resolved['values']['fallback'], 'Later scope fallback');
    same(false, $resolved['values']['flag'], 'Boolean survives real server');
    same(0, $resolved['values']['zero'], 'Zero survives real server');
    same(true, array_key_exists('nullable', $resolved['values']), 'Null record is present');
    same(null, $resolved['values']['nullable'], 'Null survives real server');
    same(['items' => [1, 2]], $resolved['values']['nested'], 'Nested value survives real server');
    same(['missing'], $resolved['missingKeys'], 'Real missing keys');
    $provenance = $resolved['provenance']['choice'];
    same($scopes[0], $provenance['scopeRef'], 'Real scope provenance');
    same('active', $provenance['store'], 'Real store');
    same('STATE', $provenance['kind'], 'Real kind');
    same('sdk-user', $provenance['updatedBy'], 'Real principal metadata');
    same(60, $provenance['ttlSeconds'], 'TTL is seconds');
    same(60000, $provenance['expiresAt'] - $provenance['updatedAt'], 'Timestamps are epoch milliseconds');
    $direct = $client->getScopedContext('session', $id, ['choice'], 'active', 'STATE');
    same($provenance, $direct['metadata']['choice'], 'Get and resolve metadata agree');
    $client->setActiveContext('session', $id, ['choice' => 'state2'], ttl: 60);
    same($provenance['version'] + 1, $client->resolve(['choice'], $scopes)['provenance']['choice']['version'], 'Existing record version increments');
    $client->unsetContext('session', $id, ['choice'], 'active', 'STATE');
    same('cache', $client->resolve(['choice'], $scopes)['values']['choice'], 'Unset STATE falls back to CACHE');
    $client->unsetContext('session', $id, ['choice']);
    same('session-default', $client->resolve(['choice'], $scopes)['values']['choice'], 'Unset active falls back to same scope default');
    same('organisation', $client->resolve(['choice'], array_reverse($scopes))['values']['choice'], 'Caller scope order is authoritative');
    $client->setActiveContext('session', $id, ['short' => 'temporary'], ttl: 1);
    eventually(fn() => $client->resolve(['short'], $scopes)['values']['short'] === 'default', 'TTL expiry reveals default');

    $bridge = new ContextEngineRedisClient($redis, responseTimeout: 8);
    $reply = $bridge->sendRequest(['method' => 'PATCH', 'path' => '/scopes/session/' . $id . '/active',
        'body' => ['values' => ['bridge' => 'through-redis'], 'kind' => 'STATE', 'ttl' => 60]], true);
    same(200, $reply['status'], 'Redis bridge accepts PATCH');
    same(['status' => 'ok'], json_decode($reply['body'], true, 512, JSON_THROW_ON_ERROR), 'Bridge write acknowledgement');
    $responseTtl = $redis->ttl('context_engine:response:' . $reply['request_id']);
    same(true, $responseTtl > 0 && $responseTtl <= 300, 'Server reply has bounded TTL');
    $http = $client->resolve(['bridge'], $scopes);
    $reply = $bridge->sendRequest(['method' => 'POST', 'path' => '/resolve', 'body' => ['keys' => ['bridge'], 'scopeRefs' => $scopes]], true);
    same($http, json_decode($reply['body'], true, 512, JSON_THROW_ON_ERROR), 'HTTP and Redis request/reply read the same canonical record');

    $event = ['event' => 'entity.update', 'event_id' => $id . '-event', 'source' => 'sdk-smoke',
        'timestamp' => gmdate('c'), 'version' => 1, 'context_id' => $id . '-ticket', 'object_type' => 'ticket',
        'scopeRefs' => [$scopes[0]], 'principal' => ['id' => 'sdk-user'], 'ttl' => 60,
        'context' => ['name' => 'Ticket preview', 'event_flag' => false]];
    $payload = json_encode($event, JSON_THROW_ON_ERROR);
    $entryId = $redis->xAdd('ce:events', '*', ['payload' => $payload]);
    same(true, is_string($entryId), 'Event appended as a stream entry');
    $fromEvent = eventually(function () use ($client, $scopes) {
        $read = $client->resolve(['ticket', 'event_flag'], $scopes);
        return isset($read['values']['ticket']) ? $read : false;
    }, 'Stream projection visible through SDK resolve');
    same('Ticket preview', $fromEvent['values']['ticket']['display_name'], 'Event preview available');
    same(false, $fromEvent['values']['event_flag'], 'Event boolean retained');
    same('sdk-user', $fromEvent['provenance']['ticket']['updatedBy'], 'Event principal metadata');
    $replayId = $redis->xAdd('ce:events', '*', ['payload' => $payload]);
    eventually(fn() => $redis->get('ce:events:last_id') === $replayId, 'Replay checkpoint');
    same($fromEvent, $client->resolve(['ticket', 'event_flag'], $scopes), 'Replay preserves value, version and expiry');
    same(Redis::REDIS_STREAM, $redis->type('ce:events'), 'Event key remains a stream after bridge requests');
    echo "Real CE + Redis smoke passed: scoped CRUD, precedence, expiry, metadata, bridge PATCH, stream projection and replay\n";
} finally {
    // Only this run's scopes; stream history/checkpoints and mutation markers stay in the disposable instance.
    foreach ($scopes as $scope) {
        $client->unsetContext($scope['scopeType'], $id, $keys);
        $client->unsetContext($scope['scopeType'], $id, $keys, 'default');
    }
    $redis->close();
}
