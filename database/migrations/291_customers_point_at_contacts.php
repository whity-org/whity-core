<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Core\Contacts\CustomerContacts;
use Whity\Database\Database;

/**
 * CustomersPointAtContacts — Contacts is the one customer record (merge map,
 * plumbing; owner-approved 2026-11-16).
 *
 * A booking (`calendar_bookings`), a web chat visitor (`webchat_visitors`) and
 * a join request (`customer_signups`) each name a customer in their own typed
 * fields. Each now also points at the contact they are (`contact_id`), so a
 * customer's page shows their bookings, chats and requests together. The typed
 * fields stay as what was given at the time.
 *
 * Backfill, safe to re-run (only rows without a contact): each row is matched
 * to a contact by email, then mobile, then the person's account, or a contact
 * is created ({@see CustomerContacts}). On app.whity.dev that is the salon's 2
 * bookings; there are no chat visitors or join requests.
 *
 * Idempotent and reversible.
 */
final class CustomersPointAtContacts
{
    public static function up(Database $db): void
    {
        foreach (['calendar_bookings', 'webchat_visitors', 'customer_signups'] as $table) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS contact_id INTEGER REFERENCES contacts(id) ON DELETE SET NULL");
        }
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_bookings_contact ON calendar_bookings (tenant_id, contact_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_webchat_visitors_contact ON webchat_visitors (tenant_id, contact_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_customer_signups_contact ON customer_signups (tenant_id, contact_id)');

        $pdo = $db->getPdo();
        $contacts = new CustomerContacts($pdo);
        $backfill = [
            'calendar_bookings' => 'SELECT id, tenant_id, customer_name AS name, customer_email AS email, customer_phone AS phone, customer_profile_id AS profile_id FROM calendar_bookings WHERE contact_id IS NULL ORDER BY id',
            'webchat_visitors' => "SELECT id, tenant_id, COALESCE(display_name, '') AS name, COALESCE(email, '') AS email, '' AS phone, profile_id FROM webchat_visitors WHERE contact_id IS NULL ORDER BY id",
            'customer_signups' => "SELECT id, tenant_id, display_name AS name, email, '' AS phone, profile_id FROM customer_signups WHERE contact_id IS NULL AND status <> 'unverified' AND status <> 'superseded' ORDER BY id",
        ];
        foreach ($backfill as $table => $sql) {
            // Unscoped by design (migration): every workspace's customers are linked.
            $rows = $pdo->query($sql);
            if ($rows === false) {
                continue;
            }
            $set = $pdo->prepare("UPDATE {$table} SET contact_id = :contact WHERE id = :id AND tenant_id = :tenant_id");
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $contactId = $contacts->forCustomer(
                    (int) $row['tenant_id'],
                    (string) $row['name'],
                    (string) $row['email'],
                    (string) $row['phone'],
                    $row['profile_id'] !== null ? (int) $row['profile_id'] : null,
                );
                if ($contactId !== null) {
                    $set->execute([':contact' => $contactId, ':id' => (int) $row['id'], ':tenant_id' => (int) $row['tenant_id']]);
                }
            }
        }
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_calendar_bookings_contact');
        $db->exec('DROP INDEX IF EXISTS idx_webchat_visitors_contact');
        $db->exec('DROP INDEX IF EXISTS idx_customer_signups_contact');
        foreach (['calendar_bookings', 'webchat_visitors', 'customer_signups'] as $table) {
            $db->exec("ALTER TABLE {$table} DROP COLUMN IF EXISTS contact_id");
        }
    }
}
