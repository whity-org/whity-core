<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Core\Tenant\HandleRules;
use Whity\Core\Tenant\ReservedHandles;
use Whity\Database\Database;

/**
 * `whity-cli handles:check` (WHIT-1363, design section 36, HD.7).
 *
 * Lists every workspace's handle and what migration 356 does, or did, to it,
 * without writing anything. Run it on a restored copy of a live database
 * before deploying, never on the live server. After the migration it says
 * which workspaces still have a handle Whity made (`ws-…`), whose owners will
 * be asked to choose one.
 */
class HandlesCheckCommand extends BaseCommand implements CliCommand
{
    public function printHelp(string $commandName): bool
    {
        $this->showHelp();

        return true;
    }

    /** @return list<string>|null */
    public function knownFlags(): ?array
    {
        return [];
    }

    /** @param array<int, string> $argv */
    public function execute(array $argv): int
    {
        foreach ($argv as $arg) {
            if ($arg === 'help' || $arg === '--help' || $arg === '-h') {
                $this->showHelp();

                return 0;
            }
        }

        require_once dirname(__DIR__, 3) . '/database/migrations/356_create_tenant_handle_history.php';

        $pdo = Database::connect()->getPdo();
        $stmt = $pdo->prepare('SELECT id, slug, name FROM tenants ORDER BY id');
        $stmt->execute();
        $tenants = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $tenants[] = [
                'id'   => (int) $row['id'],
                'slug' => is_string($row['slug']) ? $row['slug'] : null,
                'name' => (string) $row['name'],
            ];
        }
        $plan = \Database\Migrations\CreateTenantHandleHistory::plan($tenants);

        $kept = 0;
        $generated = 0;
        foreach ($tenants as $row) {
            $id = (int) $row['id'];
            if ($id === 0) {
                continue;
            }
            $old = $row['slug'];
            if (isset($plan[$id])) {
                [, $new] = $plan[$id];
                $why = $old === null || $old === '' ? 'had none' : $this->why(strtolower(trim($old)));
                echo sprintf("%5d  %-30s  %s -> %s  (%s)\n", $id, $this->cut((string) $row['name']), $old ?? '-', $new, $why);
                if (HandleRules::isGenerated($new)) {
                    $generated++;
                }
            } else {
                $kept++;
                if (HandleRules::isGenerated((string) $old)) {
                    $generated++;
                }
            }
        }
        echo sprintf(
            "%d workspace(s) keep their handle, %d change, %d have a handle Whity made (their owners will be asked to choose one).\n",
            $kept,
            count($plan),
            $generated
        );

        return 0;
    }

    private function why(string $slug): string
    {
        if (ReservedHandles::isBuiltIn($slug)) {
            return 'reserved';
        }
        $shape = HandleRules::shapeProblem($slug);
        if ($shape !== null) {
            return str_replace('_', ' ', $shape);
        }
        if (str_starts_with($slug, HandleRules::GENERATED_PREFIX)) {
            return 'starts with ws-';
        }

        return 'case or clash';
    }

    private function cut(string $name): string
    {
        return mb_strlen($name) > 30 ? mb_substr($name, 0, 29) . '…' : $name;
    }

    private function showHelp(): void
    {
        echo "Usage: whity-cli handles:check\n\n";
        echo "Lists what migration 356 does to each workspace's handle (@handle), without writing anything.\n";
        echo "Run it on a restored copy before deploying.\n";
    }
}
