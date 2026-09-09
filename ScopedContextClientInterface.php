<?php

declare(strict_types=1);

namespace ContextEngine;

/** Scoped application API. Resolver precedence and record metadata belong to CE. */
interface ScopedContextClientInterface
{
    /**
     * @param list<string> $keys
     * @param list<array{scopeType:string,scopeId:string}> $scopeRefs
     */
    public function resolve(array $keys, array $scopeRefs): array;

    /** @param list<string> $keys */
    public function getScopedContext(string $scopeType, string $scopeId, array $keys, string $store = 'active', ?string $kind = null): array;

    /** @param array<string,mixed> $values */
    public function setDefaultContext(string $scopeType, string $scopeId, array $values, ?string $updatedBy = null): array;

    /** @param array<string,mixed> $values */
    public function setActiveContext(string $scopeType, string $scopeId, array $values, string $kind = 'STATE', ?int $ttl = null, ?string $updatedBy = null): array;

    /**
     * Null kind with active store unsets both STATE and CACHE.
     * @param list<string> $keys
     */
    public function unsetContext(string $scopeType, string $scopeId, array $keys, string $store = 'active', ?string $kind = null): array;
}
