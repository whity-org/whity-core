<?php

declare(strict_types=1);

namespace Whity\Core\Security;

/**
 * One place a secret is stored (merge map plumbing, WHIT-1047).
 *
 * Every secret core keeps is ciphertext from {@see EncryptedSecretStore}, the
 * instance key ring, but it sits in many places: a column of its own row, an
 * instance setting, a workspace setting, a field inside a JSON setting. The
 * rows stay where they are. A SecretRef names each place once, so
 * `whity-cli secrets reencrypt` finds every value after a key rotation
 * ({@see SecretReencryptor}) and {@see SecretLocations} is the one list of them.
 */
final class SecretRef
{
    public const COLUMN = 'column';
    public const INSTANCE_SETTING = 'instance_setting';
    public const WORKSPACE_SETTING = 'workspace_setting';
    public const INSTANCE_SETTING_JSON_FIELD = 'instance_setting_json_field';

    private function __construct(
        public readonly string $kind,
        public readonly string $table,
        public readonly string $idColumn,
        public readonly string $column,
        /** A setting key, a `LIKE` pattern for workspace settings, or '' for a column. */
        public readonly string $key,
        /** The field inside a JSON setting, or ''. */
        public readonly string $field,
        /** What it is, for the command's report. */
        public readonly string $label,
    ) {
    }

    /** A column on a table, each row found by its id. */
    public static function column(string $table, string $column, string $label, string $idColumn = 'id'): self
    {
        return new self(self::COLUMN, $table, $idColumn, $column, '', '', $label);
    }

    /** An instance setting (`app_settings`). */
    public static function instanceSetting(string $key, string $label): self
    {
        return new self(self::INSTANCE_SETTING, 'app_settings', 'id', 'value', $key, '', $label);
    }

    /** Workspace settings whose key matches a `LIKE` pattern (`tenant_settings`). */
    public static function workspaceSetting(string $keyPattern, string $label): self
    {
        return new self(self::WORKSPACE_SETTING, 'tenant_settings', 'id', 'value', $keyPattern, '', $label);
    }

    /** One field of an instance setting that holds JSON, such as the push key pair's private half. */
    public static function instanceSettingJsonField(string $key, string $field, string $label): self
    {
        return new self(self::INSTANCE_SETTING_JSON_FIELD, 'app_settings', 'id', 'value', $key, $field, $label);
    }
}
