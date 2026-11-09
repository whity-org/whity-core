<?php

declare(strict_types=1);

namespace Whity\Core\Audit;

use PDO;
use Whity\Sdk\Audit\AuditEntry;
use Whity\Sdk\Audit\AuditTrail;
use Whity\Sdk\PluginNamespace;

/**
 * One plugin's audit entries (WHIT-1032). Writes go through {@see AuditLogger},
 * still the only writer of `audit_log`, with only the action, target and metadata:
 * the actor and IP address are left for the logger to take from
 * {@see AuditContext}. The namespace on the action is the attribution, and a
 * bare name can't carry a colon to write its own.
 */
final class HostAuditTrail implements AuditTrail
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';
    private const MAX_NAME_LENGTH = 100;
    private const MAX_METADATA_BYTES = 16384;
    private const MAX_ENTRIES = 200;

    /** @param \Closure(): ?int $tenant */
    public function __construct(
        private readonly PDO $db,
        private readonly AuditLoggerInterface $audit,
        private readonly string $slug,
        private readonly \Closure $tenant,
    ) {
    }

    public function record(string $action, ?string $targetType = null, ?int $targetId = null, array $metadata = []): void
    {
        $tenantId = $this->workspace();
        $options = [
            'tenant_id' => $tenantId,
            'target_type' => $targetType === null ? null : $this->namespaced($targetType),
            'target_id' => $targetId,
            'metadata' => $metadata,
        ];
        $canonical = $this->namespaced($action);
        if (strlen((string) json_encode($metadata)) > self::MAX_METADATA_BYTES) {
            throw new \InvalidArgumentException('Audit metadata is too large.');
        }

        $this->audit->record($canonical, $options);
    }

    public function entries(int $limit = 50, ?int $beforeId = null, ?string $action = null): array
    {
        $tenantId = $this->workspace();
        $params = [':tenant_id' => $tenantId];
        if ($action !== null) {
            $filter = 'action = :action';
            $params[':action'] = $this->namespaced($action);
        } else {
            // Escaped: a plugin slug can hold `_`, which LIKE would read as any character.
            $filter = "action LIKE :action ESCAPE '\\'";
            $params[':action'] = addcslashes($this->prefix(), '\\%_') . '%';
        }
        if ($beforeId !== null) {
            $filter .= ' AND id < :before_id';
            $params[':before_id'] = $beforeId;
        }

        $stmt = $this->db->prepare(
            "SELECT id, action, target_type, target_id, metadata, actor_kind, actor_user_id, on_behalf_of_profile_id, created_at
               FROM audit_log
              WHERE tenant_id = :tenant_id AND {$filter}
              ORDER BY id DESC
              LIMIT " . min(max(1, $limit), self::MAX_ENTRIES)
        );
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): AuditEntry => $this->entryOf($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function entryOf(array $row): AuditEntry
    {
        $metadata = json_decode((string) ($row['metadata'] ?? '{}'), true);
        $targetType = $row['target_type'] === null ? null : (string) $row['target_type'];
        if ($targetType !== null && str_starts_with($targetType, $this->prefix())) {
            $targetType = substr($targetType, strlen($this->prefix()));
        }

        return new AuditEntry(
            (int) $row['id'],
            substr((string) $row['action'], strlen($this->prefix())),
            $targetType,
            $row['target_id'] === null ? null : (int) $row['target_id'],
            is_array($metadata) ? $metadata : [],
            $row['actor_kind'] === null ? null : (string) $row['actor_kind'],
            $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            $row['on_behalf_of_profile_id'] === null ? null : (int) $row['on_behalf_of_profile_id'],
            $row['created_at'] === null ? null : (string) $row['created_at'],
        );
    }

    private function namespaced(string $name): string
    {
        $canonical = $this->prefix() . $name;
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || strlen($canonical) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException("Bad audit name: {$name}");
        }

        return $canonical;
    }

    private function prefix(): string
    {
        return $this->slug . PluginNamespace::SEPARATOR;
    }

    private function workspace(): int
    {
        $tenantId = ($this->tenant)();
        if ($tenantId === null || $tenantId === 0) {
            throw new \RuntimeException('The audit trail needs a workspace, and none is in scope.');
        }

        return $tenantId;
    }
}
