<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use Whity\Core\Storage\StoredFiles;
use Whity\Storage\StorageDriverInterface;
use Whity\Storage\StorageException;
use Whity\Storage\StorageKey;

/**
 * Writes a rendered document to the configured storage backend, once
 * (#947 item 1).
 *
 * NO SECOND STORAGE PATH
 * ----------------------
 * This class owns key construction and the write, and nothing else in the
 * document subsystem talks to a driver. The driver it is handed is the
 * platform's own — {@see \Whity\Storage\TenantRoutingStorageDriver}, which
 * resolves per tenant so an entitled tenant's own bucket is used and everyone
 * else transparently gets the platform default. Keys go through
 * {@see StorageKey}, so they are tenant-prefixed and sanitised by the same
 * helper branding already uses, and the routing driver can read the tenant back
 * out of the key on a context-less path. Inventing a documents-specific path or
 * key format would have given the platform a second storage story to keep
 * correct, and the one that drifts is always the newer one.
 *
 * IMMUTABILITY: A KEY IS CLAIMED ONCE
 * -----------------------------------
 * Every artifact gets a FRESH RANDOM key, and the object is written only after
 * the store has confirmed nothing lives there. Two things follow:
 *
 *  - a re-render cannot overwrite the artifact it supersedes, because it never
 *    computes that artifact's address; and
 *  - a key derived from something predictable — the document id, a counter, a
 *    timestamp — could collide across concurrent renders and silently replace
 *    one of them, which is the failure this whole table exists to prevent. The
 *    randomness is not for secrecy (the key is never public and every read goes
 *    through an RBAC-checked route); it is what makes "write once" true without
 *    a lock.
 *
 * The existence check is a real round trip and is worth it here: it runs once
 * per PERSISTED render, immediately after a headless-Chromium render that took
 * seconds, so it is not measurable against the work it protects. Skipping it
 * would leave the guarantee resting on 128 bits of luck and on the driver's
 * put() semantics, which overwrite by design on both backends.
 *
 * The database's `UNIQUE (storage_key)` is the independent second half. Both
 * can fail on their own, and neither is load-bearing for the other.
 */
final class DocumentArtifactStore
{
    /**
     * The `{plugin}` segment of the storage key — the sub-directory documents
     * live under within a tenant's space, beside `branding`.
     */
    public const STORAGE_SEGMENT = 'documents';

    /** Bytes of randomness in an artifact's file name (32 hex characters). */
    private StorageDriverInterface $storage;

    public function __construct(StorageDriverInterface $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Write one artifact and describe what was written.
     *
     * @param string $extension The bare file extension for the payload (no dot).
     *
     * @throws StorageException When the address is already taken (which must not
     *         happen and is therefore not silently tolerated) or the write fails.
     *         The caller has NOT yet recorded a row at that point, so nothing
     *         claims an object that does not exist.
     */
    public function put(
        int $tenantId,
        int $documentId,
        string $bytes,
        string $contentType,
        string $extension = 'pdf',
    ): StoredArtifact {
        // The one file store (WHIT-1159): a stored artifact is evidence, so the
        // key is fresh and an existing object is never overwritten.
        $stored = StoredFiles::store($this->storage, $tenantId, self::STORAGE_SEGMENT, (string) $documentId, $bytes, $contentType, $extension);

        return new StoredArtifact(
            storageKey: $stored['storage_key'],
            contentType: $contentType,
            byteSize: $stored['byte_size'],
            checksumSha256: $stored['checksum_sha256'],
        );
    }

    /**
     * Read an artifact's bytes back.
     *
     * Here rather than in the handler so that every access to a document object
     * goes through the same class that wrote it — the handler holds the record,
     * never a driver.
     *
     * @throws StorageException When the object is missing or unreadable.
     */
    public function get(string $storageKey): string
    {
        return $this->storage->get($storageKey);
    }

    /**
     * A fresh, unused key: `tenants/{tenantId}/documents/{documentId}/{random}.{ext}`.
     *
     * The document id is a path segment rather than part of the file name so an
     * operator (or a retention sweep) can see a document's whole history in one
     * place on a local backend and under one prefix on an object store.
     */
}
