<?php

declare(strict_types=1);

namespace Whity\Core\Storage;

use PDO;
use Whity\Storage\StorageDriverInterface;
use Whity\Storage\StorageException;
use Whity\Storage\StorageKey;

/**
 * The one file store (merge map plumbing, WHIT-1159; migration 298).
 *
 * Form uploads, document source files, document artifacts and chat
 * attachments each kept a file the same way: a random key under the workspace,
 * never overwritten, the bytes written with their type, a SHA-256 over what was
 * stored, and a row describing it. That now happens here, once:
 *
 * - {@see store()} writes the bytes: a 128-bit random name under
 *   `<segment>/<owner>`, refused if the key exists (a stored file is evidence
 *   and is never replaced), the type given at write time (S3 keeps it on the
 *   object), and the checksum over the bytes actually written.
 * - {@see remember()} records the file in `stored_files`, one row per stored
 *   object, whichever feature owns it, and returns its id for the owner's row
 *   (`stored_file_id`). One place answers "what files does this workspace
 *   hold, how big, since when".
 *
 * Each feature keeps its own table for what only it knows (the form, the
 * document, the chat message, a voice note's length).
 */
final class StoredFiles
{
    public const FORM_UPLOAD = 'form_upload';
    public const DOCUMENT_SOURCE = 'document_source';
    public const DOCUMENT_ARTIFACT = 'document_artifact';
    public const CHAT_ATTACHMENT = 'chat_attachment';

    public const KINDS = [self::FORM_UPLOAD, self::DOCUMENT_SOURCE, self::DOCUMENT_ARTIFACT, self::CHAT_ATTACHMENT];

    private const KEY_ENTROPY_BYTES = 16;

    private function __construct()
    {
    }

    /**
     * Write new bytes under `<segment>/<owner>` with a fresh random name.
     *
     * @return array{storage_key: string, content_type: string, byte_size: int, checksum_sha256: string}
     *
     * @throws StorageException When the key is taken (a broken random source) or the write fails.
     */
    public static function store(
        StorageDriverInterface $storage,
        int $tenantId,
        string $segment,
        string $owner,
        string $bytes,
        string $contentType,
        string $extension,
    ): array {
        $suffix = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?? '';
        $name = bin2hex(random_bytes(self::KEY_ENTROPY_BYTES)) . ($suffix !== '' ? '.' . $suffix : '');
        $key = StorageKey::build($tenantId, $segment . '/' . $owner, $name);

        if ($storage->exists($key)) {
            // Unreachable short of a broken random source, and loud rather
            // than a silent overwrite of something people rely on.
            throw new StorageException('Refusing to overwrite a stored file; a stored file is immutable.');
        }
        $storage->put($key, $bytes, ['ContentType' => $contentType]);

        return [
            'storage_key' => $key,
            'content_type' => $contentType,
            'byte_size' => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes),
        ];
    }

    /**
     * Record a stored object and return its `stored_files` id. The same object
     * recorded twice (an upload claimed onto a document) is one row.
     *
     * @param array{storage_key: string, content_type: string, byte_size: int, checksum_sha256?: ?string} $file
     */
    public static function remember(PDO $db, int $tenantId, string $kind, array $file, ?string $filename, ?int $createdBy): int
    {
        $existing = $db->prepare('SELECT id FROM stored_files WHERE tenant_id = :tenant_id AND storage_key = :key');
        $existing->execute([':tenant_id' => $tenantId, ':key' => $file['storage_key']]);
        $id = $existing->fetchColumn();
        $existing->closeCursor();
        if ($id !== false) {
            return (int) $id;
        }

        $stmt = $db->prepare(
            'INSERT INTO stored_files (tenant_id, kind, storage_key, content_type, byte_size, checksum_sha256, filename, created_by_profile_id, created_at)
             VALUES (:tenant_id, :kind, :key, :type, :size, :checksum, :filename, :by, CURRENT_TIMESTAMP)
             RETURNING id'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':kind' => $kind,
            ':key' => $file['storage_key'],
            ':type' => $file['content_type'],
            ':size' => $file['byte_size'],
            ':checksum' => $file['checksum_sha256'] ?? null,
            ':filename' => $filename,
            ':by' => $createdBy,
        ]);
        $newId = (int) $stmt->fetchColumn();
        $stmt->closeCursor();

        // The workspace's stored bytes (section 33 §5); never throws.
        \Whity\Core\Usage\StorageUsage::added($db, $tenantId, (int) $file['byte_size']);

        return $newId;
    }

    /** Forget a stored object's row once the owner has deleted the bytes. */
    public static function forget(PDO $db, int $tenantId, string $storageKey): void
    {
        $delete = $db->prepare('DELETE FROM stored_files WHERE tenant_id = :tenant_id AND storage_key = :key RETURNING byte_size');
        $delete->execute([':tenant_id' => $tenantId, ':key' => $storageKey]);
        foreach ($delete->fetchAll(PDO::FETCH_COLUMN) as $bytes) {
            \Whity\Core\Usage\StorageUsage::removed($db, $tenantId, (int) $bytes);
        }
    }

    /**
     * A person's own file name, safe to show and to send in a download header:
     * no path, no control characters, at most 255 characters. Null for nothing usable.
     */
    public static function safeFilename(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $name = basename(str_replace('\\', '/', $raw));
        $name = trim(preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '');

        return $name === '' || $name === '.' || $name === '..' ? null : mb_substr($name, 0, 255);
    }
}
