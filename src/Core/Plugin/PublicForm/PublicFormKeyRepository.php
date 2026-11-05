<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\PublicForm;

use PDO;

/**
 * Keys that open a plugin's public form for one tenant (migration 217).
 *
 * The key is 256 bits from a CSPRNG, the same strength as a public form's slug
 * ({@see \Whity\Core\Form\PublicFormLink}). Only its hash is stored, so reading
 * this table doesn't open anybody's form. {@see self::findLive()} is the one read
 * with no tenant predicate: it is what resolves the tenant.
 */
final class PublicFormKeyRepository
{
    public const KEY_BYTES = 32;

    private const COLUMNS = 'id, tenant_id, plugin, form_name, key_hint, label, allowed_origins, created_by, created_at, revoked_at, revoked_by';

    public function __construct(private readonly PDO $db)
    {
    }

    public static function looksLikeKey(string $key): bool
    {
        return preg_match('/^[a-f0-9]{' . (self::KEY_BYTES * 2) . '}$/D', $key) === 1;
    }

    /**
     * Issue a key. The returned `key` is the only time it can be read.
     *
     * @param list<string> $origins
     * @return array<string, mixed>
     */
    public function issue(int $tenantId, string $plugin, string $form, string $label, array $origins, ?int $createdBy): array
    {
        $key = bin2hex(random_bytes(self::KEY_BYTES));
        $stmt = $this->db->prepare(
            'INSERT INTO public_form_keys (tenant_id, plugin, form_name, key_hash, key_hint, label, allowed_origins, created_by, created_at)
             VALUES (:tenant_id, :plugin, :form_name, :key_hash, :key_hint, :label, :allowed_origins, :created_by, CURRENT_TIMESTAMP)
             RETURNING id'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':plugin' => $plugin,
            ':form_name' => $form,
            ':key_hash' => hash('sha256', $key),
            ':key_hint' => substr($key, 0, 8),
            ':label' => $label,
            ':allowed_origins' => json_encode(array_values($origins), JSON_THROW_ON_ERROR),
            ':created_by' => $createdBy,
        ]);

        $row = $this->findForTenant($tenantId, (int) $stmt->fetchColumn());
        if ($row === null) {
            throw new \RuntimeException('The issued key could not be read back');
        }
        $row['key'] = $key;

        return $row;
    }

    /**
     * The live key behind a raw key, or null. Unknown and revoked look the same.
     *
     * @return array<string, mixed>|null
     */
    public function findLive(string $key): ?array
    {
        if (!self::looksLikeKey($key)) {
            return null;
        }
        // @tenant-guard-ignore: the anonymous public-form lookup — this read is what RESOLVES the tenant, from a 256-bit key under a global unique index (migration 217), on a path where the caller has no account. Everything afterwards binds the tenant_id it returns. Mirrors FormRepository::findByPublicSlug(); recorded against `public_form_keys` in TenantOwnedTables.
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM public_form_keys WHERE key_hash = :key_hash AND revoked_at IS NULL LIMIT 1');
        $stmt->execute([':key_hash' => hash('sha256', $key)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /** @return list<array<string, mixed>> Newest first, revoked keys included. */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM public_form_keys WHERE tenant_id = :tenant_id ORDER BY id DESC');
        $stmt->execute([':tenant_id' => $tenantId]);

        return array_values(array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return array<string, mixed>|null */
    public function findForTenant(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM public_form_keys WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /** @param list<string> $origins */
    public function update(int $tenantId, int $id, string $label, array $origins): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE public_form_keys SET label = :label, allowed_origins = :allowed_origins
              WHERE tenant_id = :tenant_id AND id = :id AND revoked_at IS NULL'
        );
        $stmt->execute([
            ':label' => $label,
            ':allowed_origins' => json_encode(array_values($origins), JSON_THROW_ON_ERROR),
            ':tenant_id' => $tenantId,
            ':id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    /** @return bool Whether this call revoked it. A key already revoked stays as it was. */
    public function revoke(int $tenantId, int $id, ?int $revokedBy): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE public_form_keys SET revoked_at = CURRENT_TIMESTAMP, revoked_by = :revoked_by
              WHERE tenant_id = :tenant_id AND id = :id AND revoked_at IS NULL'
        );
        $stmt->execute([':revoked_by' => $revokedBy, ':tenant_id' => $tenantId, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $origins = json_decode((string) ($row['allowed_origins'] ?? '[]'), true);
        $row['id'] = (int) $row['id'];
        $row['tenant_id'] = (int) $row['tenant_id'];
        $row['allowed_origins'] = is_array($origins) ? array_values(array_filter($origins, 'is_string')) : [];
        $row['created_by'] = $row['created_by'] === null ? null : (int) $row['created_by'];
        $row['revoked_by'] = $row['revoked_by'] === null ? null : (int) $row['revoked_by'];

        return $row;
    }
}
