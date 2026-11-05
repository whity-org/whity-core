<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;

/**
 * Data-access layer for `form_drafts` (migration 210). All SQL touching the table lives
 * here. Every statement binds the tenant and the profile, because a draft belongs to
 * one person and has no reader but them.
 *
 * Stateless apart from the injected handle — worker-safe.
 */
final class FormDraftRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{form_id: int, data: array<string, mixed>|\stdClass, step: int|null, updated_at: string}|null
     */
    public function find(int $tenantId, int $profileId, int $formId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT form_id, data, step, updated_at
               FROM form_drafts
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND form_id = :form_id
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId, ':form_id' => $formId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /**
     * This person's drafts on forms that are still open, most recent first.
     *
     * @return list<array<string, mixed>>
     */
    public function listForProfile(int $tenantId, int $profileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.form_id, d.step, d.updated_at, f.name AS form_name, f.open_to_members
               FROM form_drafts d
               JOIN forms f ON f.id = d.form_id AND f.tenant_id = d.tenant_id
              WHERE d.tenant_id = :tenant_id AND d.profile_id = :profile_id AND f.status = :status
              ORDER BY d.updated_at DESC, d.id DESC
              LIMIT 200'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId, ':status' => FormStatus::PUBLISHED]);

        return array_map(
            static fn (array $row): array => [
                'form_id' => (int) $row['form_id'],
                'form_name' => LocalizedLabel::decode(isset($row['form_name']) ? (string) $row['form_name'] : null),
                'step' => $row['step'] === null ? null : (int) $row['step'],
                'updated_at' => (string) $row['updated_at'],
                'open_to_members' => \Whity\Core\Db\DbBool::of($row['open_to_members'] ?? false),
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(int $tenantId, int $profileId, int $formId, array $data, ?int $step): void
    {
        $json = json_encode($data === [] ? new \stdClass() : $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->prepare(
            'INSERT INTO form_drafts (tenant_id, form_id, profile_id, data, step, updated_at)
             VALUES (:tenant_id, :form_id, :profile_id, :data, :step, NOW())
             ON CONFLICT (form_id, profile_id)
             DO UPDATE SET data = excluded.data, step = excluded.step, updated_at = NOW()'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':form_id' => $formId,
            ':profile_id' => $profileId,
            ':data' => $json === false ? '{}' : $json,
            ':step' => $step,
        ]);
    }

    public function delete(int $tenantId, int $profileId, int $formId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM form_drafts WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND form_id = :form_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId, ':form_id' => $formId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{form_id: int, data: array<string, mixed>|\stdClass, step: int|null, updated_at: string}
     */
    private static function normalize(array $row): array
    {
        $decoded = json_decode((string) ($row['data'] ?? '{}'), true);

        return [
            'form_id' => (int) $row['form_id'],
            'data' => is_array($decoded) && $decoded !== [] ? $decoded : new \stdClass(),
            'step' => $row['step'] === null ? null : (int) $row['step'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
