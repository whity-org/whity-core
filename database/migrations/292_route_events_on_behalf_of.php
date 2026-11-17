<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * RouteEventsOnBehalfOf — route steps read "I'm away" (merge map A2).
 *
 * While a person is away, the person they named decides their route items for
 * them (OR.1). The trail names who acted (`actor_profile_id`) and now also whom
 * they acted for (`document_route_events.on_behalf_of_profile_id`), so it reads
 * "Awss Haddad for Amro Saleh". It can't be worked out afterwards: when a
 * quorum is met, one event closes several people's items at once.
 *
 * Null on every existing entry and on every act a person makes for themselves.
 * The trail's hash body records it only when it is set, so entries written
 * before this migration still verify. Idempotent and reversible.
 */
final class RouteEventsOnBehalfOf
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE document_route_events ADD COLUMN IF NOT EXISTS on_behalf_of_profile_id INTEGER REFERENCES profiles(id) ON DELETE SET NULL');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE document_route_events DROP COLUMN IF EXISTS on_behalf_of_profile_id');
    }
}
