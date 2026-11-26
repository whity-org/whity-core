<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * CreateCalendarWaitlist — the waitlist (WHIT-1442, design section 39 §8,
 * register AP.23).
 *
 * 1. `calendar_waitlist`: someone who wants a time that isn't free. The
 *    customer (their contact, with the name, email and phone they gave), the
 *    service, a person or anyone (`profile_id` null), the days from and to,
 *    the times of day (`any`, or any of `morning`, `afternoon`, `evening`),
 *    who added it (`staff` or `public`), their language, and the status:
 *    `waiting`, `offered`, `booked` or `removed`.
 *
 * 2. `calendar_waitlist_offers`: a freed time offered to one entry. Every
 *    entry that matches is offered at once (Q12). The offer's link carries a
 *    token; only its SHA-256 is kept. It ends at the time's start minus the
 *    minimum notice. Who takes it first is decided by the booking itself:
 *    the database refuses a second booking of the same time (M1,
 *    `calendar_holds`), so the others' offers become `lost`.
 *
 * Tenant-owned and named literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class CreateCalendarWaitlist
{
    public static function up(Database $db): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $id = $pgsql ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $ref = $pgsql ? 'BIGINT' : 'INTEGER';

        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_waitlist (
                id                     {$id},
                tenant_id              INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                contact_id             INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
                customer_name          VARCHAR(200) NOT NULL DEFAULT '',
                customer_email         VARCHAR(320) NOT NULL DEFAULT '',
                customer_phone         VARCHAR(64) NOT NULL DEFAULT '',
                service_id             INTEGER NOT NULL REFERENCES calendar_services(id) ON DELETE CASCADE,
                profile_id             INTEGER REFERENCES profiles(id) ON DELETE SET NULL,
                date_from              DATE NOT NULL,
                date_to                DATE NOT NULL,
                times                  VARCHAR(40) NOT NULL DEFAULT 'any',
                note                   VARCHAR(500) NOT NULL DEFAULT '',
                language               VARCHAR(8) NOT NULL DEFAULT '',
                source                 VARCHAR(16) NOT NULL DEFAULT 'staff',
                status                 VARCHAR(16) NOT NULL DEFAULT 'waiting',
                booking_id             INTEGER REFERENCES calendar_bookings(id) ON DELETE SET NULL,
                created_by_profile_id  INTEGER REFERENCES profiles(id) ON DELETE SET NULL,
                created_at             TIMESTAMP NOT NULL,
                updated_at             TIMESTAMP NOT NULL
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_waitlist_open ON calendar_waitlist(tenant_id, status, service_id)');

        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_waitlist_offers (
                id            {$id},
                tenant_id     INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                waitlist_id   {$ref} NOT NULL REFERENCES calendar_waitlist(id) ON DELETE CASCADE,
                service_id    INTEGER NOT NULL REFERENCES calendar_services(id) ON DELETE CASCADE,
                profile_id    INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                starts_at     TIMESTAMP NOT NULL,
                expires_at    TIMESTAMP NOT NULL,
                token_hash    VARCHAR(64) NOT NULL,
                status        VARCHAR(16) NOT NULL DEFAULT 'open',
                booking_id    INTEGER REFERENCES calendar_bookings(id) ON DELETE SET NULL,
                offered_by_profile_id INTEGER REFERENCES profiles(id) ON DELETE SET NULL,
                created_at    TIMESTAMP NOT NULL,
                decided_at    TIMESTAMP
            )
        ");
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_calendar_waitlist_offers_token ON calendar_waitlist_offers(token_hash)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_waitlist_offers_slot ON calendar_waitlist_offers(tenant_id, profile_id, starts_at)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS calendar_waitlist_offers');
        $db->exec('DROP TABLE IF EXISTS calendar_waitlist');
    }
}
