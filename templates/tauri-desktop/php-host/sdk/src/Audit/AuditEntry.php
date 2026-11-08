<?php

declare(strict_types=1);

namespace Whity\Sdk\Audit;

/**
 * One row of a plugin's own audit entries (WHIT-1032). `action` and `targetType`
 * are the bare names the plugin used. The actor was recorded by the host:
 * `actorKind` is `person`, `agent` or `system`; for an agent,
 * `onBehalfOfProfileId` is the person it acted for.
 */
final class AuditEntry
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly int $id,
        public readonly string $action,
        public readonly ?string $targetType,
        public readonly ?int $targetId,
        public readonly array $metadata,
        public readonly ?string $actorKind,
        public readonly ?int $actorProfileId,
        public readonly ?int $onBehalfOfProfileId,
        public readonly ?string $createdAt,
    ) {
    }
}
