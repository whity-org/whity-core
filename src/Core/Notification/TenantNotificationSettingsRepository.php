<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use PDO;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * A tenant's per-channel notification SENDER configuration (WC-notifications):
 * the transport, from/reply-to identity, non-secret provider config, whether
 * the channel is on, and the encrypted provider credentials.
 *
 * Stored as the `notifications.<channel>.<field>` family of the settings
 * registry in `tenant_settings` (WHIT-1036, migration 275; until then the
 * `tenant_notification_settings` table). A channel "has settings" when any of
 * its fields is stored. Every read and write binds the tenant through
 * {@see TenantSettingsRepository}, so a tenant only sees or edits its own.
 *
 * SECRET HANDLING: the API-facing reads ({@see self::listForTenant()},
 * {@see self::findForChannel()}) NEVER return the stored credentials, only a
 * `has_credentials` flag. {@see self::findWithCredentials()} is the internal
 * accessor a sending transport uses to obtain the (still-encrypted) blob.
 * Config and credentials are written separately, so editing one never touches
 * the other.
 */
final class TenantNotificationSettingsRepository
{
    private readonly TenantSettingsRepository $store;

    public function __construct(private readonly PDO $pdo)
    {
        $this->store = new TenantSettingsRepository($pdo);
    }

    /**
     * Every channel a tenant configured, by channel name — REDACTED.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId): array
    {
        $out = [];
        foreach ($this->channels($tenantId) as $channel => $fields) {
            $out[] = self::mapRedacted($channel, $fields);
        }

        return $out;
    }

    /**
     * One channel's sender config for a tenant — REDACTED.
     *
     * @return array<string, mixed>|null
     */
    public function findForChannel(int $tenantId, string $channel): ?array
    {
        $fields = $this->channels($tenantId)[$channel] ?? null;

        return $fields === null ? null : self::mapRedacted($channel, $fields);
    }

    /**
     * One channel's sender config INCLUDING the encrypted credentials blob — for a
     * sending transport to decrypt. Tenant-scoped. NOT exposed over the API.
     *
     * @return array<string, mixed>|null
     */
    public function findWithCredentials(int $tenantId, string $channel): ?array
    {
        $fields = $this->channels($tenantId)[$channel] ?? null;
        if ($fields === null) {
            return null;
        }
        $mapped = self::mapRedacted($channel, $fields);
        $mapped['credentials_encrypted'] = $fields['credentials_encrypted'] ?? null;

        return $mapped;
    }

    /**
     * Upsert a channel's non-secret sender config (transport, from/reply-to,
     * provider config, enabled) WITHOUT touching stored credentials. A field
     * given as null or empty is cleared.
     *
     * @param array{transport?: string|null, from_address?: string|null, from_name?: string|null, reply_to?: string|null, config?: array<string, mixed>, enabled?: bool} $fields
     */
    public function upsertConfig(int $tenantId, string $channel, array $fields): void
    {
        $config = $fields['config'] ?? [];
        $values = [
            'enabled'      => ($fields['enabled'] ?? true) ? 'true' : 'false',
            'transport'    => self::nullableString($fields['transport'] ?? null),
            'from_address' => self::nullableString($fields['from_address'] ?? null),
            'from_name'    => self::nullableString($fields['from_name'] ?? null),
            'reply_to'     => self::nullableString($fields['reply_to'] ?? null),
            'config'       => json_encode($config === [] ? new \stdClass() : $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ];

        $this->atomically(function () use ($tenantId, $channel, $values): void {
            foreach ($values as $field => $value) {
                $this->write($tenantId, $channel, $field, $value);
            }
        });
    }

    /**
     * Set or clear (null) a channel's ENCRYPTED credentials blob WITHOUT touching
     * the config. The caller encrypts before storing; this layer only persists.
     * Setting credentials on a channel with no config yet stores it as on.
     */
    public function setCredentials(int $tenantId, string $channel, ?string $encrypted): void
    {
        $this->atomically(function () use ($tenantId, $channel, $encrypted): void {
            if (!isset($this->channels($tenantId)[$channel])) {
                $this->write($tenantId, $channel, 'enabled', SettingsRegistry::NOTIFICATION_ENABLED_DEFAULT);
            }
            $this->write($tenantId, $channel, 'credentials_encrypted', $encrypted === '' ? null : $encrypted);
        });
    }

    /**
     * Delete a channel's sender config entirely (config + credentials).
     */
    public function delete(int $tenantId, string $channel): bool
    {
        $removed = 0;
        $this->atomically(function () use ($tenantId, $channel, &$removed): void {
            foreach (SettingsRegistry::NOTIFICATION_FIELDS as $field) {
                $removed += $this->store->delete($tenantId, SettingsRegistry::notificationKey($channel, $field));
            }
        });

        return $removed > 0;
    }

    /**
     * The tenant's stored notification fields, grouped by channel and sorted
     * by channel name.
     *
     * @return array<string, array<string, string>>
     */
    private function channels(int $tenantId): array
    {
        $out = [];
        foreach ($this->store->allForTenant($tenantId) as $key => $value) {
            $parsed = SettingsRegistry::parseNotificationKey($key);
            if ($parsed !== null) {
                $out[$parsed['channel']][$parsed['field']] = $value;
            }
        }
        ksort($out);

        return $out;
    }

    /** Store one field, or remove it for null. Refuses a value the registry refuses. */
    private function write(int $tenantId, string $channel, string $field, ?string $value): void
    {
        $key = SettingsRegistry::notificationKey($channel, $field);
        if ($value === null) {
            $this->store->delete($tenantId, $key);
            return;
        }
        $problem = SettingsRegistry::validateNotificationValue($field, $value);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }
        $this->store->set($tenantId, $key, $value);
    }

    /** Run $work in one transaction, or in the caller's when one is open. */
    private function atomically(callable $work): void
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $work();
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Redacted public shape: NEVER includes the encrypted blob, only a boolean flag.
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private static function mapRedacted(string $channel, array $fields): array
    {
        $config = json_decode($fields['config'] ?? '{}', true);
        $creds  = $fields['credentials_encrypted'] ?? null;

        return [
            'channel'         => $channel,
            'transport'       => $fields['transport'] ?? null,
            'from_address'    => $fields['from_address'] ?? null,
            'from_name'       => $fields['from_name'] ?? null,
            'reply_to'        => $fields['reply_to'] ?? null,
            'config'          => is_array($config) ? $config : [],
            'has_credentials' => $creds !== null && $creds !== '',
            'enabled'         => ($fields['enabled'] ?? SettingsRegistry::NOTIFICATION_ENABLED_DEFAULT) === 'true',
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
