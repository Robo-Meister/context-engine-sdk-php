# Context Engine PHP SDK (CE)

PHP 8.1+ SDK for connecting to **Context Engine (CE)** over HTTPS, with an optional Redis transport helper for async worker patterns.

## What this SDK covers (v0)

- CE REST operations:
  - `getContext(entityId)`
  - `upsertContext(entityId, payload)`
  - `deleteContext(entityId)`
  - `searchContext(criteria)`
- API key auth via `X-API-Key`.
- HTTPS enforcement by default (can be disabled for local dev only).
- Optional Redis helper (`ContextEngineRedisClient`) for pub/sub request-response flows.

## Requirements

- PHP **8.1+**
- cURL extension (`ext-curl`)
- JSON extension (`ext-json`)
- Optional Redis extension/client for Redis helper

## Installation

### Option A — copy files

Copy:
- `ContextEngineClient.php`
- `ContextEngineRedisClient.php`

Then load them through your autoloader or `require_once`.

### Option B — Composer path repository (recommended for monorepo use)

From your app project:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../context_engine_client/sdk/php"
    }
  ],
  "require": {
    "contextengine/php-sdk": "dev-main"
  }
}
```

Run:

```bash
composer update contextengine/php-sdk
```

## Quick start (HTTPS API)

```php
<?php

use ContextEngine\ContextEngineClient;

$client = new ContextEngineClient(
    'https://ce.example.com',
    getenv('CONTEXT_ENGINE_API_KEY') ?: null,
    timeout: 8
);

$entityId = 'user-123';

$current = $client->getContext($entityId);

$client->upsertContext($entityId, [
    'traits' => [
        'plan' => 'pro',
        'locale' => 'en-US',
    ],
    'events' => [
        ['type' => 'page_view', 'path' => '/pricing'],
    ],
]);

$found = $client->searchContext([
    'filter' => ['plan' => 'pro'],
    'limit' => 20,
]);

$client->deleteContext($entityId);
```

## Local development without HTTPS

For local CE instances (e.g., `http://localhost:8080`) you can explicitly disable HTTPS enforcement:

```php
$client = new ContextEngineClient(
    'http://localhost:8080',
    null,
    timeout: 8,
    enforceHttps: false
);
```

> Keep `enforceHttps: true` in production.

## Optional Redis flow

```php
<?php

use ContextEngine\ContextEngineRedisClient;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$ceRedis = new ContextEngineRedisClient($redis);

// Producer publishes CE-style request payload.
$requestId = $ceRedis->sendRequest([
    'method' => 'GET',
    'path' => 'context/user-123',
], waitForResponse: false);

// Consumer/worker handles incoming messages.
$ceRedis->handleRequests(function (array $payload, ?string $requestId, ?string $responseKey) {
    // Example worker logic
    return [
        'request_id' => $requestId,
        'ok' => true,
        'echo' => $payload,
    ];
}, stopAfter: 1);
```

## Error handling

Both clients throw `RuntimeException` on:
- transport failures,
- non-2xx HTTP responses,
- invalid JSON payload/response encoding issues,
- timeout while waiting for Redis response keys.

## Next planned steps

- Add interface + PSR-18 adapter option.
- Add pagination helpers for CE query patterns.
- Add framework integrations (Laravel/Symfony service providers).
- Publish as standalone package.
