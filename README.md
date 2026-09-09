# Context Engine PHP SDK

PHP 8.1+ client for the scoped Context Engine API introduced by
[`CE-CONTRACT-TRANSPORT-PARITY-001` (CE PR #144)](https://github.com/Robo-Meister/context_engine_client/pull/144).
The canonical application client uses HTTP. An optional Redis request/reply helper remains available.

## Installation

Requires PHP 8.1+, `ext-curl` and `ext-json`. The Composer package name stays
`robo-meister/context-engine-api`; this change does not create a release tag.

For a local checkout, add this to your application's `composer.json`:

```json
{
  "repositories": [{"type": "path", "url": "../context-engine-sdk-php"}],
  "require": {"robo-meister/context-engine-api": "dev-main"}
}
```

Then run `composer update robo-meister/context-engine-api` and load `vendor/autoload.php`.
For direct installation copy all four root PHP files, including
`ContextEngineException.php` and `ScopedContextClientInterface.php`, then
`require_once 'ContextEngineClient.php'` (and the Redis client if used).

## Scoped API

```php
use ContextEngine\ContextEngineClient;

$ce = new ContextEngineClient(
    'https://ce.example.com',
    apiKey: getenv('CONTEXT_ENGINE_API_KEY') ?: null,
    timeout: 8
);

// Application code can depend on ScopedContextClientInterface.
$scopes = [
    ['scopeType' => 'session', 'scopeId' => 'session-123'],
    ['scopeType' => 'organisation', 'scopeId' => 'org-123'],
];

$ce->setDefaultContext('organisation', 'org-123', ['locale' => 'pl']);
$ce->setActiveContext('session', 'session-123', [
    'document' => ['context_id' => 'doc-123', 'display_name' => 'Invoice'],
    'reviewed' => false,
], kind: 'STATE', ttl: 300, updatedBy: 'user-123');

$result = $ce->resolve(['document', 'reviewed', 'locale'], $scopes);
$document = $result['values']['document'] ?? null;
$source = $result['provenance']['document'] ?? null;
$missing = $result['missingKeys'];

$record = $ce->getScopedContext('session', 'session-123', ['document'], 'active', 'STATE');
$metadata = $record['metadata']['document'] ?? null;
$ce->unsetContext('session', 'session-123', ['document']);
```

| Method | HTTP operation | Result |
| --- | --- | --- |
| `resolve(keys, scopeRefs)` | `POST /resolve` | `values`, `provenance`, `missingKeys` |
| `getScopedContext(scopeType, scopeId, keys, store, kind)` | `POST /scopes/{type}/{id}/get` | `values`, `metadata`, `missingKeys` |
| `setDefaultContext(scopeType, scopeId, values, updatedBy)` | `PATCH /scopes/{type}/{id}/default` | `status: ok` |
| `setActiveContext(scopeType, scopeId, values, kind, ttl, updatedBy)` | `PATCH /scopes/{type}/{id}/active` | `status: ok` |
| `unsetContext(scopeType, scopeId, keys, store, kind)` | `POST /scopes/{type}/{id}/unset` | `status: ok` |

One `resolve` call sends one request for all supplied keys. The SDK preserves scope
order; CE checks each scope's active `STATE`, active `CACHE`, then `default`, before
moving to the next scope. It neither expands dependencies nor implements a local resolver.
With `active`, omitted `kind` reads STATE then CACHE; on unset it removes both kinds.
With `default`, `kind` is not accepted.

TTL is a positive integer in **seconds**. `null` omits TTL; `default` has no TTL
parameter. Version, `scopeRef`, `store`, `kind`, `updatedBy`, `updatedAt`,
`ttlSeconds` and `expiresAt` are returned as supplied by CE. Optional fields stay
optional, and unknown metadata fields are preserved. Server timestamps are epoch
milliseconds. Version increases for an existing record; it can restart after
expiry or deletion. The SDK does not manufacture a version or renew expiry on read.

Results are associative PHP arrays. JSON objects decode to arrays, and JSON integers
beyond the platform integer range decode to strings. Use `array_key_exists` to
distinguish an explicit `null` value from a missing key; `false`, zero and empty
strings remain values. Mutations return acknowledgements, not the new metadata.
Read explicitly when an application needs the resulting version or preview.

## Authentication and failures

The existing constructor's first four parameters are unchanged. For a gateway
using bearer authentication, supply `bearerToken` instead of `apiKey`:

```php
$ce = new ContextEngineClient(
    'https://ce.example.com',
    bearerToken: $serviceToken
);
```

HTTPS is required by default. For an explicitly trusted local endpoint use
`new ContextEngineClient('http://localhost:8080', timeout: 8, enforceHttps: false)`.
Certificate verification stays enabled for HTTPS, and redirects are never followed.
The SDK does not exchange OAuth tokens or infer account/subscription permissions.
Scope selection and `updatedBy` are data, not proof of authority: your trusted
application/gateway must enforce access. CE PR #144's WebServer does not itself
validate these authentication headers.

```php
use ContextEngine\ContextEngineException;

try {
    $result = $ce->resolve(['document'], $scopes);
} catch (ContextEngineException $e) {
    // Classify with errorCode and statusCode, e.g. 401/403, 429 or 503.
    // responseHeaders can include retry-after; curlErrorCode identifies transport errors.
    // responseBody is available explicitly for controlled diagnostics.
    throw $e;
}
```

Scoped failures use `invalid_request`, `transport_error`, `http_error`,
`invalid_json` or `invalid_response`. HTTP failures preserve status, body and
headers, but the default exception message excludes the body and credentials.
Existing `RuntimeException` catches still work; constructor configuration errors
remain `RuntimeException`. There is no automatic retry, fallback transport, or
missing-context substitution on a failed request. In particular, CE HTTP writes
do not expose mutation idempotency keys: a timeout can leave their outcome unknown.

## Compatibility and server limits

`getContext`, `upsertContext`, `deleteContext` and `searchContext` retain their
signatures and legacy `/context` routes. That facade uses `ce:context:*` and does
**not** expose canonical scoped records from events. RC must explicitly migrate
its context reads to the scoped API; installing this SDK alone does not switch them.

The scoped client targets CE `12683d8051314334cae234968a38accfbf2b5456`
(main after PR #144). Current server limitations are made explicit:

- Scope type/ID must match `[A-Za-z0-9_-][A-Za-z0-9_.-]*`. This excludes ambiguous
  delimiters and encoded path characters that the current server double-decodes.
- Keys are non-empty strings, without colons, control characters or surrounding
  whitespace. Scoped writes reject `kind`, `ttl`, `updatedBy` and `principal`
  as value keys because this server removes them even from the nested `values` map.
- Stream projection lowercases scope types and normalizes projected field names;
  HTTP preserves them. For cross-transport reads use matching lowercase scope
  types and lowercase ASCII keys with digits, underscores, dots or hyphens.
  The SDK never silently renames data.
- Event `updatedBy` currently derives from `principal.id`, then actor/source;
  the server does not map `principal.principalId` here.

These constraints need server-side follow-up before the SDK can relax them.
This PR does not claim full RC/event-mapper interoperability or fix server lifecycle.

## Optional Redis request/reply

This is the existing **Pub/Sub control bridge**, not the durable event stream.
It requires a running CE `RedisRequestListener` and a caller-supplied Redis client
configured without transparent serialization. `sendRequest(..., true)` returns
the bridge envelope (`status`, JSON-string `body`, `request_id`), not the scoped
HTTP client's result. HTTP status errors inside that envelope must be checked.

```php
use ContextEngine\ContextEngineRedisClient;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$bridge = new ContextEngineRedisClient($redis);
$reply = $bridge->sendRequest([
    'method' => 'POST',
    'path' => '/resolve',
    'body' => ['keys' => ['document'], 'scopeRefs' => $scopes],
], waitForResponse: true);
if (($reply['status'] ?? null) !== 200) {
    throw new RuntimeException('CE bridge request failed');
}
$result = json_decode($reply['body'], true, 512, JSON_THROW_ON_ERROR);
```

The control bridge is for trusted internal Redis clients. The current server
bridge does not forward SDK HTTP authentication headers. Redis connection/auth/ACL
configuration remains owned by the application and infrastructure.

`waitForResponse: false` returns the request ID after publish; it does not prove
that a listener received the request or that context was updated. Pub/Sub does not
queue messages for disconnected listeners. No automatic retry is performed.
Both `false` (phpredis) and `null` (other clients) mean a reply key is still absent.

Defaults remain `context_engine:requests`, `context_engine:response:` and
`context_engine:greeting`. The helper rejects CE stream/state key namespaces
(including `ce:events`) for its request/reply/greeting configuration. If the stream
has a custom name, pass that name as `eventsStreamKey` too. There is no `SET`
operation on the event stream, no key deletion to repair a wrong type, and no
implicit direct read of CE state keys.

For applications using the optional **PHP** `handleRequests` worker, a separate
`responseWriter` Redis connection is now required. Subscribed connections cannot
safely write responses. The worker writes JSON via `SETEX` with
`responseTtlSeconds` (default 300), and only accepts reply keys with its configured
prefix plus the SDK's 16-character hex request ID. Other request-ID formats need
an explicit application adapter. Validate/authenticate business payloads in your
handler. Existing calls to `sendRequest` do not need a response writer.

## Verification

```bash
composer test
```

Runs legacy constructor checks, local HTTP contract tests, and Redis helper tests.
The HTTP fixture starts on a random loopback port; normal tests need neither a CE
server nor a Redis extension/server. They cover request count and scope order,
PATCH payloads, metadata, typed values, errors, no retry/redirect, and reserved keys.

For a disposable local CE + Redis instance matching the server commit above,
with its stream consumer and request listener enabled, additionally run:

```bash
CE_TEST_ISOLATED=1 CE_TEST_BASE_URL=http://127.0.0.1:18080 CE_TEST_REDIS_PORT=16379 \
  composer test:server
```

This requires `ext-redis` and creates randomized test scopes, stream entries,
checkpoints and replay markers. Run only against a disposable local instance.
It checks scoped CRUD, resolver precedence, expiry, metadata, bridge PATCH and
HTTP parity, event projection visible via `/resolve`, and replay preserving version/TTL.
It does not flush Redis. Stream history/markers remain for disposal with the test instance.
