<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateCalendarBookings — services, who provides them, and bookings
 * (WHIT-941, ADR 0017).
 *
 * A service's `name` and `description` are TEXT holding a `{ar?, en?}` JSON
 * object (the LocalizedLabel shape). `price_minor` is an integer in the
 * currency's minor units (JOD 12.500 is 12500) and is only ever SHOWN: core
 * charges nothing, a payment plugin does (SDK 1.98).
 *
 * A booking keeps the customer's details as typed (name, email, phone),
 * because core has no contacts table, and links `customer_profile_id` only
 * when that is known without creating an account. `starts_at`/`ends_at` are
 * the service time; `blocks_from`/`blocks_until` add the buffers and are what
 * availability reads. A `pending_payment` booking holds its slot until
 * `hold_expires_at`. `manage_token_hash` is the SHA-256 of the customer's
 * manage link token; the token is never stored.
 *
 * Table names are spelled out literally for TenantOwnedTablesTest and
 * CoreTablesTest.
 */
final class CreateCalendarBookings
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS calendar_services (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name                  TEXT        NOT NULL,
                description           TEXT        NOT NULL DEFAULT \'{}\',
                duration_minutes      INTEGER     NOT NULL,
                buffer_before_minutes INTEGER     NOT NULL DEFAULT 0,
                buffer_after_minutes  INTEGER     NOT NULL DEFAULT 0,
                price_minor           BIGINT,
                currency              CHAR(3)     NOT NULL DEFAULT \'JOD\',
                bookable_online       BOOLEAN     NOT NULL DEFAULT TRUE,
                active                BOOLEAN     NOT NULL DEFAULT TRUE,
                sort_order            INTEGER     NOT NULL DEFAULT 0,
                created_at            TIMESTAMP   NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP   NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_services_tenant_id ON calendar_services(tenant_id)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS calendar_service_staff (
                id          SERIAL PRIMARY KEY,
                tenant_id   INTEGER   NOT NULL REFERENCES tenants(id)           ON DELETE CASCADE,
                service_id  INTEGER   NOT NULL REFERENCES calendar_services(id) ON DELETE CASCADE,
                profile_id  INTEGER   NOT NULL REFERENCES profiles(id)          ON DELETE CASCADE,
                created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, service_id, profile_id)
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS calendar_bookings (
                id                      SERIAL PRIMARY KEY,
                tenant_id               INTEGER      NOT NULL REFERENCES tenants(id)           ON DELETE CASCADE,
                service_id              INTEGER      NOT NULL REFERENCES calendar_services(id) ON DELETE RESTRICT,
                profile_id              INTEGER      NOT NULL REFERENCES profiles(id)          ON DELETE RESTRICT,
                customer_profile_id     INTEGER               REFERENCES profiles(id)          ON DELETE SET NULL,
                customer_name           VARCHAR(200) NOT NULL,
                customer_email          VARCHAR(320) NOT NULL DEFAULT \'\',
                customer_phone          VARCHAR(40)  NOT NULL DEFAULT \'\',
                customer_note           VARCHAR(1000) NOT NULL DEFAULT \'\',
                starts_at               TIMESTAMP    NOT NULL,
                ends_at                 TIMESTAMP    NOT NULL,
                blocks_from             TIMESTAMP    NOT NULL,
                blocks_until            TIMESTAMP    NOT NULL,
                status                  VARCHAR(20)  NOT NULL,
                hold_expires_at         TIMESTAMP,
                source                  VARCHAR(10)  NOT NULL,
                price_minor             BIGINT,
                currency                CHAR(3)      NOT NULL DEFAULT \'JOD\',
                payment_ref             VARCHAR(255),
                manage_token_hash       CHAR(64),
                manage_token_expires_at TIMESTAMP,
                reminder_due_at         TIMESTAMP,
                reminder_sent_at        TIMESTAMP,
                created_by_profile_id   INTEGER               REFERENCES profiles(id) ON DELETE SET NULL,
                cancelled_by            VARCHAR(10),
                cancel_reason           VARCHAR(500) NOT NULL DEFAULT \'\',
                cancelled_at            TIMESTAMP,
                created_at              TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_bookings_owner ON calendar_bookings(tenant_id, profile_id, blocks_from)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_bookings_starts ON calendar_bookings(tenant_id, starts_at)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_calendar_bookings_manage_token ON calendar_bookings(manage_token_hash)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS calendar_bookings');
        $db->exec('DROP TABLE IF EXISTS calendar_service_staff');
        $db->exec('DROP TABLE IF EXISTS calendar_services');
    }
}
