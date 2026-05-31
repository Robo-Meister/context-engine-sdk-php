<?php

declare(strict_types=1);

require_once __DIR__ . '/../ContextEngineClient.php';

use ContextEngine\ContextEngineClient;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertThrows(callable $fn, string $expectedMessageContains): void
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        assertTrue(
            str_contains($e->getMessage(), $expectedMessageContains),
            "Expected exception message to contain '{$expectedMessageContains}', got '{$e->getMessage()}'"
        );
        return;
    }

    throw new RuntimeException('Expected RuntimeException to be thrown');
}

function runContextEngineClientTests(): void
{
    // Accepts https by default.
    $httpsClient = new ContextEngineClient('https://ce.example.com');
    assertTrue($httpsClient instanceof ContextEngineClient, 'Expected https client to be created');

    // Rejects base URL without scheme.
    assertThrows(
        fn() => new ContextEngineClient('ce.example.com'),
        'must include a scheme'
    );

    // Rejects insecure scheme when enforceHttps=true (default).
    assertThrows(
        fn() => new ContextEngineClient('http://localhost:8080'),
        'must use https when enforceHttps=true'
    );

    // Allows local HTTP when explicitly disabled.
    $httpClient = new ContextEngineClient('http://localhost:8080', null, 5, false);
    assertTrue($httpClient instanceof ContextEngineClient, 'Expected http client to be created when enforceHttps=false');
}

runContextEngineClientTests();
echo "ContextEngineClient tests passed\n";
