<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use Whity\Core\Storage\StoredFiles;
use Whity\Storage\StorageDriverInterface;
use Whity\Storage\StorageException;
use Whity\Storage\StorageKey;

/**
 * Writes one uploaded form attachment to the configured storage backend, and
 * records where it went.
 *
 * WHY THIS IS NOT {@see \Whity\Core\Document\DocumentArtifactStore}
 * -----------------------------------------------------------------
 * That class's docblock says "nothing else in the DOCUMENT subsystem talks to a
 * driver", and this class does not contradict it: a form upload happens BEFORE
 * any document exists. `DocumentArtifactStore::put()` takes a `$documentId` and
 * builds a key under it, and at upload time there is no document to build one
 * under — the person is still filling in the form, and there may never be a
 * submission at all.
 *
 * What this class does NOT do is invent a second storage story. It obeys the
 * same four rules, deliberately and visibly:
 *
 *   1. keys come from {@see StorageKey::build()}, so they are tenant-prefixed
 *      and sanitised by the helper branding and documents already use;
 *   2. the file name is FRESH RANDOM per write, so a write can never compute the
 *      address of an object it might overwrite;
 *   3. the address is checked with `exists()` before the write, and a collision
 *      is a loud refusal rather than a silent replacement;
 *   4. `ContentType` is supplied AT WRITE TIME, because
 *      {@see \Whity\Storage\S3\S3StorageDriver::put()} stamps the type onto the
 *      object permanently and no read-time lookup can repair it.
 *
 * The driver it is handed is the platform's own
 * {@see \Whity\Storage\TenantRoutingStorageDriver}, so an entitled tenant's
 * uploads land in that tenant's bucket and everybody else's on the platform
 * default. There is no storage client here and no environment variable read.
 *
 * THE ROW IS WRITTEN AFTER THE BYTES, NEVER BEFORE
 * -------------------------------------------------
 * If the write fails, no row exists and the caller is told. If the row insert
 * fails, an object exists that nothing points at — and that object is exactly
 * what {@see FormUploadSweeper} deletes, except that with no row it will not be
 * found. That is the smaller failure and is why the order is this way round: an
 * unreferenced object costs storage, while a row pointing at an object that was
 * never written is an upload a person believes succeeded, which fails at submit
 * time hours later with nothing to re-try.
 */
final class FormUploadStore
{
    /**
     * The `{plugin}` segment of the storage key — the sub-directory form
     * attachments live under within a tenant's space, beside `branding` and
     * `documents`.
     */
    public const STORAGE_SEGMENT = 'form-uploads';

    /** Bytes of randomness in an upload's file name (32 hex characters). */
    public function __construct(
        private readonly StorageDriverInterface $storage,
        private readonly FormUploadRepository $uploads,
    ) {
    }

    /**
     * Store one upload and describe it back to the caller.
     *
     * The returned `storage_key` is what a `file` answer carries. It is not a
     * capability: nothing in the platform reads bytes from a key a request
     * supplied — see {@see FormUploadRepository::claim()} and migration 133.
     *
     * @param string $contentType The SNIFFED type from
     *        {@see FormUploadPolicy::assertAcceptable()}, never the client's.
     *
     * @return array{storage_key: string, content_type: string, byte_size: int,
     *               checksum_sha256: string, filename: ?string}
     *
     * @throws StorageException When the address is taken (which must not happen)
     *         or the write fails. No row is written in either case.
     */
    public function put(
        int $tenantId,
        int $formId,
        string $bytes,
        string $contentType,
        ?string $clientFilename,
        ?int $uploaderProfileId,
    ): array {
        // The one file store (WHIT-1159): fresh key, never overwritten, the
        // checksum over the bytes actually written.
        $stored = StoredFiles::store($this->storage, $tenantId, self::STORAGE_SEGMENT, (string) $formId, $bytes, $contentType, FormUploadPolicy::extensionFor($contentType));
        $key = $stored['storage_key'];

        $filename = StoredFiles::safeFilename($clientFilename);

        $this->uploads->record($tenantId, [
            'form_id'                => $formId,
            'storage_key'            => $key,
            'content_type'           => $contentType,
            'byte_size'              => strlen($bytes),
            // Computed here, over the bytes actually stored. It is kept on the
            // file's `stored_files` row, which the artifact shares when the upload is claimed,
            // so "is this the file the applicant sent" is answerable later
            // without trusting anything the applicant's client said.
            'checksum_sha256'        => $stored['checksum_sha256'],
            'client_filename'        => $filename,
            'uploaded_by_profile_id' => $uploaderProfileId,
        ]);

        return [
            'storage_key'     => $key,
            'content_type'    => $contentType,
            'byte_size'       => strlen($bytes),
            'checksum_sha256' => $stored['checksum_sha256'],
            'filename'        => $filename,
        ];
    }

    /**
     * A fresh, unused key: `tenants/{tenantId}/form-uploads/{formId}/{random}.{ext}`.
     *
     * The form id is a path segment rather than part of the file name so an
     * operator can see one form's pending attachments under one prefix — the
     * same shape {@see \Whity\Core\Document\DocumentArtifactStore} uses for a
     * document's history, for the same reason.
     */
}
