<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * CreateCalendarPlans — treatment plans (WHIT-1444, design section 39 §7.4,
 * register AP.22).
 *
 * 1. `calendar_plans`: a customer, the staff member who treats them, a title
 *    and a status (`active`, `done` or `stopped`). The customer is their
 *    contact, and only that: their name, email and phone are read from the
 *    contact, never copied here, so the details live in one place and
 *    deleting the contact deletes the plan.
 * 2. `calendar_plan_steps`: in order, each a service and the gap after the
 *    step before as a range of days ("14–21 days"), and the booking once it
 *    is made. Whether a step happened is read from its booking (attended,
 *    didn't come), never stored twice.
 * 3. `calendar_plan_templates`: a service's ready-made plan ("Braces
 *    check-ups", "Laser, 6 sessions"): a title and its steps.
 *
 * Tenant-owned and named literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class CreateCalendarPlans
{
    public static function up(Database $db): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $id = $pgsql ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $ref = $pgsql ? 'BIGINT' : 'INTEGER';

        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_plans (
                id                     {$id},
                tenant_id              INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                contact_id             INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
                profile_id             INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                title                  VARCHAR(200) NOT NULL,
                status                 VARCHAR(16) NOT NULL DEFAULT 'active',
                created_by_profile_id  INTEGER REFERENCES profiles(id) ON DELETE SET NULL,
                created_at             TIMESTAMP NOT NULL,
                updated_at             TIMESTAMP NOT NULL
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_plans_contact ON calendar_plans(tenant_id, contact_id)');

        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_plan_steps (
                id            {$id},
                tenant_id     INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                plan_id       {$ref} NOT NULL REFERENCES calendar_plans(id) ON DELETE CASCADE,
                position      INTEGER NOT NULL,
                service_id    INTEGER NOT NULL REFERENCES calendar_services(id) ON DELETE CASCADE,
                gap_min_days  INTEGER NOT NULL DEFAULT 0,
                gap_max_days  INTEGER NOT NULL DEFAULT 0,
                booking_id    INTEGER REFERENCES calendar_bookings(id) ON DELETE SET NULL
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_plan_steps_plan ON calendar_plan_steps(tenant_id, plan_id, position)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_plan_steps_booking ON calendar_plan_steps(tenant_id, booking_id)');

        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_plan_templates (
                id          {$id},
                tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                service_id  INTEGER NOT NULL REFERENCES calendar_services(id) ON DELETE CASCADE,
                title       VARCHAR(200) NOT NULL,
                steps       TEXT NOT NULL DEFAULT '[]',
                created_at  TIMESTAMP NOT NULL,
                updated_at  TIMESTAMP NOT NULL
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_plan_templates_service ON calendar_plan_templates(tenant_id, service_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS calendar_plan_templates');
        $db->exec('DROP TABLE IF EXISTS calendar_plan_steps');
        $db->exec('DROP TABLE IF EXISTS calendar_plans');
    }
}
