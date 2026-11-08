<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

use PDO;

/**
 * Data access for `route_signatures` (WHIT-922, #1726, migration 232).
 *
 * APPEND-ONLY, BY OMISSION, like the trail: there is `append()` and there are
 * reads. A signature is never edited; it is bound to the hash of one subject
 * version and never carries over to a new one.
 *
 * Each record is SEALED: {@see sealedMessage()} is the canonical text of every
 * fact on the record plus the hash of the trail entry it belongs to, and the
 * seal is the instance's Ed25519 signature over it ({@see InstanceSealKeys}).
 *
 * TENANT-OWNED: every statement binds a literal `tenant_id` predicate.
 */
final class SignatureRepository
{
    /** The facts the seal covers, besides the trail entry's hash. */
    public const SEALED_FIELDS = [
        'tenant_id', 'document_id', 'route_id', 'event_id', 'step_id', 'subject_type', 'subject_id',
        'subject_version', 'content_hash', 'signer_profile_id', 'signer_name', 'signer_email',
        'capacity', 'on_behalf_of', 'confirmed_by', 'signed_at', 'ip_address', 'user_agent',
        'consent_version', 'appearance_hash',
    ];

    private const INTS = [
        'tenant_id', 'document_id', 'route_id', 'event_id', 'step_id', 'subject_id', 'subject_version',
        'signer_profile_id',
    ];

    private const COLUMNS = 's.id, s.tenant_id, s.document_id, s.route_id, s.event_id, s.step_id,
        s.subject_type, s.subject_id, s.subject_version, s.content_hash, s.signer_profile_id,
        s.signer_name, s.signer_email, s.capacity, s.on_behalf_of, s.confirmed_by, s.signed_at,
        s.ip_address, s.user_agent, s.consent_version, s.appearance, s.appearance_hash,
        s.record_hash, s.seal, s.seal_key_id, e.entry_hash AS event_entry_hash';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The canonical text a record's seal is made over.
     *
     * @param array<string, mixed> $record
     */
    public static function sealedMessage(array $record, ?string $eventEntryHash): string
    {
        $facts = [];
        foreach (self::SEALED_FIELDS as $field) {
            $value = $record[$field] ?? null;
            if ($value === null) {
                $facts[$field] = null;
            } elseif (in_array($field, self::INTS, true)) {
                $facts[$field] = (int) $value;
            } elseif ($field === 'signed_at') {
                $facts[$field] = substr(str_replace('T', ' ', (string) $value), 0, 19);
            } else {
                $facts[$field] = (string) $value;
            }
        }
        $facts['event_entry_hash'] = $eventEntryHash;
        ksort($facts);

        return 'whity-signature-v1' . "\n"
            . (string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Append one sealed record and return its id. The only write.
     *
     * @param array<string, mixed> $record Every field in {@see SEALED_FIELDS}, plus
     *        `appearance`, `record_hash`, `seal` and `seal_key_id`.
     */
    public function append(array $record): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO route_signatures
                 (tenant_id, document_id, route_id, event_id, step_id, subject_type, subject_id,
                  subject_version, content_hash, signer_profile_id, signer_name, signer_email,
                  capacity, on_behalf_of, confirmed_by, signed_at, ip_address, user_agent,
                  consent_version, appearance, appearance_hash, record_hash, seal, seal_key_id)
             VALUES (:tenant_id, :document_id, :route_id, :event_id, :step_id, :subject_type, :subject_id,
                     :subject_version, :content_hash, :signer_profile_id, :signer_name, :signer_email,
                     :capacity, :on_behalf_of, :confirmed_by, :signed_at, :ip_address, :user_agent,
                     :consent_version, :appearance, :appearance_hash, :record_hash, :seal, :seal_key_id)'
        );
        $params = [];
        foreach (array_merge(self::SEALED_FIELDS, ['appearance', 'record_hash', 'seal', 'seal_key_id']) as $field) {
            $params[':' . $field] = $record[$field] ?? null;
        }
        $stmt->execute($params);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Every signature on a document, oldest first, across every version.
     *
     * @return list<array<string, mixed>>
     */
    public function listForDocument(int $tenantId, int $documentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM route_signatures s
               JOIN document_route_events e ON e.id = s.event_id AND e.tenant_id = s.tenant_id
              WHERE s.tenant_id = :tenant_id AND s.document_id = :document_id
              ORDER BY s.id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * The signatures that stand on the subject's content as it is now: those
     * bound to `$contentHash`, the hash of its current snapshot. A signature
     * on other content is not carried over.
     *
     * @return list<array<string, mixed>>
     */
    public function listForContent(int $tenantId, string $subjectType, int $subjectId, string $contentHash): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM route_signatures s
               JOIN document_route_events e ON e.id = s.event_id AND e.tenant_id = s.tenant_id
              WHERE s.tenant_id = :tenant_id AND s.subject_type = :subject_type AND s.subject_id = :subject_id
                AND s.content_hash = :content_hash
              ORDER BY s.id ASC'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
            ':content_hash' => $contentHash,
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[$key] = in_array($key, self::INTS, true) || $key === 'id'
                ? ($value === null ? null : (int) $value)
                : ($value === null ? null : (string) $value);
        }

        return $out;
    }
}
