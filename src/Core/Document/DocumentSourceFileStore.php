<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;
use Whity\Core\Form\FormUploadPolicy;
use Whity\Storage\StorageDriverInterface;
use Whity\Core\Storage\StoredFiles;
use Whity\Storage\StorageException;
use Whity\Storage\StorageKey;

/**
 * Images and files placed in an editor document (editor M3, E.13; migration
 * 161): writes the bytes to the tenant's storage and keeps the row that is the
 * only way back to them.
 *
 * It follows {@see \Whity\Core\Form\FormUploadStore}'s rules on purpose rather
 * than inventing a second storage story: keys come from {@see StorageKey::build()},
 * the file name is fresh randomness so no part of the address comes from the
 * client, an existing key is a loud refusal rather than an overwrite, the
 * content type is the SNIFFED one and is set at write time, and the row is
 * written after the bytes (an unreferenced object costs storage; a row with no
 * object is an image that silently never loads).
 *
 * What may be stored is {@see FormUploadPolicy}'s list (PDF, PNG, JPEG, sniffed
 * from the leading bytes, 10 MiB): one policy for every upload a person makes.
 * SVG is deliberately not on it, because an SVG served from our origin is a
 * script.
 */
final class DocumentSourceFileStore
{
    public const STORAGE_SEGMENT = 'document-sources';
    public function __construct(
        private readonly StorageDriverInterface $storage,
        private readonly PDO $db,
    ) {
    }

    /**
     * Store one upload into a document and return its row.
     *
     * @param string $contentType The SNIFFED type from FormUploadPolicy::assertAcceptable().
     * @return array{id: int, content_type: string, byte_size: int, checksum_sha256: string, filename: ?string}
     * @throws StorageException When the address is taken or the write fails; no row is written.
     */
    public function put(int $tenantId, int $sourceId, string $bytes, string $contentType, ?string $clientFilename, ?int $uploaderProfileId): array
    {
        // The one file store (WHIT-1159).
        $stored = StoredFiles::store($this->storage, $tenantId, self::STORAGE_SEGMENT, (string) $sourceId, $bytes, $contentType, FormUploadPolicy::extensionFor($contentType));
        $filename = StoredFiles::safeFilename($clientFilename);
        $checksum = $stored['checksum_sha256'];
        $storedFileId = StoredFiles::remember($this->db, $tenantId, StoredFiles::DOCUMENT_SOURCE, $stored, $filename, $uploaderProfileId);
        $stmt = $this->db->prepare(
            'INSERT INTO document_source_files
                 (tenant_id, source_id, uploaded_by_profile_id, stored_file_id, created_at)
             VALUES (:tenant_id, :source_id, :uploaded_by, :stored_file_id, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':source_id' => $sourceId,
            ':uploaded_by' => $uploaderProfileId,
            ':stored_file_id' => $storedFileId,
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'content_type' => $contentType,
            'byte_size' => strlen($bytes),
            'checksum_sha256' => $checksum,
            'filename' => $filename,
        ];
    }

    /**
     * One file of one document of one tenant. A file id alone is never a
     * capability: it is bound to the document the caller was already allowed
     * to see.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $fileId, int $sourceId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id, f.storage_key, f.content_type, f.byte_size, f.checksum_sha256, f.filename
               FROM document_source_files s
               JOIN stored_files f ON f.id = s.stored_file_id AND f.tenant_id = s.tenant_id
              WHERE s.id = :id AND s.source_id = :source_id AND s.tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $fileId, ':source_id' => $sourceId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @throws StorageException When the object is missing or unreadable.
     */
    public function bytes(string $storageKey): string
    {
        return $this->storage->get($storageKey);
    }
}
