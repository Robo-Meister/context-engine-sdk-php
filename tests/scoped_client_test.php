<?php

declare(strict_types=1);

require_once __DIR__ . '/../ContextEngineClient.php';
require_once __DIR__ . '/assertions.php';

use ContextEngine\ContextEngineClient;
use ContextEngine\ContextEngineException;
use ContextEngine\ScopedContextClientInterface;

$directory = sys_get_temp_dir() . '/ce-http-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0');
if ($socket === false) throw new RuntimeException('Cannot reserve fixture port');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$state = $directory . '/state.json';
$log = $directory . '/requests.jsonl';
$env = getenv();
$env['CE_FIXTURE_STATE'] = $state;
$env['CE_FIXTURE_LOG'] = $log;
$process = proc_open([PHP_BINARY, '-n', '-S', $address, __DIR__ . '/fixtures/http.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes, null, $env);
if (!is_resource($process)) throw new RuntimeException('Cannot start HTTP fixture');
fclose($pipes[0]);

function respond(mixed $body, int $status = 200, array $headers = [], bool $raw = false): void
{
    global $state;
    file_put_contents($state, json_encode([
        'status' => $status, 'headers' => $headers + ['Content-Type' => 'application/json'],
        'body' => $raw ? $body : json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
    ], JSON_THROW_ON_ERROR));
}

function requests(): array
{
    global $log;
    return is_file($log) ? array_map(fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($log, FILE_IGNORE_NEW_LINES)) : [];
}

function lastRequest(): array
{
    $requests = requests();
    return $requests[array_key_last($requests)];
}

try {
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
        if ($probe !== false) { fclose($probe); $ready = true; break; }
        usleep(20_000);
    }
    same(true, $ready, 'Fixture starts');
    $client = new ContextEngineClient('http://' . $address . '/api', 'fixture-api-key', 2, false);
    same(true, $client instanceof ScopedContextClientInterface, 'Scoped client interface');
    $scopes = [['scopeType' => 'session', 'scopeId' => 'run-123'], ['scopeType' => 'organisation', 'scopeId' => 'org-123']];
    $metadata = ['scopeRef' => $scopes[0], 'store' => 'active', 'kind' => 'STATE',
        'version' => 42, 'updatedAt' => 1750000000000, 'updatedBy' => 'rc-user',
        'ttlSeconds' => 60, 'expiresAt' => 1750000060000, 'futureField' => 'preserved'];
    $values = ['ticket' => ['id' => 'T-1', 'amount' => 2.5], 'enabled' => false, 'count' => 0, 'empty' => '', 'nullable' => null, 'list' => [1, 'two']];
    $resolved = ['values' => $values, 'provenance' => ['ticket' => $metadata], 'missingKeys' => ['missing']];
    respond($resolved);
    $keys = [...array_keys($values), 'missing'];
    same($resolved, $client->resolve($keys, $scopes), 'Resolver preserves typed values, metadata and missing keys');
    same(1, count(requests()), 'Batch resolution takes one HTTP request');
    same('POST', lastRequest()['method'], 'Resolve method');
    same('/api/resolve', lastRequest()['uri'], 'Resolve endpoint keeps base path');
    same(['keys' => $keys, 'scopeRefs' => $scopes], lastRequest()['body'], 'Ordered scopes and keys sent unchanged');
    same('fixture-api-key', lastRequest()['headers']['x-api-key'], 'API key header');
    same(false, isset(lastRequest()['headers']['authorization']), 'No implicit bearer');

    respond(['status' => 'ok']);
    same(['status' => 'ok'], $client->setActiveContext('session', 'run-123', $values, 'CACHE', 60, 'rc-user'), 'Active acknowledgement');
    same('PATCH', lastRequest()['method'], 'Active write uses PATCH');
    same('/api/scopes/session/run-123/active', lastRequest()['uri'], 'Active route');
    same(['values' => $values, 'updatedBy' => 'rc-user', 'kind' => 'CACHE', 'ttl' => 60], lastRequest()['body'], 'Typed active values with seconds TTL');
    $client->setActiveContext('session', 'run-123', ['ticket' => 'state']);
    same(['values' => ['ticket' => 'state'], 'kind' => 'STATE'], lastRequest()['body'], 'Null TTL omitted and STATE default');
    $client->setDefaultContext('session', 'run-123', ['ticket' => 'default'], 'rc-user');
    same('/api/scopes/session/run-123/default', lastRequest()['uri'], 'Default route');
    same(['values' => ['ticket' => 'default'], 'updatedBy' => 'rc-user'], lastRequest()['body'], 'Default writes cannot acquire TTL or kind');

    respond(['values' => ['ticket' => null], 'metadata' => ['ticket' => $metadata], 'missingKeys' => ['missing']]);
    $read = $client->getScopedContext('session', 'run-123', ['ticket', 'missing'], 'active', 'STATE');
    same(null, $read['values']['ticket'], 'Explicit null preserved');
    same($metadata, $read['metadata']['ticket'], 'Read metadata unchanged');
    same(['store' => 'active', 'keys' => ['ticket', 'missing'], 'kind' => 'STATE'], lastRequest()['body'], 'Scoped get request');
    same('/api/scopes/session/run-123/get', lastRequest()['uri'], 'Get route');

    respond(['status' => 'ok']);
    $client->unsetContext('session', 'run-123', ['ticket']);
    same('POST', lastRequest()['method'], 'Unset method');
    same('/api/scopes/session/run-123/unset', lastRequest()['uri'], 'Unset route');
    same(['store' => 'active', 'keys' => ['ticket']], lastRequest()['body'], 'Unset both active kinds leaves kind absent');
    $client->unsetContext('session', 'run-123', ['ticket'], 'default');
    same(['store' => 'default', 'keys' => ['ticket']], lastRequest()['body'], 'Default unset');

    $bearer = new ContextEngineClient('http://' . $address, timeout: 2, enforceHttps: false, bearerToken: 'fixture-bearer');
    respond($resolved);
    $bearer->resolve(['ticket'], $scopes);
    same('Bearer fixture-bearer', lastRequest()['headers']['authorization'], 'Explicit bearer header');
    same(false, isset(lastRequest()['headers']['x-api-key']), 'Bearer excludes API key');

    foreach ([401, 403, 404, 409, 429, 500, 503, 302] as $status) {
        respond(['error' => 'private-body'], $status, ['Retry-After' => '7', 'Location' => 'http://' . $address . '/unexpected']);
        $before = count(requests());
        $e = fails(fn() => $client->setActiveContext('session', 'run-123', ['ticket' => 1]), (string) $status);
        same(true, $e instanceof ContextEngineException, 'Structured HTTP error');
        same('http_error', $e->errorCode, 'HTTP error category');
        same($status, $e->statusCode, 'HTTP status retained');
        same('7', $e->responseHeaders['retry-after'], 'Retry metadata retained');
        same(true, str_contains($e->responseBody, 'private-body'), 'Body available explicitly');
        same(false, str_contains($e->getMessage(), 'private-body'), 'No error payload in default logs');
        same($before + 1, count(requests()), 'No retry, redirect or fallback on failed mutation');
    }
    respond('{broken', raw: true);
    $e = fails(fn() => $client->resolve(['ticket'], $scopes), 'decode JSON');
    same('invalid_json', $e->errorCode, 'Malformed JSON error');
    same(200, $e->statusCode, 'Malformed response status retained');
    foreach ([null, [], ['values' => [], 'missingKeys' => []], ['values' => [], 'provenance' => [], 'missingKeys' => 'bad']] as $bad) {
        respond($bad);
        same('invalid_response', fails(fn() => $client->resolve(['ticket'], $scopes), 'Invalid scoped read')->errorCode, 'Invalid envelope rejected');
    }
    respond(['status' => 'queued']);
    fails(fn() => $client->setDefaultContext('session', 'run-123', ['ticket' => 1]), 'Invalid scoped mutation');

    $before = count(requests());
    foreach (['other:scope', 'a/b', 'a%2Fb', 'a+b', '..', 'white space'] as $id) {
        fails(fn() => $client->getScopedContext('session', $id, ['ticket']), 'Scope identifiers');
    }
    fails(fn() => $client->resolve([], $scopes), 'keys');
    fails(fn() => $client->resolve(['ticket'], []), 'scopeRefs');
    fails(fn() => $client->resolve(['ticket'], [['scopeType' => 'session', 'scopeId' => 1]]), 'scopeRef');
    fails(fn() => $client->resolve(['bad:key'], $scopes), 'Context keys');
    fails(fn() => $client->setActiveContext('session', 'run-123', ['ticket' => 1], ttl: 0), 'ttl');
    fails(fn() => $client->setActiveContext('session', 'run-123', ['ticket' => 1], kind: 'cache'), 'kind');
    fails(fn() => $client->getScopedContext('session', 'run-123', ['ticket'], 'default', 'STATE'), 'does not accept kind');
    fails(fn() => $client->setDefaultContext('session', 'run-123', ['ttl' => 1]), 'reserves write key');
    fails(fn() => $client->setDefaultContext('session', 'run-123', ['ticket' => INF]), 'encode payload');
    same($before, count(requests()), 'Invalid input rejected before network I/O');
    fails(fn() => new ContextEngineClient('https://ce.example', 'key', bearerToken: 'token'), 'either');
    fails(fn() => new ContextEngineClient('https://ce.example', "key\r\nInjected: yes"), 'delimiters');
    fails(fn() => new ContextEngineClient('https://user:pass@ce.example'), 'Invalid');
    fails(fn() => new ContextEngineClient('https://ce.example?token=value'), 'Invalid');

    respond(['legacy' => true]);
    same(['legacy' => true], $client->getContext('ticket 1'), 'Legacy GET remains available');
    same('/api/context/ticket%201', lastRequest()['uri'], 'Legacy encoded path');
    $client->upsertContext('ticket-1', ['legacy' => true]);
    same('PUT', lastRequest()['method'], 'Legacy PUT retained');
    $client->searchContext(['type' => 'ticket']);
    same('/api/context/search', lastRequest()['uri'], 'Legacy search retained');
    respond('', 204, raw: true);
    same(true, $client->deleteContext('ticket-1'), 'Legacy empty DELETE success');
    respond('legacy text', headers: ['Content-Type' => 'text/plain'], raw: true);
    same('legacy text', $client->getContext('ticket-1'), 'Legacy non-JSON response retained');
    echo "Scoped HTTP contract tests passed\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}

// The fixture listener is now gone: distinguish network failure from missing context.
$e = fails(fn() => $client->resolve(['ticket'], $scopes), 'transport failed');
same('transport_error', $e->errorCode, 'Transport error category');
same(true, $e->curlErrorCode > 0, 'cURL code retained');
