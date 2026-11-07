<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\CrossTenantProbe\RouteProbe;
use Tests\Support\SchemaFromMigrations;

/**
 * Every route, as one workspace's administrator, aimed at another workspace
 * (#1144, Phase 1 and Phase 3).
 *
 * Until this test, cross-tenant isolation was checked only by reading SQL
 * text: the tenant-predicate guard looks for a `tenant_id` in each statement,
 * and roughly a hundred statements carry a reasoned exemption. Nothing sent a
 * request as one tenant and looked at what came back about another. This does,
 * for the whole route table at once, so a route added tomorrow is covered
 * without anybody remembering to add it.
 *
 * HOW. A child process boots `public/index.php` the way production does, so the
 * routes, the middleware order, the handlers and the plugin routes are the real
 * ones (see {@see RouteProbe} for the method). The victim is the seeded default
 * workspace, filled by the document demo and then by its own administrator
 * calling every create route. The attacker is a fresh workspace's
 * administrator, who calls every route and verb with the victim's ids in the
 * path and the body, and with the victim or the system tenant named in the
 * `X-Tenant-Id` header and the `tenant_id` query.
 *
 * WHAT IT PROVES, by kind of finding:
 *  - READ: no response carries text only the victim holds;
 *  - WRITE: no row outside the attacker's workspace changes (other workspaces,
 *    the system tenant's rows, and every global row except the attacker's own
 *    people), apart from the two documented below;
 *  - REFERENCE: no row the attacker owns ends up pointing at a row the victim
 *    owns;
 *  - TOKEN: no response hands out a token for another workspace or for the
 *    system tenant;
 *  - SCOPE: no handler runs with the tenant context on anything but the
 *    attacker, or with system mode on.
 *
 * AND THAT IT CAN FAIL. The probe also plants one route per kind that leaks on
 * purpose, and {@see self::testTheProbeCatchesEveryKindOfLeak} requires every
 * one to be caught. Without that, a clean result could mean the probe was blind.
 *
 * Runs only on PostgreSQL (the boot opens a real connection), in the
 * Integration shards. Takes a few minutes; the report is written once and
 * shared by every test method.
 */
final class CrossTenantRouteProbeRealBootTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $report = null;

    private static ?string $failure = null;

    /**
     * Writes outside the attacker's workspace that are correct, each with the
     * reason. Everything else is a finding.
     *
     * @return list<array{string, \Closure(array<string, mixed>): bool, string}>
     */
    private static function sanctionedWrites(): array
    {
        return [
            [
                'audit_log',
                static fn(array $row): bool => (int) $row['tenant_id'] === 0
                    && ($row['actor_kind'] ?? null) === 'system'
                    && preg_match('/(failure|failed)$/', (string) $row['action']) === 1,
                'A failed sign-in, reset or invitation is recorded before any workspace is known, so it is '
                . "the system tenant's record. It holds nothing of another workspace.",
            ],
            [
                'sequence_counters',
                static fn(array $row): bool => (int) $row['tenant_id'] === 0
                    && $row['name'] === 'democatalog:change_seq',
                "The demo catalogue's change sequence is global by design (its sync cursor is one "
                . 'monotonic counter across workspaces); it is a number, not data.',
            ],
        ];
    }

    protected function setUp(): void
    {
        if (self::postgresDsn() === null) {
            self::markTestSkipped('PHPUNIT_PG_DSN is not set; the probe boots the real app against PostgreSQL.');
        }
        if (self::$report === null && self::$failure === null) {
            try {
                self::$report = self::runProbe();
            } catch (\Throwable $e) {
                self::$failure = $e->getMessage();
            }
        }
        if (self::$failure !== null) {
            self::fail(self::$failure);
        }
    }

    public function testTheProbeReachesTheWholeRouteTable(): void
    {
        $report = $this->report();
        $stats = $report['stats'];

        self::assertGreaterThanOrEqual(500, $report['routes'], 'The booted app registered implausibly few routes.');
        // A route counts once a handler ran under the attacker's tenant for it.
        // Below this, most requests are being refused before any handler runs and
        // a clean result would say little.
        self::assertGreaterThanOrEqual(
            (int) floor($report['routes'] * 0.85),
            $stats['routes_reaching_a_handler'],
            'Too few routes reached a handler; the probe is mostly testing the middleware.'
        );
        self::assertGreaterThanOrEqual(30, count($report['victim_tables']), 'The victim workspace holds too little to aim at.');
        self::assertGreaterThanOrEqual(50, $report['needles'], 'Too few victim-only values to recognise a leak by.');
    }

    public function testTheProbeCatchesEveryKindOfLeak(): void
    {
        $caught = [];
        foreach ($this->report()['findings'] as $finding) {
            if (str_contains((string) ($finding['route'] ?? ''), '/probe-decoys/')) {
                $caught[$finding['kind']] = true;
            }
        }

        foreach (['read', 'write', 'reference', 'token', 'scope'] as $kind) {
            self::assertArrayHasKey($kind, $caught, "The probe missed the decoy that leaks by {$kind}; a clean result would prove nothing.");
        }
    }

    public function testNoRouteShowsTheOtherWorkspacesData(): void
    {
        self::assertSame([], $this->real('read'), 'A route returned text only the other workspace holds.');
    }

    public function testNoRouteChangesAnythingOutsideTheCallersWorkspace(): void
    {
        $unsanctioned = array_values(array_filter($this->real('write'), static function (array $finding): bool {
            foreach (self::sanctionedWrites() as [$table, $isSanctioned]) {
                $row = $finding['row'] ?? $finding['was'] ?? null;
                if ($finding['table'] === $table && is_array($row) && $isSanctioned($row)) {
                    return false;
                }
            }

            return true;
        }));

        self::assertSame([], $unsanctioned, 'A route changed a row outside the calling workspace.');
    }

    public function testNoRouteLinksTheCallersRowsToTheOtherWorkspace(): void
    {
        self::assertSame([], $this->real('reference'), "A route left one of the caller's rows pointing at another workspace's row.");
    }

    public function testNoRouteHandsOutATokenForAnotherWorkspaceOrTheSystemTenant(): void
    {
        self::assertSame([], $this->real('token'));
    }

    public function testEveryHandlerRunsAsTheCallersWorkspaceWithSystemModeOff(): void
    {
        self::assertSame([], $this->real('scope'));
    }

    // ─── plumbing ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function report(): array
    {
        self::assertIsArray(self::$report);

        return self::$report;
    }

    /** @return list<array<string, mixed>> findings of one kind, decoys excluded */
    private function real(string $kind): array
    {
        return array_values(array_filter(
            $this->report()['findings'],
            static fn(array $f): bool => $f['kind'] === $kind
                && !str_contains((string) ($f['route'] ?? ''), '/probe-decoys/')
        ));
    }

    /** @return array<string, mixed> */
    private static function runProbe(): array
    {
        // A throwaway database of its own: the probe writes thousands of rows.
        $pdo = SchemaFromMigrations::make();
        $statement = $pdo->query('SELECT current_database()');
        self::assertNotFalse($statement);
        $database = (string) $statement->fetchColumn();
        unset($pdo);

        $root = dirname(__DIR__, 2);
        $env = self::environment($database);

        self::runChild([PHP_BINARY, 'tests/Support/CrossTenantProbe/run.php', 'seed', '--with-fixtures', '--with-document-demo'], $env, $root, 'seed');

        $report = tempnam(sys_get_temp_dir(), 'whity-probe-');
        self::assertIsString($report);
        self::runChild(
            [PHP_BINARY, '-d', 'memory_limit=-1', 'tests/Support/CrossTenantProbe/run.php'],
            $env + ['WHITY_PROBE_REPORT' => $report, 'WHITY_PROBE_DECOYS' => '1'],
            $root,
            'probe'
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        @unlink($report);

        return $decoded;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private static function runChild(array $command, array $env, string $root, string $what): void
    {
        // Output goes to files, not pipes: the app logs to stderr freely, and a
        // full pipe nobody is reading would stall the child for good.
        $out = (string) tempnam(sys_get_temp_dir(), 'whity-probe-out-');
        $err = (string) tempnam(sys_get_temp_dir(), 'whity-probe-err-');
        $process = proc_open($command, [1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, $root, $env + self::inherited());
        self::assertIsResource($process, "Could not start the {$what} process.");
        $exit = proc_close($process);
        $stdout = (string) file_get_contents($out);
        $stderr = (string) file_get_contents($err);
        @unlink($out);
        @unlink($err);
        if ($exit !== 0) {
            throw new \RuntimeException("The {$what} process exited {$exit}.\nSTDOUT:\n" . substr($stdout, -4000) . "\nSTDERR:\n" . substr($stderr, -4000));
        }
    }

    /** @return array<string, string> */
    private static function inherited(): array
    {
        $env = [];
        foreach (['PATH', 'HOME', 'TMPDIR', 'TEMP', 'TMP', 'SYSTEMROOT'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /** @return array<string, string> */
    private static function environment(string $database): array
    {
        $pairs = [];
        foreach (explode(';', (string) preg_replace('/^[a-z]+:/i', '', (string) self::postgresDsn())) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $pairs[trim($parts[0])] = trim($parts[1]);
            }
        }

        return [
            'APP_ENV' => 'development',
            'DB_HOST' => $pairs['host'] ?? 'localhost',
            'DB_PORT' => $pairs['port'] ?? '5432',
            'DB_NAME' => $database,
            'DB_USER' => (string) (getenv('PHPUNIT_PG_USER') ?: 'whity'),
            'DB_PASSWORD' => (string) (getenv('PHPUNIT_PG_PASSWORD') ?: 'whity_dev'),
            'JWT_SECRET' => (string) (getenv('JWT_SECRET') ?: 'probe_jwt_secret_min_32_chars_aaaaaaaaaa'),
            'ENCRYPTION_KEY' => (string) (getenv('ENCRYPTION_KEY') ?: 'probe_encryption_key_min_32_chars_bbbbbb'),
            'INITIAL_ADMIN_PASSWORD' => 'probe-admin-password',
            'INITIAL_USER_PASSWORD' => 'probe-user-password',
            'INITIAL_SYSTEM_ADMIN_PASSWORD' => 'probe-system-password',
            'INITIAL_SUPERUSER_PASSWORD' => 'probe-superuser-password',
            'DEMO_SEED_PASSWORD' => 'probe-demo-password',
            'RATE_LIMIT_ENABLED' => '0',
        ];
    }

    private static function postgresDsn(): ?string
    {
        $dsn = getenv('PHPUNIT_PG_DSN') ?: ($_ENV['PHPUNIT_PG_DSN'] ?? null);

        return is_string($dsn) && $dsn !== '' ? $dsn : null;
    }
}
