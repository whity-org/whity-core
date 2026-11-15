<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Existing workspaces keep the currency they had when the default became US
 * dollars (design 3.5.1, CU.1, WHIT-1087).
 *
 * Until 3.5.1 the registry default for `billing.default_currency` was JOD, and
 * no workspace needed to store it, so most never did. The default is now USD;
 * without this, every such workspace would start pricing new services and
 * printing new documents in dollars overnight.
 *
 * So each workspace that exists when this runs and holds no value of its own
 * gets one: the install's global value if an operator set one, otherwise JOD,
 * the old default. A workspace created afterwards gets USD. The system
 * workspace (tenant 0) is not a business and is left to the new default, so a
 * fresh install, which has only that one when this runs, is USD throughout;
 * the operator's own plans and prices follow the owner's choice of dollars.
 *
 * Only `tenant_settings` rows are inserted. No amount, price, invoice or
 * booking is read or changed: those rows carry their own currency.
 *
 * The rows carry a fixed `updated_at` so down() removes only the ones this
 * wrote and still unchanged; a workspace that has since chosen a currency
 * keeps it.
 */
final class PinExistingWorkspaceCurrency
{
    private const KEY = 'billing.default_currency';
    private const OLD_DEFAULT = 'JOD';
    private const MARK = '2026-11-15 00:00:00';

    public static function up(Database $db): void
    {
        $global = $db->query(
            'SELECT value FROM app_settings WHERE setting_key = :key',
            [':key' => self::KEY]
        )->fetchColumn();
        $currency = is_string($global) && preg_match('/^[A-Za-z]{3}$/', trim($global)) === 1
            ? strtoupper(trim($global))
            : self::OLD_DEFAULT;

        $tenants = $db->query(
            'SELECT t.id FROM tenants t
              WHERE t.id <> 0
                AND NOT EXISTS (
                    SELECT 1 FROM tenant_settings s
                     WHERE s.tenant_id = t.id AND s.setting_key = :key
              )',
            [':key' => self::KEY]
        )->fetchAll();

        foreach ($tenants as $tenant) {
            $db->query(
                'INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at)
                 VALUES (:tenant_id, :key, :value, :updated_at)
                 ON CONFLICT (tenant_id, setting_key) DO NOTHING',
                [
                    ':tenant_id'  => (int) $tenant['id'],
                    ':key'        => self::KEY,
                    ':value'      => $currency,
                    ':updated_at' => self::MARK,
                ]
            );
        }
    }

    public static function down(Database $db): void
    {
        $db->query(
            'DELETE FROM tenant_settings WHERE setting_key = :key AND updated_at = :mark',
            [':key' => self::KEY, ':mark' => self::MARK]
        );
    }
}
