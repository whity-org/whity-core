<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\AuditHistorySources;

/**
 * The audit page reads what agents did and the decisions on routes beside the
 * audit log (merge map plumbing), each in the log's own row shape.
 *
 * Example Bakery (1): Amro's stock assistant restocked dates; Awss approved a
 * budget request. Example Florist (2) has its own approval, never shown here.
 */
final class AuditHistorySourcesRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Florist', 'florist')");
        foreach ([10 => 'Amro Saleh', 11 => 'Awss Haddad', 12 => 'Emad Nasser'] as $id => $name) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                              VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        }
        $this->pdo->exec("INSERT INTO agent_actions (id, tenant_id, agent_principal_id, on_behalf_of_profile_id, tool, arguments, summary, permissions, status, created_at)
                          VALUES (1, 1, 'agent:7', 10, 'stock.restock', '{}', 'Restocked dates', '[]', 'done', '2026-11-16 08:00:00')");
        foreach ([[1, 1, 11, 'approved', 'Budget request'], [2, 2, 12, 'approved', 'Wedding flowers']] as [$id, $tenant, $actor, $verdict, $title]) {
            $this->pdo->exec("INSERT INTO document_routes (id, tenant_id, title, subject_type, subject_id, created_at) VALUES ({$id}, {$tenant}, '{$title}', 'form_submission', 5, CURRENT_TIMESTAMP)");
            $this->pdo->exec("INSERT INTO document_route_events (tenant_id, route_id, actor_profile_id, action, verdict, withdrawal, to_author, occurred_at)
                              VALUES ({$tenant}, {$id}, {$actor}, 'acknowledged', '{$verdict}', false, false, '2026-11-16 09:00:00')");
            $this->pdo->exec("INSERT INTO document_route_events (tenant_id, route_id, actor_profile_id, action, withdrawal, to_author, occurred_at)
                              VALUES ({$tenant}, {$id}, {$actor}, 'noted', false, false, '2026-11-16 09:05:00')");
        }
    }

    public function testAgentsAndRouteDecisionsReadAsAuditRows(): void
    {
        $history = new AuditHistorySources($this->pdo);
        $scope = $history->scope([]);
        $rows = $history->rows(1, [], $scope, 25);

        $byAction = array_column($rows, null, 'action');
        $actions = array_keys($byAction);
        sort($actions);
        self::assertSame(['agent.action.ran', 'route.approved'], $actions);
        self::assertSame('agent:7', $byAction['agent.action.ran']['agent_principal_id']);
        self::assertSame(10, $byAction['agent.action.ran']['on_behalf_of_profile_id']);
        self::assertSame('Restocked dates', json_decode($byAction['agent.action.ran']['metadata'], true)['title']);
        self::assertSame(11, $byAction['route.approved']['actor_user_id']);
        self::assertSame('Budget request', json_decode($byAction['route.approved']['metadata'], true)['title']);
        self::assertSame(2, $history->count(1, [], $scope), 'a plain note is not a decision; the florist is not ours');
    }

    public function testFiltersOnlyTheLogHasLeaveThemOut(): void
    {
        $history = new AuditHistorySources($this->pdo);
        self::assertSame(['agents' => false, 'routes' => false], $history->scope(['source' => 'audit']));
        self::assertSame(['agents' => false, 'routes' => false], $history->scope(['target_type' => 'role']));
        self::assertSame(['agents' => false, 'routes' => true], $history->scope(['area' => 'documents']));
        self::assertSame(['agents' => true, 'routes' => false], $history->scope(['area' => 'agents']));
        self::assertSame(['agents' => false, 'routes' => true], $history->scope(['action' => 'route.approved']));
        self::assertSame([], $history->rows(1, ['actor' => '12'], $history->scope([]), 25), 'nobody here is Emad');
    }

    public function testTheSystemWorkspaceReadsEveryWorkspace(): void
    {
        $history = new AuditHistorySources($this->pdo);
        self::assertSame(3, $history->count(0, [], $history->scope([])));
    }
}
