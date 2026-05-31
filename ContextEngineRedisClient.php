<?php

declare(strict_types=1);

namespace ContextEngine;

use JsonException;
use RuntimeException;

/**
 * Lightweight helper for exchanging Context Engine messages over Redis.
 *
 * The helper publishes API-equivalent requests onto a Redis channel, tagging
 * each request with an ID and (optionally) waiting for a response written to a
 * predictable Redis key (e.g., "context_engine:response:{id}"). This allows
 * long-running Context Engine requests to be handled asynchronously by
 * back-end workers while callers continue to receive correlated responses.
 */
class ContextEngineRedisClient
{
    private $redis;
    private string $requestChannel;
    private string $responsePrefix;
    private string $greetingKey;
    private float $responseTimeout;

    public function __construct(
        $redisClient,
        string $requestChannel = 'context_engine:requests',
        string $responsePrefix = 'context_engine:response:',
        string $greetingKey = 'context_engine:greeting',
        float $responseTimeout = 5.0
    ) {
        if ($redisClient === null) {
            throw new RuntimeException('redisClient is required');
        }

        $this->redis = $redisClient;
        $this->requestChannel = $requestChannel;
        $this->responsePrefix = $responsePrefix;
        $this->greetingKey = $greetingKey;
        $this->responseTimeout = $responseTimeout;
    }

    /**
        * Publish a request payload and optionally wait for a keyed response.
        *
        * The published message always includes a unique ``id`` so that workers
        * can store a response at ``$responsePrefix . $id``. When
        * ``$waitForResponse`` is ``true``, the method polls Redis for that key
        * until the configured timeout elapses.
        *
        * @param array $payload Arbitrary JSON-serialisable payload.
        * @param bool $waitForResponse Whether to wait for a response key.
        * @param float|null $timeout Override the response timeout (seconds).
        *
        * @return mixed The decoded response when waiting, otherwise the
        * request ID so callers can fetch the response later if desired.
        */
    public function sendRequest(array $payload, bool $waitForResponse = false, ?float $timeout = null): mixed
    {
        $requestId = bin2hex(random_bytes(8));
        $responseKey = $this->responsePrefix . $requestId;

        $message = [
            'id' => $requestId,
            'payload' => $payload,
            'response_key' => $waitForResponse ? $responseKey : null,
        ];

        $this->publish($this->requestChannel, $message);

        if ($waitForResponse) {
            return $this->waitForResponse($responseKey, $timeout);
        }

        return $requestId;
    }

    /**
     * Listen for requests and respond using the provided handler.
     *
     * The handler receives ``(array $payload, ?string $requestId, ?string
     * $responseKey)`` and its return value is stored at the provided response
     * key when present.
     */
    public function handleRequests(callable $handler, ?int $stopAfter = null): int
    {
        $processed = 0;
        $channel = $this->requestChannel;

        if (!method_exists($this->redis, 'subscribe')) {
            throw new RuntimeException('Redis client must support subscribe');
        }

        $callback = function ($redis, $chan, $message) use ($handler, $stopAfter, &$processed) {
            $decoded = $this->decodeMessage($message);
            $payload = is_array($decoded) && isset($decoded['payload']) ? (array) $decoded['payload'] : [];
            $responseKey = is_array($decoded) && isset($decoded['response_key']) ? $decoded['response_key'] : null;
            $requestId = is_array($decoded) && isset($decoded['id']) ? $decoded['id'] : null;

            $response = $handler($payload, $requestId, $responseKey);
            if ($responseKey !== null) {
                $this->redis->set($responseKey, $this->encodeMessage($response));
            }

            $processed++;
            if ($stopAfter !== null && $processed >= $stopAfter) {
                if (method_exists($redis, 'unsubscribe')) {
                    $redis->unsubscribe([$chan]);
                }
            }
        };

        $this->redis->subscribe([$channel], $callback);

        return $processed;
    }

    /** Store a greeting or similar static value in Redis. */
    public function setGreeting(string $value): void
    {
        $this->redis->set($this->greetingKey, $value);
    }

    /** Retrieve the configured greeting value. */
    public function getGreeting(): ?string
    {
        $value = $this->redis->get($this->greetingKey);
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }

        return (string) $value;
    }

    private function publish(string $channel, array $message): void
    {
        try {
            $encoded = json_encode($message, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to encode payload as JSON: ' . $e->getMessage(), previous: $e);
        }

        if (!method_exists($this->redis, 'publish')) {
            throw new RuntimeException('Redis client must support publish');
        }

        $this->redis->publish($channel, $encoded);
    }

    private function waitForResponse(string $key, ?float $timeout): mixed
    {
        $waitFor = $timeout ?? $this->responseTimeout;
        $deadline = microtime(true) + $waitFor;

        while (microtime(true) < $deadline) {
            $value = $this->redis->get($key);
            if ($value !== null) {
                return $this->decodeMessage($value);
            }

            usleep(100_000); // 100ms backoff
        }

        throw new RuntimeException("No response stored at key '{$key}' within " . number_format($waitFor, 1) . ' seconds');
    }

    private function decodeMessage($raw): mixed
    {
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return '';
            }

            try {
                return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $raw;
            }
        }

        if (is_array($raw)) {
            return $raw;
        }

        return $raw;
    }

    private function encodeMessage($value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to encode response as JSON: ' . $e->getMessage(), previous: $e);
        }
    }
}
