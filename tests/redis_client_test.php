<?php

declare(strict_types=1);

require_once __DIR__ . '/../ContextEngineRedisClient.php';
require_once __DIR__ . '/assertions.php';

use ContextEngine\ContextEngineRedisClient;

class FakeRedis
{
    public array $reads = [];
    public array $published = [];
    public array $writes = [];
    public array $messages = [];
    public bool $publishFails = false;
    public bool $writeFails = false;
    public bool $unsubscribed = false;

    public function get(string $key): mixed { return array_shift($this->reads); }
    public function publish(string $channel, string $body): int|false
    {
        $this->published[] = [$channel, json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
        return $this->publishFails ? false : 0;
    }
    public function set(string $key, string $value): bool
    {
        $this->writes[] = [$key, $value];
        return !$this->writeFails;
    }
    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->writes[] = [$key, $ttl, $value];
        return !$this->writeFails;
    }
    public function subscribe(array $channels, callable $callback): void
    {
        foreach ($this->messages as $message) $callback($this, $channels[0], json_encode($message, JSON_THROW_ON_ERROR));
    }
    public function unsubscribe(array $channels): void { $this->unsubscribed = true; }
}

foreach ([false, null] as $missing) {
    $redis = new FakeRedis();
    $redis->reads = [$missing, '{"status":200,"body":"{}"}'];
    $client = new ContextEngineRedisClient($redis);
    same(['status' => 200, 'body' => '{}'], $client->sendRequest(['path' => '/resolve'], true), 'Missing Redis key keeps polling');
    same(1, count($redis->published), 'Request published only once');
    same('context_engine:requests', $redis->published[0][0], 'Request channel is separate from event stream');
    $envelope = $redis->published[0][1];
    same('context_engine:response:' . $envelope['id'], $envelope['response_key'], 'Correlated reply key');
    $redis->reads = [$missing];
    same(null, $client->getGreeting(), 'Missing greeting is null');
}

foreach (['false' => false, 'null' => null, '0' => 0, '""' => '', '"plain"' => 'plain'] as $wire => $expected) {
    $redis = new FakeRedis();
    $redis->reads = [(string) $wire];
    same($expected, (new ContextEngineRedisClient($redis))->sendRequest(['path' => '/resolve'], true), 'JSON scalars survive reply decoding');
}
$redis = new FakeRedis();
fails(fn() => (new ContextEngineRedisClient($redis, responseTimeout: 0.01))->sendRequest([], true), 'No response stored');
same(1, count($redis->published), 'Timeout does not resend');
$redis->publishFails = true;
fails(fn() => (new ContextEngineRedisClient($redis))->sendRequest([]), 'publish failed');

foreach (['ce:events', 'ce:record:session:1', 'ce:events:last_id', 'ce:tombstone:foo'] as $reserved) {
    fails(fn() => new ContextEngineRedisClient(new FakeRedis(), greetingKey: $reserved), 'must not use CE');
    fails(fn() => new ContextEngineRedisClient(new FakeRedis(), responsePrefix: $reserved), 'must not use CE');
}
fails(fn() => new ContextEngineRedisClient(new FakeRedis(), responsePrefix: 'ce:'), 'overlap');
fails(fn() => new ContextEngineRedisClient(new FakeRedis(), greetingKey: 'tenant:events', eventsStreamKey: 'tenant:events'), 'must not use CE');

$subscriber = new FakeRedis();
$writer = new FakeRedis();
$responseKey = 'context_engine:response:1234567890abcdef';
$subscriber->messages = [['id' => '1234567890abcdef', 'payload' => ['path' => '/resolve'], 'response_key' => $responseKey]];
$client = new ContextEngineRedisClient($subscriber, responseWriter: $writer, responseTtlSeconds: 45);
same(1, $client->handleRequests(fn() => false, 1), 'One request processed');
same([], $subscriber->writes, 'Subscribed connection never writes');
same([[$responseKey, 45, 'false']], $writer->writes, 'Separate connection writes JSON with bounded TTL');
same(true, $subscriber->unsubscribed, 'stopAfter unsubscribes');
fails(fn() => (new ContextEngineRedisClient($subscriber))->handleRequests(fn() => []), 'separate responseWriter');
fails(fn() => (new ContextEngineRedisClient($subscriber, responseWriter: $subscriber))->handleRequests(fn() => []), 'separate responseWriter');
$subscriber->messages[0]['response_key'] = 'ce:events';
$called = false;
fails(fn() => $client->handleRequests(function () use (&$called) { $called = true; }), 'Invalid response key');
same(false, $called, 'Invalid response key rejected before handler side effects');
same(1, count($writer->writes), 'No write to event stream key');
echo "Redis helper tests passed\n";
