<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Add-on packs a workspace holds beside its tier (WHIT-932).
 *
 * `tenant_plan` holds ONE plan per workspace, which is right for a tier and
 * wrong for an add-on: "10 extra staff" is bought beside Team, and a workspace
 * may hold two of it. Before this table an add-on could only be applied by
 * replacing the tier, which took away everything the tier granted.
 *
 * - `plan_id` must be a plan with `is_addon` set; PlanService refuses others.
 * - `quantity` is how many packs. Each pack's numeric limits are added to the
 *   tier's, and a pack's flags are granted (see EntitlementService::effective).
 * - RESTRICT on the plan: a pack somebody holds cannot vanish from under them.
 *   PlanUsage counts holders, so deleting it is refused with the reason first.
 */
final class CreateTenantPlanAddons
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS tenant_plan_addons (
                tenant_id   INTEGER   NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                plan_id     BIGINT    NOT NULL REFERENCES plans(id) ON DELETE RESTRICT,
                quantity    INTEGER   NOT NULL DEFAULT 1,
                assigned_by INTEGER   NULL,
                assigned_at TIMESTAMP NOT NULL DEFAULT NOW(),
                PRIMARY KEY (tenant_id, plan_id),
                CHECK (quantity > 0)
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tenant_plan_addons_plan ON tenant_plan_addons (plan_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_tenant_plan_addons_plan');
        $db->exec('DROP TABLE IF EXISTS tenant_plan_addons');
    }
}
