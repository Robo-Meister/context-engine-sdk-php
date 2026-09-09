<?php

declare(strict_types=1);

namespace ContextEngine;

use JsonException;
use RuntimeException;

// Preserve the existing direct require_once installation path as well as Composer.
require_once __DIR__ . '/ContextEngineException.php';
require_once __DIR__ . '/ScopedContextClientInterface.php';

class ContextEngineClient implements ScopedContextClientInterface
{
    private string $baseUrl;
    private ?string $apiKey;
    private int $timeout;
    private ?string $bearerToken;

    public function __construct(
        string $baseUrl,
        ?string $apiKey = null,
        int $timeout = 5,
        bool $enforceHttps = true,
        ?string $bearerToken = null
    ) {
        if ($baseUrl === '') {
            throw new RuntimeException('baseUrl is required');
        }
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new RuntimeException('Context Engine base URL must include a scheme');
        }
        $scheme = strtolower($parts['scheme']);
        if ($enforceHttps && $scheme !== 'https') {
            throw new RuntimeException('Context Engine base URL must use https when enforceHttps=true');
        }
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $baseUrl)) {
            throw new RuntimeException('Invalid Context Engine base URL');
        }
        if ($timeout <= 0) {
            throw new RuntimeException('timeout must be positive');
        }
        foreach ([$apiKey, $bearerToken] as $credential) {
            if ($credential !== null && (trim($credential) === '' || preg_match('/[\r\n\x00]/', $credential))) {
                throw new RuntimeException('Credentials must be non-empty and contain no header delimiters');
            }
        }
        if ($apiKey !== null && $bearerToken !== null) {
            throw new RuntimeException('Select either apiKey or bearerToken authentication');
        }
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->apiKey = $apiKey;
        $this->bearerToken = $bearerToken;
        $this->timeout = $timeout;
    }

    public function resolve(array $keys, array $scopeRefs): array
    {
        $this->validateKeys($keys);
        if ($scopeRefs === [] || !array_is_list($scopeRefs)) {
            $this->invalid('scopeRefs must be a non-empty ordered list');
        }
        foreach ($scopeRefs as $scope) {
            if (!is_array($scope) || !isset($scope['scopeType'], $scope['scopeId'])
                || !is_string($scope['scopeType']) || !is_string($scope['scopeId'])) {
                $this->invalid('Each scopeRef requires string scopeType and scopeId');
            }
            $this->validateScope($scope['scopeType'], $scope['scopeId']);
        }
        return $this->readResult($this->request('POST', 'resolve', [
            'keys' => $keys, 'scopeRefs' => $scopeRefs,
        ], true), 'provenance');
    }

    public function getScopedContext(string $scopeType, string $scopeId, array $keys, string $store = 'active', ?string $kind = null): array
    {
        $this->validateKeys($keys);
        $this->validateStore($store, $kind);
        $body = ['store' => $store, 'keys' => $keys];
        if ($kind !== null) $body['kind'] = $kind;
        return $this->readResult($this->request('POST', $this->scopePath($scopeType, $scopeId, 'get'), $body, true), 'metadata');
    }

    public function setDefaultContext(string $scopeType, string $scopeId, array $values, ?string $updatedBy = null): array
    {
        $body = $this->writeBody($values, $updatedBy);
        return $this->writeResult($this->request('PATCH', $this->scopePath($scopeType, $scopeId, 'default'), $body, true));
    }

    public function setActiveContext(string $scopeType, string $scopeId, array $values, string $kind = 'STATE', ?int $ttl = null, ?string $updatedBy = null): array
    {
        $this->validateStore('active', $kind);
        if ($ttl !== null && $ttl <= 0) $this->invalid('ttl must be a positive number of seconds or null');
        $body = $this->writeBody($values, $updatedBy);
        $body['kind'] = $kind;
        if ($ttl !== null) $body['ttl'] = $ttl;
        return $this->writeResult($this->request('PATCH', $this->scopePath($scopeType, $scopeId, 'active'), $body, true));
    }

    public function unsetContext(string $scopeType, string $scopeId, array $keys, string $store = 'active', ?string $kind = null): array
    {
        $this->validateKeys($keys);
        $this->validateStore($store, $kind);
        $body = ['store' => $store, 'keys' => $keys];
        if ($kind !== null) $body['kind'] = $kind;
        return $this->writeResult($this->request('POST', $this->scopePath($scopeType, $scopeId, 'unset'), $body, true));
    }

    /** Legacy entity-document facade. Does not read event projections. */
    public function getContext(string $entityId): mixed
    {
        return $this->request('GET', 'context/' . rawurlencode($entityId));
    }

    /** Legacy entity-document facade. Does not apply scoped TTL or resolver semantics. */
    public function upsertContext(string $entityId, array $payload): mixed
    {
        return $this->request('PUT', 'context/' . rawurlencode($entityId), $payload);
    }

    public function deleteContext(string $entityId): bool
    {
        $this->request('DELETE', 'context/' . rawurlencode($entityId));
        return true;
    }

    public function searchContext(array $criteria): mixed
    {
        return $this->request('POST', 'context/search', $criteria);
    }

    private function scopePath(string $scopeType, string $scopeId, string $action): string
    {
        $this->validateScope($scopeType, $scopeId);
        return 'scopes/' . rawurlencode($scopeType) . '/' . rawurlencode($scopeId) . '/' . $action;
    }

    private function validateScope(string $scopeType, string $scopeId): void
    {
        // Current CE decodes path segments twice and joins storage identity with ':'.
        // Reject ambiguous identifiers instead of silently addressing another scope.
        foreach ([$scopeType, $scopeId] as $segment) {
            if (!preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9_.-]*\z/D', $segment)) {
                $this->invalid('Scope identifiers must use letters, digits, underscore, hyphen or dot and start with a letter, digit, underscore or hyphen');
            }
        }
    }

    private function validateKeys(array $keys): void
    {
        if ($keys === [] || !array_is_list($keys)) $this->invalid('keys must be a non-empty list');
        foreach ($keys as $key) {
            if (!is_string($key) || trim($key) === '' || trim($key) !== $key || str_contains($key, ':')
                || preg_match('/[\x00-\x1f\x7f]/', $key)) {
                $this->invalid('Context keys must be non-empty strings without colons, control characters or surrounding whitespace');
            }
        }
    }

    private function validateStore(string $store, ?string $kind): void
    {
        if (!in_array($store, ['active', 'default'], true)) $this->invalid('store must be active or default');
        if ($store === 'default' && $kind !== null) $this->invalid('default store does not accept kind');
        if ($kind !== null && !in_array($kind, ['STATE', 'CACHE'], true)) $this->invalid('kind must be STATE or CACHE');
    }

    private function writeBody(array $values, ?string $updatedBy): array
    {
        $this->validateKeys(array_keys($values));
        // CE #144 removes these names even inside values. Avoid silent data loss.
        foreach (['kind', 'ttl', 'updatedBy', 'principal'] as $reserved) {
            if (array_key_exists($reserved, $values)) $this->invalid('This CE version reserves write key: ' . $reserved);
        }
        $body = ['values' => (object) $values];
        if ($updatedBy !== null) $body['updatedBy'] = $updatedBy;
        return $body;
    }

    private function readResult(mixed $result, string $metadataField): array
    {
        if (!is_array($result) || !isset($result['values'], $result[$metadataField], $result['missingKeys'])
            || !is_array($result['values']) || !is_array($result[$metadataField])
            || !is_array($result['missingKeys']) || !array_is_list($result['missingKeys'])) {
            throw new ContextEngineException('Invalid scoped read response', 'invalid_response');
        }
        return $result;
    }

    private function writeResult(mixed $result): array
    {
        if (!is_array($result) || ($result['status'] ?? null) !== 'ok') {
            throw new ContextEngineException('Invalid scoped mutation response', 'invalid_response');
        }
        return $result;
    }

    private function invalid(string $message): never
    {
        throw new ContextEngineException($message, 'invalid_request');
    }

    private function request(string $method, string $path, ?array $payload = null, bool $requireJson = false): mixed
    {
        try {
            $json = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new ContextEngineException('Failed to encode payload as JSON', 'invalid_request', previous: $e);
        }
        $ch = curl_init($this->baseUrl . ltrim($path, '/'));
        if ($ch === false) throw new ContextEngineException('Unable to initialize cURL', 'transport_error');
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($this->apiKey !== null) $headers[] = 'X-API-Key: ' . $this->apiKey;
        if ($this->bearerToken !== null) $headers[] = 'Authorization: Bearer ' . $this->bearerToken;
        $responseHeaders = [];
        try {
            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                    if (str_starts_with($line, 'HTTP/')) $responseHeaders = [];
                    if (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $responseHeaders[strtolower(trim($name))] = trim($value);
                    }
                    return strlen($line);
                },
            ];
            if ($json !== null) $options[CURLOPT_POSTFIELDS] = $json;
            curl_setopt_array($ch, $options);
            $body = curl_exec($ch);
            if ($body === false) {
                // Do not echo a URL or credentials from a transport diagnostic.
                throw new ContextEngineException('Context Engine transport failed', 'transport_error', curlErrorCode: curl_errno($ch));
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
        } finally {
            curl_close($ch);
        }
        if ($status < 200 || $status >= 300) {
            // Error body is available explicitly, but excluded from default exception logs.
            throw new ContextEngineException('Context Engine request failed: ' . $status, 'http_error', $status, $body, $responseHeaders);
        }
        if (!$requireJson && ($status === 204 || $body === '')) return null;
        if ($requireJson || preg_match('~(?:application/json|[a-z0-9.+-]+/[a-z0-9.+-]+\+json)(?:\s*;|$)~i', $contentType)) {
            try {
                return json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (JsonException $e) {
                throw new ContextEngineException('Failed to decode JSON response', 'invalid_json', $status, $body, $responseHeaders, previous: $e);
            }
        }
        return $body;
    }
}
