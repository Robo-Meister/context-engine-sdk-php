<?php

declare(strict_types=1);

function same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function fails(callable $action, string $contains): RuntimeException
{
    try {
        $action();
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), $contains)) throw $e;
        return $e;
    }
    throw new RuntimeException('Expected failure: ' . $contains);
}
