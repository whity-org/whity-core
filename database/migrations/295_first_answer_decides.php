<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * FirstAnswerDecides — a decision step can settle on its first answer, either
 * way (owner, 2026-11-16; WHIT-1145).
 *
 * `decision_quorum` gains `first` beside `all`, `any` and `majority`, on route
 * steps (`document_route_steps`, migration 119) and on route template steps
 * (`document_route_template_steps`, migration 120). The default route for a
 * customer's join request uses it: whoever answers first, approve or turn away,
 * decides.
 *
 * On PostgreSQL the constraints are dropped and made again with the wider
 * list. SQLite (tests and local databases) can't alter a CHECK, so the stored
 * table definition's list is rewritten in place and the schema version bumped,
 * which is SQLite's documented way to change a constraint without rebuilding
 * the table. Re-running changes nothing. down() narrows them back, after
 * turning any `first` into `any`.
 */
final class FirstAnswerDecides
{
    /**
     * The quorums the CHECK admits from here on. The vocabulary tests compare
     * it with RouteQuorum::all(), as they compared migration 119's list before.
     */
    public const QUORUMS = ['all', 'any', 'majority', 'first'];

    private const CHECKS = [
        'document_route_steps' => 'document_route_steps_decision_quorum_check',
        'document_route_template_steps' => 'document_route_template_steps_decision_quorum_check',
    ];

    public static function up(Database $db): void
    {
        self::widen($db, "'" . implode("', '", self::QUORUMS) . "'");
    }

    public static function down(Database $db): void
    {
        // Unscoped by design (migration): every workspace's steps.
        $db->exec("UPDATE document_route_steps SET decision_quorum = 'any' WHERE decision_quorum = 'first'");
        $db->exec("UPDATE document_route_template_steps SET decision_quorum = 'any' WHERE decision_quorum = 'first'");
        self::widen($db, "'all', 'any', 'majority'");
    }

    private static function widen(Database $db, string $values): void
    {
        $pdo = $db->getPdo();
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            self::widenSqlite($pdo, $values);

            return;
        }
        foreach (self::CHECKS as $table => $name) {
            $db->exec("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            $db->exec("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK (decision_quorum IS NULL OR decision_quorum IN ({$values}))");
        }
    }

    private static function widenSqlite(PDO $pdo, string $values): void
    {
        $from = ["IN ('all', 'any', 'majority', 'first')", "IN ('all', 'any', 'majority')"];
        $to = "IN ({$values})";
        $read = $pdo->query('PRAGMA schema_version');
        $version = $read !== false ? (int) $read->fetchColumn() : 0;
        $pdo->exec('PRAGMA writable_schema = ON');
        $changed = 0;
        foreach (array_keys(self::CHECKS) as $table) {
            foreach ($from as $old) {
                if ($old === $to) {
                    continue;
                }
                $stmt = $pdo->prepare("UPDATE sqlite_master SET sql = replace(sql, :old, :new) WHERE type = 'table' AND name = :name AND instr(sql, :old2) > 0");
                $stmt->execute([':old' => $old, ':new' => $to, ':name' => $table, ':old2' => $old]);
                $changed += $stmt->rowCount();
            }
        }
        if ($changed > 0) {
            $pdo->exec('PRAGMA schema_version = ' . ($version + 1));
        }
        $pdo->exec('PRAGMA writable_schema = OFF');
    }
}
