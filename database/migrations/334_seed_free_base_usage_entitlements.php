<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * SeedFreeBaseUsageEntitlements: the Free base for the two meters core could
 * not hold before, invoices a month and API and MCP calls a month (BM.3, Q5:
 * 100 invoices and 10,000 calls, warn-only).
 *
 * ── Which plan ─────────────────────────────────────────────────────────────
 *
 * The free plan is the one new workspaces are put on: the plan named by the
 * `billing.default_plan` setting, or failing that a plan whose key is `free`.
 * A deployment with neither (every self-hosted install, BM.3 Q14) has no plan
 * limits and is not given any.
 *
 * ── Insert if absent ───────────────────────────────────────────────────────
 *
 * As in migration 152: an operator who has already set these on the plan has
 * decided, and a deploy must not reprice their product. Nothing is refused
 * past these numbers; both keys only warn (EntitlementRegistry::WARN_ONLY).
 */
final class SeedFreeBaseUsageEntitlements
{
    private const FREE_BASE = [
        'invoices.per_month' => '100',
        'api.calls.per_month' => '10000',
    ];

    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();

        $setting = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key = :key');
        $setting->execute([':key' => 'billing.default_plan']);
        $defaultKey = trim((string) ($setting->fetchColumn() ?: ''));

        $find = $pdo->prepare('SELECT id FROM plans WHERE plan_key = :plan_key');
        $planId = false;
        foreach (array_filter([$defaultKey, 'free']) as $planKey) {
            $find->execute([':plan_key' => $planKey]);
            $planId = $find->fetchColumn();
            $find->closeCursor();
            if ($planId !== false) {
                break;
            }
        }
        if ($planId === false) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO plan_entitlements (plan_id, entitlement_key, value)
             VALUES (:plan_id, :entitlement_key, :value)
             ON CONFLICT (plan_id, entitlement_key) DO NOTHING'
        );
        foreach (self::FREE_BASE as $key => $value) {
            $insert->execute([':plan_id' => (int) $planId, ':entitlement_key' => $key, ':value' => $value]);
        }
    }

    public static function down(Database $db): void
    {
        // Left in place: by then an operator may have priced the plan around
        // them, and taking a limit away is not this migration's call.
    }
}
