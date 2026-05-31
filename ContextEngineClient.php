<?php

declare(strict_types=1);

namespace ContextEngine;

use JsonException;
use RuntimeException;

class ContextEngineClient
{
    private string $baseUrl;
    private ?string $apiKey;
    private int $timeout;

    public function __construct(
        string $baseUrl,
        ?string $apiKey = null,
        int $timeout = 5,
        bool $enforceHttps = true
    )
    {
        if ($baseUrl === '') {
            throw new RuntimeException('baseUrl is required');
        }

        $parts = parse_url($baseUrl);
        if (!isset($parts['scheme'])) {
            throw new RuntimeException('Context Engine base URL must include a scheme');
        }

        $scheme = strtolower($parts['scheme']);
        if ($enforceHttps && $scheme !== 'https') {
            throw new RuntimeException('Context Engine base URL must use https when enforceHttps=true');
        }

        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
    }

    public function getContext(string $entityId): mixed
    {
        return $this->request('GET', 'context/' . rawurlencode($entityId));
    }

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

    private function request(string $method, string $path, ?array $payload = null): mixed
    {
        $url = $this->baseUrl . ltrim($path, '/');
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL');
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if ($this->apiKey !== null) {
            $headers[] = 'X-API-Key: ' . $this->apiKey;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
        ];

        if ($payload !== null) {
            try {
                $json = json_encode($payload, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                curl_close($ch);
                throw new RuntimeException('Failed to encode payload as JSON: ' . $e->getMessage(), previous: $e);
            }
            $options[CURLOPT_POSTFIELDS] = $json;
        }

        curl_setopt_array($ch, $options);
        $responseBody = curl_exec($ch);

        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Context Engine request failed: ' . $error);
        }

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = trim((string) $responseBody);
            $detail = $message !== '' ? ' - ' . $message : '';
            throw new RuntimeException('Context Engine request failed: ' . $statusCode . $detail);
        }

        if ($statusCode === 204 || $responseBody === '' || $responseBody === null) {
            return null;
        }

        if (str_contains(strtolower($contentType), 'application/json')) {
            try {
                return json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException('Failed to decode JSON response: ' . $e->getMessage(), previous: $e);
            }
        }

        return $responseBody;
    }
}
