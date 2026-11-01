<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * A workspace's company facts get their own settings (WHIT-788, #1439; owner,
 * 31 Oct 2026: "own company keys... migrate billing.seller_* once").
 *
 * Copies `billing.seller_name`, `_address` and `_tax_id` to `company.name`,
 * `.address` and `.tax_id`, in both layers (the global defaults and each
 * workspace's overrides), then removes the old keys. A company value that is
 * already set is never overwritten; an empty one counts as not set. An empty
 * seller override is copied too, because it means something: that workspace
 * chose not to inherit the global default.
 *
 * Invoices already issued are untouched: they keep the seller frozen on them.
 *
 * Idempotent: with the old keys gone there is nothing left to copy. down()
 * moves the three values back, never over a seller value, and removes
 * `company.phone` and `company.email`, which have nowhere to go.
 */
class MoveBillingSellerSettingsToCompany
{
    /** @var array<string, string> old key => new key */
    private const KEYS = [
        'billing.seller_name' => 'company.name',
        'billing.seller_address' => 'company.address',
        'billing.seller_tax_id' => 'company.tax_id',
    ];

    public static function up(Database $db): void
    {
        foreach (self::KEYS as $from => $to) {
            self::move($db, $from, $to);
        }
    }

    public static function down(Database $db): void
    {
        foreach (self::KEYS as $from => $to) {
            self::move($db, $to, $from);
        }
        foreach (['company.phone', 'company.email'] as $key) {
            $db->query('DELETE FROM tenant_settings WHERE setting_key = :key', [':key' => $key]);
            $db->query('DELETE FROM app_settings WHERE setting_key = :key', [':key' => $key]);
        }
    }

    private static function move(Database $db, string $from, string $to): void
    {
        // Every workspace's row for this one key: no tenant to scope to.
        $db->query(
            "INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at)
             SELECT tenant_id, CAST(:to AS VARCHAR(100)), value, updated_at FROM tenant_settings WHERE setting_key = :from
             ON CONFLICT (tenant_id, setting_key) DO UPDATE SET value = EXCLUDED.value
             WHERE tenant_settings.value IS NULL OR tenant_settings.value = ''",
            [':to' => $to, ':from' => $from]
        );
        $db->query('DELETE FROM tenant_settings WHERE setting_key = :key', [':key' => $from]);

        $db->query(
            "INSERT INTO app_settings (setting_key, value, updated_at)
             SELECT CAST(:to AS VARCHAR(100)), value, updated_at FROM app_settings WHERE setting_key = :from
             ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value
             WHERE app_settings.value IS NULL OR app_settings.value = ''",
            [':to' => $to, ':from' => $from]
        );
        $db->query('DELETE FROM app_settings WHERE setting_key = :key', [':key' => $from]);
    }
}
