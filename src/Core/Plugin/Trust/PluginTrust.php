<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Trust;

use PDO;
use Whity\Core\Audit\AuditLogger;

/**
 * Verified marks and the revocation list: checks signatures, and is the only
 * writer of `plugin_version_marks` and `plugin_revocation_state`
 * (docs/design/VERIFIED-PLUGINS-SECURITY.md, sections 4 and 5).
 *
 * Verification only: it holds public keys and can't sign anything. Signed
 * documents are an envelope of `key`, `payload` and `signature`, all base64, and
 * the signature covers the document type and the payload's exact bytes, so an
 * attestation can't pass as a list or the reverse.
 */
final class PluginTrust
{
    public const ATTESTATION = 'whity.attestation/1';
    public const REVOCATIONS = 'whity.revocations/1';

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock Now, as a timestamp; tests move it. */
    public function __construct(
        private readonly PDO $pdo,
        private readonly TrustedKeys $keys,
        private readonly ?AuditLogger $audit = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Check a signed statement that this exact package passed review.
     *
     * A signature alone isn't enough: it must name this plugin, version and
     * package, be in date, not be revoked, and the revocation list must be fresh.
     *
     * @return array{ok: bool, reason: string}
     */
    public function checkAttestation(string $envelope, string $plugin, string $version, string $packageSha256): array
    {
        if ($this->keys->count() === 0) {
            return ['ok' => false, 'reason' => 'no_keys'];
        }
        $payload = $this->verified($envelope, self::ATTESTATION);
        if ($payload === null) {
            return ['ok' => false, 'reason' => 'bad_signature'];
        }
        if (
            ($payload['plugin'] ?? null) !== $plugin
            || ($payload['version'] ?? null) !== $version
            || !is_string($payload['sha256'] ?? null)
            || !hash_equals($payload['sha256'], $packageSha256)
        ) {
            return ['ok' => false, 'reason' => 'mismatch'];
        }
        if (!is_int($payload['expires_at'] ?? null) || $payload['expires_at'] <= ($this->clock)()) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        if ($this->isStale()) {
            return ['ok' => false, 'reason' => 'list_stale'];
        }
        if ($this->revocationOf($plugin, $version) !== null) {
            return ['ok' => false, 'reason' => 'revoked'];
        }

        return ['ok' => true, 'reason' => 'verified'];
    }

    /**
     * Record the mark, checking the attestation again here rather than trusting
     * the caller: this is the only way a mark comes to exist.
     */
    public function grant(
        string $envelope,
        string $plugin,
        string $version,
        string $packageSha256,
        string $manifestHash,
        ?int $profileId,
        ?int $tenantId = null,
    ): bool {
        $check = $this->checkAttestation($envelope, $plugin, $version, $packageSha256);
        if (!$check['ok']) {
            $this->audit?->record('plugin.mark.refused', [
                'tenant_id' => $tenantId,
                'target_type' => 'plugin',
                'metadata' => ['plugin' => $plugin, 'version' => $version, 'reason' => $check['reason']],
            ]);

            return false;
        }

        $this->pdo->prepare(
            "INSERT INTO plugin_version_marks
                (plugin_name, version, package_sha256, manifest_hash, source, attestation, granted_by_profile_id, granted_at)
             VALUES (?, ?, ?, ?, 'whity', ?, ?, NOW())
             ON CONFLICT (plugin_name, version, manifest_hash) DO NOTHING"
        )->execute([$plugin, $version, $packageSha256, $manifestHash, $envelope, $profileId]);

        $this->audit?->record('plugin.mark.granted', [
            'tenant_id' => $tenantId,
            'target_type' => 'plugin',
            'metadata' => ['plugin' => $plugin, 'version' => $version, 'sha256' => $packageSha256, 'source' => 'whity'],
        ]);

        return true;
    }

    /** Whether this exact manifest of this version carries a live mark. */
    public function isVerified(string $plugin, string $version, string $manifestHash): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM plugin_version_marks
              WHERE plugin_name = ? AND version = ? AND manifest_hash = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$plugin, $version, $manifestHash]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Accept a newer signed revocation list and act on it: marks it names go, and
     * a `kill` entry also turns the plugin off in every workspace, which reaches
     * the next request because workspace plugins are read per request.
     *
     * @return array{ok: bool, reason: string}
     */
    public function applyRevocationList(string $envelope): array
    {
        $list = $this->verified($envelope, self::REVOCATIONS);
        if ($list === null) {
            return $this->listRefused('bad_signature');
        }
        $sequence = $list['sequence'] ?? null;
        $issuedAt = $list['issued_at'] ?? null;
        $expiresAt = $list['expires_at'] ?? null;
        $revoked = $list['revoked'] ?? null;
        if (!is_int($sequence) || !is_int($issuedAt) || !is_int($expiresAt) || !is_array($revoked)) {
            return $this->listRefused('malformed');
        }
        $current = $this->state();
        if ($current !== null && $sequence <= (int) $current['sequence']) {
            return $this->listRefused('replay');
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($revoked as $entry) {
                if (!is_array($entry) || !is_string($entry['plugin'] ?? null)) {
                    continue;
                }
                $this->enforce(
                    $entry['plugin'],
                    is_string($entry['version'] ?? null) ? $entry['version'] : null,
                    ($entry['action'] ?? null) === 'kill',
                    is_string($entry['reason'] ?? null) ? $entry['reason'] : ''
                );
            }
            $this->pdo->exec('DELETE FROM plugin_revocation_state');
            $this->pdo->prepare(
                'INSERT INTO plugin_revocation_state (id, sequence, issued_at, expires_at, body, applied_at)
                 VALUES (1, ?, ?, ?, ?, NOW())'
            )->execute([$sequence, $issuedAt, $expiresAt, $envelope]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit?->record('plugin.revocations.applied', [
            'tenant_id' => 0,
            'target_type' => 'plugin',
            'metadata' => ['sequence' => $sequence, 'entries' => count($revoked)],
        ]);

        return ['ok' => true, 'reason' => 'applied'];
    }

    /** The accepted list is missing or past its expiry: nothing new activates. */
    public function isStale(): bool
    {
        $state = $this->state();

        return $state === null || (int) $state['expires_at'] <= ($this->clock)();
    }

    /** A `kill` entry stands against this version, so no one may turn it back on. */
    public function isKilled(string $plugin, string $version): bool
    {
        return ($this->revocationOf($plugin, $version)['action'] ?? null) === 'kill';
    }

    /** @return array{keys: int, sequence: int|null, issued_at: int|null, expires_at: int|null, stale: bool} */
    public function status(): array
    {
        $state = $this->state();

        return [
            'keys' => $this->keys->count(),
            'sequence' => $state === null ? null : (int) $state['sequence'],
            'issued_at' => $state === null ? null : (int) $state['issued_at'],
            'expires_at' => $state === null ? null : (int) $state['expires_at'],
            'stale' => $this->isStale(),
        ];
    }

    /**
     * The payload of a signed document, or null unless a pinned key signed it.
     *
     * @return array<string, mixed>|null
     */
    private function verified(string $envelope, string $format): ?array
    {
        $decoded = json_decode($envelope, true);
        if (!is_array($decoded) || !is_string($decoded['key'] ?? null)) {
            return null;
        }
        $key = $this->keys->match($decoded['key']);
        $payload = is_string($decoded['payload'] ?? null) ? base64_decode($decoded['payload'], true) : false;
        $signature = is_string($decoded['signature'] ?? null) ? base64_decode($decoded['signature'], true) : false;
        if ($key === null || $key === '' || $payload === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }
        if (!sodium_crypto_sign_verify_detached($signature, $format . "\n" . $payload, $key)) {
            return null;
        }
        $body = json_decode($payload, true);

        return is_array($body) && ($body['format'] ?? null) === $format ? $body : null;
    }

    private function enforce(string $plugin, ?string $version, bool $kill, string $reason): void
    {
        $mark = 'UPDATE plugin_version_marks SET revoked_at = NOW(), revoke_reason = ?
                  WHERE plugin_name = ? AND revoked_at IS NULL';
        $args = [$reason, $plugin];
        if ($version !== null) {
            $mark .= ' AND version = ?';
            $args[] = $version;
        }
        $this->pdo->prepare($mark)->execute($args);

        $this->audit?->record('plugin.mark.revoked', [
            'tenant_id' => 0,
            'target_type' => 'plugin',
            'metadata' => ['plugin' => $plugin, 'version' => $version, 'kill' => $kill, 'reason' => $reason],
        ]);

        if (!$kill) {
            return;
        }

        if ($version === null) {
            // @tenant-guard-ignore: a kill is about the version, wherever it runs, so every workspace on purpose; only a list signed by a pinned key gets here
            $stmt = $this->pdo->prepare('UPDATE tenant_plugins SET enabled = :off, updated_at = NOW() WHERE plugin_key = :plugin');
        } else {
            // @tenant-guard-ignore: a kill is about the version, wherever it runs, so every workspace on purpose; only a list signed by a pinned key gets here
            $stmt = $this->pdo->prepare('UPDATE tenant_plugins SET enabled = :off, updated_at = NOW() WHERE plugin_key = :plugin AND active_version_id IN (SELECT id FROM plugin_manifest_versions WHERE plugin_key = :plugin2 AND version = :version)');
        }
        $stmt->bindValue(':off', false, PDO::PARAM_BOOL);
        $stmt->bindValue(':plugin', $plugin);
        if ($version !== null) {
            $stmt->bindValue(':plugin2', $plugin);
            $stmt->bindValue(':version', $version);
        }
        $stmt->execute();
    }

    /** @return array<string, mixed>|null The list entry naming this version. */
    private function revocationOf(string $plugin, string $version): ?array
    {
        $state = $this->state();
        if ($state === null) {
            return null;
        }
        $outer = json_decode((string) $state['body'], true);
        $payload = is_array($outer) && is_string($outer['payload'] ?? null) ? base64_decode($outer['payload'], true) : false;
        $list = $payload === false ? null : json_decode($payload, true);
        foreach (is_array($list) && is_array($list['revoked'] ?? null) ? $list['revoked'] : [] as $entry) {
            if (
                is_array($entry)
                && ($entry['plugin'] ?? null) === $plugin
                && (($entry['version'] ?? null) === null || $entry['version'] === $version)
            ) {
                return $entry;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function state(): ?array
    {
        $stmt = $this->pdo->prepare('SELECT sequence, issued_at, expires_at, body FROM plugin_revocation_state WHERE id = 1');
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array{ok: false, reason: string} */
    private function listRefused(string $reason): array
    {
        $this->audit?->record('plugin.revocations.refused', [
            'tenant_id' => 0,
            'target_type' => 'plugin',
            'metadata' => ['reason' => $reason],
        ]);

        return ['ok' => false, 'reason' => $reason];
    }
}
