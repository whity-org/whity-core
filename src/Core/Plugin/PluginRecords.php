<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use PDO;

/**
 * The deployment's own record of its code plugins: where each came from, what
 * access it was installed with, any update waiting for approval, and any kept
 * for 30 days after uninstalling (migrations 204 and 205, WHIT-870).
 *
 * Install-wide, so no query here takes a tenant: callers reach it only after
 * {@see PluginOperatorGate}.
 */
final class PluginRecords
{
    public const KEEP_DAYS = 30;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<string> $permissions
     * @param list<string> $domains
     */
    public function recordInstall(
        string $name,
        string $source,
        ?string $storeHost,
        ?string $storeSlug,
        string $version,
        ?string $sha256,
        array $permissions,
        array $domains,
        ?int $profileId,
    ): void {
        $this->pdo->prepare('DELETE FROM plugin_installs WHERE plugin_name = ?')->execute([$name]);
        $this->pdo->prepare(
            'INSERT INTO plugin_installs
                (plugin_name, source, store_host, store_slug, version, package_sha256, permissions, outside_domains, installed_by_profile_id, installed_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([
            $name,
            $source,
            $storeHost,
            $storeSlug,
            $version,
            $sha256,
            self::encode($permissions),
            self::encode($domains),
            $profileId,
        ]);
    }

    /** @return array<string, array<string, mixed>> Install rows by plugin name, with the installer's name. */
    public function installs(): array
    {
        $rows = $this->pdo->query(
            'SELECT i.*, p.display_name AS installed_by_name
               FROM plugin_installs i
               LEFT JOIN profiles p ON p.id = i.installed_by_profile_id'
        );
        $byName = [];
        foreach ($rows === false ? [] : $rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byName[(string) $row['plugin_name']] = $row;
        }

        return $byName;
    }

    /** @return array<string, mixed>|null */
    public function install(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM plugin_installs WHERE plugin_name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param list<string> $permissions The update's full permission list.
     * @param list<string> $domains The update's full outside-domain list.
     */
    public function setPending(string $name, string $version, ?string $sha256, array $permissions, array $domains): void
    {
        $this->pdo->prepare(
            'UPDATE plugin_installs
                SET pending_version = ?, pending_sha256 = ?, pending_permissions = ?, pending_domains = ?, pending_at = NOW()
              WHERE plugin_name = ?'
        )->execute([$version, $sha256, self::encode($permissions), self::encode($domains), $name]);
    }

    public function clearPending(string $name): void
    {
        $this->pdo->prepare(
            'UPDATE plugin_installs
                SET pending_version = NULL, pending_sha256 = NULL, pending_permissions = NULL, pending_domains = NULL, pending_at = NULL
              WHERE plugin_name = ?'
        )->execute([$name]);
    }

    /**
     * @param list<string> $permissions
     * @param list<string> $domains
     */
    public function recordUpdated(string $name, string $version, ?string $sha256, array $permissions, array $domains): void
    {
        $this->pdo->prepare(
            'UPDATE plugin_installs
                SET version = ?, package_sha256 = ?, permissions = ?, outside_domains = ?, updated_at = NOW(),
                    pending_version = NULL, pending_sha256 = NULL, pending_permissions = NULL, pending_domains = NULL, pending_at = NULL
              WHERE plugin_name = ?'
        )->execute([$version, $sha256, self::encode($permissions), self::encode($domains), $name]);
    }

    public function forgetInstall(string $name): void
    {
        $this->pdo->prepare('DELETE FROM plugin_installs WHERE plugin_name = ?')->execute([$name]);
    }

    public function markRemoved(string $name, string $key, ?string $version, bool $wasActive, ?int $profileId): void
    {
        $purgeAfter = gmdate('Y-m-d H:i:s', time() + self::KEEP_DAYS * 86400);
        $this->pdo->prepare('DELETE FROM plugin_removals WHERE plugin_name = ?')->execute([$name]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO plugin_removals (plugin_name, plugin_key, version, was_active, removed_by_profile_id, removed_at, purge_after)
             VALUES (:name, :key, :version, :was_active, :profile, NOW(), :purge_after)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':key', $key);
        $stmt->bindValue(':version', $version);
        $stmt->bindValue(':was_active', $wasActive, PDO::PARAM_BOOL);
        $stmt->bindValue(':profile', $profileId, $profileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':purge_after', $purgeAfter);
        $stmt->execute();
    }

    /** @return array<string, mixed>|null */
    public function removal(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM plugin_removals WHERE plugin_name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> Newest first, with the remover's name. */
    public function removals(): array
    {
        $rows = $this->pdo->query(
            'SELECT r.*, p.display_name AS removed_by_name
               FROM plugin_removals r
               LEFT JOIN profiles p ON p.id = r.removed_by_profile_id
              ORDER BY r.removed_at DESC'
        );

        return $rows === false ? [] : array_values($rows->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> Removals whose 30 days are over. */
    public function dueRemovals(?string $now = null): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM plugin_removals WHERE purge_after <= ? ORDER BY purge_after');
        $stmt->execute([$now ?? gmdate('Y-m-d H:i:s')]);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function forgetRemoval(string $name): void
    {
        $this->pdo->prepare('DELETE FROM plugin_removals WHERE plugin_name = ?')->execute([$name]);
    }

    public function notePurgeError(string $name, string $error): void
    {
        $this->pdo->prepare('UPDATE plugin_removals SET purge_error = ? WHERE plugin_name = ?')->execute([$error, $name]);
    }

    /** @return list<string> */
    public static function decodeList(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @param list<string> $values */
    private static function encode(array $values): string
    {
        return (string) json_encode(array_values($values), JSON_UNESCAPED_SLASHES);
    }
}
