<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

use PDO;
use RuntimeException;
use Whity\Core\Security\EncryptedSecretStore;

/**
 * The instance's Ed25519 key pairs that seal signature records (WHIT-922,
 * #1726, migration 228).
 *
 * One current key per instance, made the first time something is sealed. The
 * private key is stored encrypted with the platform encryption key
 * ({@see EncryptedSecretStore}, the same store sign-in provider secrets use);
 * the public key is published at `GET /api/signing-keys` so anyone holding a
 * signature record can check its seal. A retired key stays published, so
 * records sealed with it still check.
 *
 * libsodium only (`sodium_crypto_sign_*`).
 *
 * Stateless apart from the injected handles — worker-safe.
 */
final class InstanceSealKeys
{
    public const ALGORITHM = 'Ed25519';

    private const CREATE_LOCK = 922002;

    public function __construct(
        private readonly PDO $db,
        private readonly EncryptedSecretStore $secrets,
    ) {
    }

    /**
     * Seal a message with the current key, making the key first if there is none.
     *
     * @return array{key_id: string, seal: string} The seal is base64.
     */
    public function seal(string $message): array
    {
        $key = $this->currentKey();
        $secret = sodium_base642bin($this->secrets->decrypt($key['secret_key']), SODIUM_BASE64_VARIANT_ORIGINAL);
        if ($secret === '' || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The instance signing key could not be read.');
        }
        try {
            $signature = sodium_crypto_sign_detached($message, $secret);
        } finally {
            sodium_memzero($secret);
        }

        return [
            'key_id' => $key['key_id'],
            'seal' => sodium_bin2base64($signature, SODIUM_BASE64_VARIANT_ORIGINAL),
        ];
    }

    /** Whether a seal checks against the published key it names. */
    public function verify(string $message, string $seal, string $keyId): bool
    {
        $publicKey = $this->publicKey($keyId);
        if ($publicKey === null) {
            return false;
        }

        return self::verifyWith($message, $seal, $publicKey);
    }

    /** Check a seal against a base64 public key, as anyone holding the published key can. */
    public static function verifyWith(string $message, string $seal, string $publicKeyBase64): bool
    {
        try {
            $signature = sodium_base642bin($seal, SODIUM_BASE64_VARIANT_ORIGINAL);
            $publicKey = sodium_base642bin($publicKeyBase64, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            return false;
        }
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }

    /** The base64 public key with this id, or null. */
    public function publicKey(string $keyId): ?string
    {
        // @tenant-guard-ignore: instance_signing_keys is a sanctioned global table (WHIT-922)
        $stmt = $this->db->prepare('SELECT public_key FROM instance_signing_keys WHERE key_id = :key_id');
        $stmt->execute([':key_id' => $keyId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    /**
     * Every key the instance has sealed with, for publishing. Never the private half.
     *
     * @return list<array{key_id: string, algorithm: string, public_key: string, created_at: string, retired_at: ?string}>
     */
    public function published(): array
    {
        // @tenant-guard-ignore: instance_signing_keys is a sanctioned global table (WHIT-922)
        $stmt = $this->db->query(
            'SELECT key_id, algorithm, public_key, created_at, retired_at FROM instance_signing_keys ORDER BY id ASC'
        );
        $out = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'key_id' => (string) $row['key_id'],
                'algorithm' => (string) $row['algorithm'],
                'public_key' => (string) $row['public_key'],
                'created_at' => (string) $row['created_at'],
                'retired_at' => $row['retired_at'] !== null ? (string) $row['retired_at'] : null,
            ];
        }

        return $out;
    }

    /** @return array{key_id: string, secret_key: string} */
    private function currentKey(): array
    {
        $current = $this->readCurrent();
        if ($current !== null) {
            return $current;
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $lock = $this->db->prepare('SELECT pg_advisory_xact_lock(:space, 0)');
                $lock->execute([':space' => self::CREATE_LOCK]);
                $lock->closeCursor();
            }
            $current = $this->readCurrent() ?? $this->create();
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $current;
    }

    /** @return array{key_id: string, secret_key: string}|null */
    private function readCurrent(): ?array
    {
        // @tenant-guard-ignore: instance_signing_keys is a sanctioned global table (WHIT-922)
        $stmt = $this->db->query(
            'SELECT key_id, secret_key FROM instance_signing_keys WHERE retired_at IS NULL ORDER BY id ASC LIMIT 1'
        );
        $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_ASSOC);
        if ($stmt !== false) {
            $stmt->closeCursor();
        }

        return is_array($row)
            ? ['key_id' => (string) $row['key_id'], 'secret_key' => (string) $row['secret_key']]
            : null;
    }

    /** @return array{key_id: string, secret_key: string} */
    private function create(): array
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        sodium_memzero($pair);

        $keyId = substr(hash('sha256', $public), 0, 16);
        $encrypted = $this->secrets->encrypt(sodium_bin2base64($secret, SODIUM_BASE64_VARIANT_ORIGINAL));
        sodium_memzero($secret);

        // @tenant-guard-ignore: instance_signing_keys is a sanctioned global table (WHIT-922)
        $stmt = $this->db->prepare(
            'INSERT INTO instance_signing_keys (key_id, algorithm, public_key, secret_key, created_at)
             VALUES (:key_id, :algorithm, :public_key, :secret_key, NOW())'
        );
        $stmt->execute([
            ':key_id' => $keyId,
            ':algorithm' => self::ALGORITHM,
            ':public_key' => sodium_bin2base64($public, SODIUM_BASE64_VARIANT_ORIGINAL),
            ':secret_key' => $encrypted,
        ]);

        $made = $this->readCurrent();
        if ($made === null) {
            throw new RuntimeException('The instance signing key could not be read back.');
        }

        return $made;
    }
}
