<?php

declare(strict_types=1);

namespace Tests\Support\CrossTenantProbe;

use PDO;
use Whity\Auth\JwtParser;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\HttpKernel;

/**
 * Drives every registered route of a REALLY BOOTED app as one workspace's
 * administrator, aimed at another workspace's data (#1144, Phase 1 and 3).
 *
 * The static guards read SQL text. This sends requests: it boots
 * `public/index.php` the way production does, so the route table, the
 * middleware order and the handlers are the real ones, and plugin routes are
 * included. Then, for every route and every verb:
 *
 *  - the path parameters are filled with ids the VICTIM workspace owns,
 *    picked from the table the path segment names;
 *  - the body is synthesised from the route's OpenAPI schema, with every id
 *    field pointing at a victim row and every tenant field at the victim or at
 *    the system tenant (0);
 *  - each request is sent three ways: plain, and with `X-Tenant-Id` plus
 *    `?tenant_id=` naming the victim, and naming the system tenant.
 *
 * What counts as a finding:
 *
 *  - READ: a response contains a string only the victim's rows hold (every
 *    distinctive text value of every victim row, plus the canary the probe
 *    wrote through the victim's own API);
 *  - WRITE: any row that is not the attacker's changed — every tenant-owned
 *    row of another workspace or of the system tenant, and every row of a
 *    global table, unless it belongs to a person who is only the attacker's;
 *  - TOKEN: a response carries a signed token for any workspace but the
 *    attacker's;
 *  - SCOPE: a handler ran with the tenant context set to anything but the
 *    attacker, or with system mode on.
 *
 * The probe reports; the test decides. It never asserts anything itself.
 */
final class RouteProbe
{
    public const VICTIM_CANARY = 'zzvictimcanary';
    private const ATTACKER_CANARY = 'zzattackercanary';

    /** Paths the attacker must not call: they end the attacker's own session. */
    private const ATTACKER_SKIP = '#/(logout|sign-out)$#';

    /**
     * Paths the victim's seeding pass leaves alone: ending its own session,
     * changing its own sign-in, or removing its own workspace would stop the
     * seeding rather than add data.
     */
    private const SEED_SKIP = '#/(logout|sign-out|sessions|password|2fa|two-factor|me/profile|switch-tenant|select-tenant|tenants/\{)#';

    private PDO $pdo;
    private JwtParser $jwt;

    /** @var array<string, array<string, mixed>> openapi operation by "METHOD /normalised/path" */
    private array $specOps = [];
    /** @var array<string, mixed> */
    private array $spec = [];
    /** @var list<string> tables with a tenant_id column */
    private array $tenantTables = [];
    /** @var array<string, true> tables with an id column */
    private array $idTables = [];

    private int $victim = 0;
    private int $attacker = 0;
    private int $victimAdmin = 0;
    private int $attackerAdmin = 0;

    /** What the observer middleware saw for the request in flight. */
    private ?int $observedTenant = null;
    private bool $observedSystemMode = false;
    private bool $observing = false;

    /** @var list<array<string, mixed>> */
    private array $findings = [];

    /**
     * @param \Closure(): void $resetRequest the worker loop's per-request reset
     */
    public function __construct(
        private readonly HttpKernel $kernel,
        private readonly Router $router,
        PDO $pdo,
        string $jwtSecret,
        private readonly \Closure $resetRequest,
        private readonly string $root,
    ) {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->jwt = new JwtParser($jwtSecret);

        // Innermost middleware: it runs after the tenant has been resolved and
        // around the handler, so it sees the context the handler ran under.
        $kernel->use(new class ($this) {
            public function __construct(private readonly RouteProbe $probe)
            {
            }

            public function handle(Request $request, callable $next): \Whity\Sdk\Http\Response
            {
                $response = $next($request);
                $this->probe->observe(TenantContext::getTenantId(), TenantContext::isSystemMode());

                return $response;
            }
        });
    }

    /** @internal called by the observer middleware */
    public function observe(?int $tenant, bool $systemMode): void
    {
        if ($this->observing) {
            $this->observedTenant = $tenant;
            $this->observedSystemMode = $systemMode;
        }
    }

    /** @return array{?int, bool} the tenant and system mode the last handler ran under */
    private function observed(): array
    {
        return [$this->observedTenant, $this->observedSystemMode];
    }

    /**
     * @return array<string, mixed> the report the test asserts on
     */
    public function run(): array
    {
        $this->loadSpec();
        $this->tenantTables = $this->column("SELECT table_name FROM information_schema.columns WHERE column_name = 'tenant_id' AND table_schema = current_schema()");
        $this->idTables = array_fill_keys($this->column("SELECT table_name FROM information_schema.columns WHERE column_name = 'id' AND table_schema = current_schema()"), true);

        $this->setUpWorkspaces();
        $seeded = $this->seedVictimThroughItsOwnApi();
        $victimIds = $this->idsOwnedBy($this->victim);
        // People are global rows; the victim's are those with no other workspace.
        // Users and persons routes take a profile id, so both names point here.
        $victimPeople = array_map('intval', $this->column(
            "SELECT profile_id FROM memberships GROUP BY profile_id HAVING bool_and(tenant_id = {$this->victim}) ORDER BY profile_id DESC LIMIT 20"
        ));
        $victimIds['profiles'] = $victimPeople;
        $victimIds['users'] = $victimPeople;
        $needles = $this->victimNeedles();

        $stats = $this->attack($victimIds, $needles);

        return [
            'victim' => $this->victim,
            'attacker' => $this->attacker,
            'routes' => count($this->router->getRoutes()),
            'seeded_ok' => $seeded,
            'victim_tables' => array_map('count', $victimIds),
            'needles' => count($needles),
            'stats' => $stats,
            'findings' => $this->findings,
        ];
    }

    /**
     * Routes that leak ON PURPOSE, one per kind of finding, so the test can show
     * the probe catches each kind. A probe that reports nothing proves nothing
     * unless it is known to report something when there is something.
     */
    public const DECOY_PREFIX = '/api/probe-decoys/';

    public function plantDecoys(): void
    {
        $pdo = $this->pdo;
        $json = static fn(mixed $data): \Whity\Sdk\Http\Response => Response::json(['data' => $data]);

        // READ: a document by id with no tenant predicate.
        $this->router->register('GET', self::DECOY_PREFIX . 'documents/{id}', static function (Request $request, array $params) use ($pdo, $json) {
            $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = :id');
            $stmt->execute([':id' => (int) $params['id']]);

            return $json($stmt->fetch(PDO::FETCH_ASSOC));
        });
        // WRITE: a role changed by id with no tenant predicate.
        $this->router->register('POST', self::DECOY_PREFIX . 'roles/{id}', static function (Request $request, array $params) use ($pdo, $json) {
            $pdo->prepare("UPDATE roles SET description = 'changed by the decoy' WHERE id = :id")->execute([':id' => (int) $params['id']]);

            return $json(true);
        });
        // REFERENCE: the caller's own membership filed under a unit by id, unchecked.
        $this->router->register('POST', self::DECOY_PREFIX . 'organizational-units/{id}', function (Request $request, array $params) use ($pdo, $json) {
            $pdo->prepare('UPDATE memberships SET ou_id = :ou WHERE tenant_id = :t')
                ->execute([':ou' => (int) $params['id'], ':t' => $this->attacker]);

            return $json(true);
        });
        // TOKEN: a token minted for whatever tenant the body names.
        $this->router->register('POST', self::DECOY_PREFIX . 'token', function (Request $request) use ($json) {
            $body = json_decode($request->getBody(), true);

            return $json(['token' => $this->tokenFor($this->attackerAdmin, (int) ($body['tenant_id'] ?? 0))]);
        });
        // SCOPE: a handler that switches system mode on.
        $this->router->register('GET', self::DECOY_PREFIX . 'system-mode', static function () use ($json) {
            TenantContext::setSystemMode(true, 'probe decoy');

            return $json(true);
        });
    }

    // ─── workspaces ──────────────────────────────────────────────────────────

    private function setUpWorkspaces(): void
    {
        $adminRole = (int) $this->scalar("SELECT id FROM roles WHERE name = 'admin' AND tenant_id IS NULL");
        $userRole = (int) $this->scalar("SELECT id FROM roles WHERE name = 'user' AND tenant_id IS NULL");

        // The victim is the seeded default workspace: the demo seed fills it with
        // an organisation, templates, documents and routes.
        $this->victim = (int) $this->scalar("SELECT MIN(id) FROM tenants WHERE id > 0");
        $this->victimAdmin = (int) $this->scalar(
            "SELECT MIN(profile_id) FROM memberships WHERE tenant_id = {$this->victim} AND role_id = {$adminRole} AND status = 'active'"
        );
        $this->pdo->exec("UPDATE tenants SET name = '" . self::VICTIM_CANARY . " workspace' WHERE id = {$this->victim}");

        $this->attacker = (int) $this->scalar('SELECT MAX(id) + 1 FROM tenants');
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES ({$this->attacker}, 'probe attacker workspace')");
        $this->attackerAdmin = $this->profile('probe-attacker-admin', 'attacker-admin@probe.example', [[$this->attacker, $adminRole]]);

        // A person in BOTH workspaces: the attacker legitimately reaches them, and
        // must still see nothing the victim holds about them (WHIT-594).
        $this->profile('Probe Shared Person', 'shared@probe.example', [[$this->attacker, $userRole], [$this->victim, $adminRole]]);
        // A person only the victim has.
        $this->profile(self::VICTIM_CANARY . ' person', self::VICTIM_CANARY . '-person@victim.example', [[$this->victim, $userRole]]);
    }

    /**
     * @param list<array{int, int}> $memberships [tenant, role]
     */
    private function profile(string $name, string $email, array $memberships): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO profiles (display_name, password_hash) VALUES (:n, '') RETURNING id");
        $stmt->execute([':n' => $name]);
        $id = (int) $stmt->fetchColumn();
        $this->pdo->prepare('INSERT INTO profile_emails (profile_id, email, verified, is_primary) VALUES (:p, :e, true, true)')
            ->execute([':p' => $id, ':e' => $email]);
        $primary = true;
        foreach ($memberships as [$tenant, $role]) {
            $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, status, is_primary) VALUES (:p, :t, :r, 'active', :pr)")
                ->execute([':p' => $id, ':t' => $tenant, ':r' => $role, ':pr' => $primary ? 'true' : 'false']);
            $primary = false;
        }

        return $id;
    }

    // ─── seeding the victim ──────────────────────────────────────────────────

    /**
     * Fill the victim workspace through its own administrator's API: every POST
     * and PUT, four rounds (required fields only, then every field), each round
     * feeding the ids the previous one created into foreign-key fields.
     */
    private function seedVictimThroughItsOwnApi(): int
    {
        $ok = 0;
        foreach ([false, false, true, true] as $full) {
            $ids = $this->idsOwnedBy($this->victim);
            $intFor = $this->intChooser($ids, null);
            foreach ($this->router->getRoutes() as $route) {
                if (!in_array($route['method'], ['POST', 'PUT'], true) || preg_match(self::SEED_SKIP, $route['path']) === 1
                    || str_contains($route['path'], '/probe-decoys/')) {
                    continue;
                }
                $body = $this->bodyFor($route, $intFor, self::VICTIM_CANARY, $full) ?? [];
                foreach ($this->candidates($route['path'], $ids, 3) as $params) {
                    [$status] = $this->send($route['method'], $this->fill($route['path'], $params), [], $body, $this->victimAdmin, $this->victim);
                    if ($status >= 200 && $status < 300) {
                        $ok++;
                        break;
                    }
                }
            }
        }

        return $ok;
    }

    // ─── the attack ──────────────────────────────────────────────────────────

    /**
     * @param array<string, list<int>> $victimIds
     * @param list<string> $needles
     * @return array<string, mixed>
     */
    private function attack(array $victimIds, array $needles): array
    {
        $requests = 0;
        $byStatus = [];
        $handled = 0;
        $routesHandled = [];
        $perRoute = [];
        $this->startWatching();
        $decoysStarted = false;

        // name => [headers, query, tenant every tenant field in the body names, GET only]
        $variants = [
            // The victim's ids, and a body naming the victim.
            'plain' => [[], [], $this->victim, false],
            // The same, with the body naming the system tenant. Sent without the
            // header so the middleware lets it through to the handler.
            'body names the system tenant' => [[], [], 0, false],
            // The victim, and then the system tenant, named where the
            // middleware looks: the header and the query.
            'header names the victim' => [['X-Tenant-Id' => (string) $this->victim], ['tenant_id' => (string) $this->victim], $this->victim, false],
            'header names the system tenant' => [['X-Tenant-Id' => '0'], ['tenant_id' => '0'], 0, false],
            // A search for the canary the victim's own rows carry.
            'search' => [[], ['q' => self::VICTIM_CANARY, 'search' => self::VICTIM_CANARY, 'query' => self::VICTIM_CANARY], $this->victim, true],
        ];

        foreach ($this->router->getRoutes() as $route) {
            if (preg_match(self::ATTACKER_SKIP, $route['path']) === 1) {
                continue;
            }
            $label = $route['method'] . ' ' . $route['path'];

            // The decoys are registered last. Everything before them is checked
            // for references first, so a decoy's reference is never mistaken for
            // a real route's.
            if (!$decoysStarted && str_contains($route['path'], '/probe-decoys/')) {
                $decoysStarted = true;
                $this->checkpoint('a GET route after the last write route');
            }

            foreach ($this->candidates($route['path'], $victimIds, 6) as $params) {
                $path = $this->fill($route['path'], $params);
                foreach ($variants as $variant => [$headers, $query, $tenantTarget, $getOnly]) {
                    $hasBody = !in_array($route['method'], ['GET', 'HEAD', 'DELETE'], true);
                    if (($getOnly && $route['method'] !== 'GET') || ($variant === 'body names the system tenant' && !$hasBody)) {
                        continue;
                    }
                    $body = $hasBody
                        ? ($this->bodyFor($route, $this->intChooser($victimIds, $tenantTarget), self::ATTACKER_CANARY, true) ?? ['tenant_id' => $tenantTarget])
                        : null;

                    $this->observing = true;
                    $this->observedTenant = null;
                    $this->observedSystemMode = false;
                    [$status, $responseBody, $responseHeaders] = $this->send($route['method'], $path, $query, $body, $this->attackerAdmin, $this->attacker, $headers);
                    $this->observing = false;
                    [$observedTenant, $observedSystemMode] = $this->observed();

                    $requests++;
                    if ($variant === 'plain') {
                        $perRoute[$label][] = $status;
                    }
                    $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
                    $at = "{$route['method']} {$path} ({$variant}) -> {$status}";

                    if ($observedTenant !== null) {
                        $handled++;
                        $routesHandled[$label] = true;
                        if ($observedTenant !== $this->attacker || $observedSystemMode) {
                            $this->findings[] = ['kind' => 'scope', 'route' => $label, 'at' => $at,
                                'tenant' => $observedTenant, 'system_mode' => $observedSystemMode];
                        }
                    }

                    $this->checkResponse($label, $at, $responseBody, $responseHeaders, $needles);
                    if ($status >= 500) {
                        $this->findings[] = ['kind' => 'error', 'route' => $label, 'at' => $at, 'excerpt' => substr($responseBody, 0, 300)];
                    }
                }
            }

            // A GET is not expected to write, so the database is compared after
            // each write route only; a GET that writes shows up in the next
            // comparison, under the route that follows it.
            if ($route['method'] !== 'GET') {
                $this->checkpoint($label);
            }
        }
        $this->checkpoint('a GET route after the last write route');

        return [
            'requests' => $requests,
            'by_status' => $byStatus,
            'requests_reaching_a_handler' => $handled,
            'routes_reaching_a_handler' => count($routesHandled),
            'plain_status_by_route' => $perRoute,
        ];
    }

    /**
     * @param array<string, mixed> $headers
     * @param list<string> $needles
     */
    private function checkResponse(string $label, string $at, string $body, array $headers, array $needles): void
    {
        $haystack = $body;
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $haystack = (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        foreach ($needles as $needle) {
            $pos = stripos($haystack, $needle);
            if ($pos !== false) {
                $this->findings[] = ['kind' => 'read', 'route' => $label, 'at' => $at, 'needle' => $needle,
                    'excerpt' => substr($haystack, max(0, $pos - 160), 320 + strlen($needle))];
                break;
            }
        }

        $all = $body . ' ' . json_encode($headers);
        if (preg_match_all('/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', $all, $m) > 0) {
            foreach ($m[0] as $token) {
                $claims = json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
                $tenant = is_array($claims) ? ($claims['active_tenant_id'] ?? $claims['tenant_id'] ?? null) : null;
                if ($tenant !== null && (int) $tenant !== $this->attacker) {
                    $this->findings[] = ['kind' => 'token', 'route' => $label, 'at' => $at, 'claims' => $claims];
                }
            }
        }
    }

    // ─── what the victim owns ────────────────────────────────────────────────

    /**
     * @return array<string, list<int>> table => ids of rows the tenant owns (newest first, at most 20)
     */
    private function idsOwnedBy(int $tenant): array
    {
        $ids = [];
        foreach ($this->tenantTables as $table) {
            if (!isset($this->idTables[$table])) {
                continue;
            }
            foreach ($this->column("SELECT id FROM \"{$table}\" WHERE tenant_id = {$tenant} ORDER BY id DESC LIMIT 20") as $id) {
                if (is_numeric($id)) {
                    $ids[$table][] = (int) $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Every text value only the victim holds: the strings in its tenant-owned
     * rows and in the profiles of people only it has, minus any string that
     * also appears anywhere the attacker may legitimately read.
     *
     * @return list<string>
     */
    private function victimNeedles(): array
    {
        $victimOnlyProfiles = $this->column(
            "SELECT profile_id FROM memberships GROUP BY profile_id
              HAVING bool_and(tenant_id = {$this->victim})"
        );
        $victimProfileList = $victimOnlyProfiles === [] ? '0' : implode(',', array_map('intval', $victimOnlyProfiles));

        $victimValues = [];
        $otherValues = [];
        foreach ($this->baseTables() as $table) {
            $isTenantTable = in_array($table, $this->tenantTables, true);
            $rows = $this->query("SELECT * FROM \"{$table}\"")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $owner = match (true) {
                    $isTenantTable => (isset($row['tenant_id']) && (int) $row['tenant_id'] === $this->victim) ? 'victim' : 'other',
                    $table === 'profiles' => in_array((string) $row['id'], array_map('strval', $victimOnlyProfiles), true) ? 'victim' : 'other',
                    array_key_exists('profile_id', $row) && $table !== 'memberships' => in_array((string) $row['profile_id'], array_map('strval', $victimOnlyProfiles), true) ? 'victim' : 'other',
                    default => 'other',
                };
                foreach ($row as $column => $value) {
                    if (!is_string($value) || $this->isNoise((string) $column, $value)) {
                        continue;
                    }
                    if ($owner === 'victim') {
                        $victimValues[$value] = true;
                    } else {
                        $otherValues[$value] = true;
                    }
                }
            }
        }
        unset($victimProfileList);

        $catalogue = $this->catalogueText();
        $needles = [self::VICTIM_CANARY];
        foreach (array_keys($victimValues) as $value) {
            $value = (string) $value;
            if (!isset($otherValues[$value]) && stripos($value, self::VICTIM_CANARY) === false && !str_contains($catalogue, $value)) {
                $needles[] = $value;
            }
        }

        return $needles;
    }

    /**
     * Text the product ships: every workspace may see it (a starter document's
     * name, a preset's label), so a victim row holding a copy identifies nothing.
     * The demo seeders are left out on purpose: their text is the victim's.
     */
    private function catalogueText(): string
    {
        $text = (string) file_get_contents($this->root . '/public/openapi.json');
        foreach (['src', 'plugins'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = str_replace('\\', '/', (string) $file);
                if (!str_ends_with($path, '.php') && !str_ends_with($path, '.json')) {
                    continue;
                }
                if (str_contains($path, '/Document/Demo/') || str_contains($path, '/Database/Seeders/')) {
                    continue;
                }
                $text .= "\n" . (string) file_get_contents($path);
            }
        }

        return $text;
    }

    /**
     * A value identifies the victim only if it is human text: it has a space or
     * is an address, and is long enough not to be a word every workspace shares.
     * Machine vocabulary (slugs, kinds, permission names, theme refs) is shared by
     * every workspace by design and would only produce noise.
     */
    private function isNoise(string $column, string $value): bool
    {
        $len = strlen($value);
        if ($len < 10 || $len > 300) {
            return true;
        }
        if (strpbrk($value, "\"\\\n\r\t{}[]") !== false) {
            return true; // JSON and multi-line text: escaped in a response, matched poorly
        }
        if (preg_match('/^[\d\s:.\-+TZ\/]+$/', $value) === 1) {
            return true; // dates, times, numbers
        }

        return preg_match('/\S\s+\S/u', $value) !== 1 && !str_contains($value, '@');
    }

    /**
     * REFERENCE: a row the attacker owns that points at a row the victim owns.
     *
     * The write diff only watches rows that are not the attacker's, and this is
     * the other half: the attacker's own committee naming the victim's person,
     * its own document filed in the victim's unit. Every foreign key in the
     * schema is checked, so a new table is covered the day it is added.
     */
    /** @var list<array<string, string>>|null */
    private ?array $foreignKeys = null;

    /** @param list<string>|null $fromTables only foreign keys from these tables */
    private function crossTenantReferences(string $source, ?array $fromTables = null): void
    {
        /** @var list<array<string, string>> $foreignKeys */
        $foreignKeys = $this->foreignKeys ??= array_values($this->query(
            "SELECT tc.table_name AS from_table, kcu.column_name AS from_column,
                    ccu.table_name AS to_table, ccu.column_name AS to_column
               FROM information_schema.table_constraints tc
               JOIN information_schema.key_column_usage kcu
                 ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
               JOIN information_schema.constraint_column_usage ccu
                 ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
              WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = current_schema()"
        )->fetchAll(PDO::FETCH_ASSOC));

        foreach ($foreignKeys as $fk) {
            [$from, $column, $to, $toColumn] = [$fk['from_table'], $fk['from_column'], $fk['to_table'], $fk['to_column']];
            if (!in_array($from, $this->tenantTables, true) || $column === 'tenant_id'
                || ($fromTables !== null && !in_array($from, $fromTables, true))) {
                continue;
            }
            if (in_array($to, $this->tenantTables, true)) {
                $owner = 'target.tenant_id = ' . $this->victim;
            } elseif ($to === 'profiles') {
                // A person only the victim has.
                $owner = "EXISTS (SELECT 1 FROM memberships m WHERE m.profile_id = target.\"{$toColumn}\" AND m.tenant_id = {$this->victim})"
                    . " AND NOT EXISTS (SELECT 1 FROM memberships m WHERE m.profile_id = target.\"{$toColumn}\" AND m.tenant_id <> {$this->victim})";
            } else {
                continue;
            }
            $rows = $this->query(
                "SELECT source.* FROM \"{$from}\" source
                   JOIN \"{$to}\" target ON target.\"{$toColumn}\" = source.\"{$column}\"
                  WHERE source.tenant_id = {$this->attacker} AND {$owner} LIMIT 3"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $seen = array_filter(
                    $this->findings,
                    static fn(array $f): bool => $f['kind'] === 'reference' && $f['row'] === $row && $f['column'] === $column
                );
                if ($seen === []) {
                    $this->findings[] = ['kind' => 'reference', 'route' => $source, 'table' => $from,
                        'column' => $column, 'points_at' => $to, 'row' => $row];
                }
            }
        }
    }

    // ─── snapshots ───────────────────────────────────────────────────────────

    /** @return list<string> */
    private function baseTables(): array
    {
        return array_map('strval', $this->column("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name"));
    }

    /** @var array<string, string> table => fingerprint of the rows that are not the attacker's */
    private array $othersPrint = [];
    /** @var array<string, string> tenant table => fingerprint of the attacker's rows */
    private array $attackerPrint = [];
    /** @var array<string, array<string, array{string, array<string, mixed>}>> table => key => [hash, row] */
    private array $othersRows = [];

    /**
     * Record every row that is not the attacker's (tenant-owned rows of any
     * other tenant, the system tenant included, and every row of a global
     * table), plus a fingerprint of the attacker's own rows.
     */
    private function startWatching(): void
    {
        [$this->othersPrint, $this->attackerPrint] = $this->fingerprints();
        foreach (array_keys($this->othersPrint) as $table) {
            $this->othersRows[$table] = $this->rowsNotOwnedByAttacker($table);
        }
    }

    /**
     * After a route: any table whose fingerprint moved is re-read and diffed row
     * by row (WRITE), and any tenant table whose attacker rows moved has its
     * foreign keys checked (REFERENCE). Fingerprints are one query for the
     * whole schema, so a route that wrote nothing costs one round trip.
     */
    private function checkpoint(string $route): void
    {
        [$others, $attacker] = $this->fingerprints();
        foreach ($others as $table => $print) {
            if (($this->othersPrint[$table] ?? null) === $print) {
                continue;
            }
            $rows = $this->rowsNotOwnedByAttacker($table);
            $this->diff($table, $this->othersRows[$table] ?? [], $rows, $route);
            $this->othersRows[$table] = $rows;
        }
        $moved = [];
        foreach ($attacker as $table => $print) {
            if (($this->attackerPrint[$table] ?? null) !== $print) {
                $moved[] = $table;
            }
        }
        if ($moved !== []) {
            $this->crossTenantReferences($route, $moved);
        }
        $this->othersPrint = $others;
        $this->attackerPrint = $attacker;
    }

    /** @return array{array<string, string>, array<string, string>} */
    private function fingerprints(): array
    {
        $parts = [];
        foreach ($this->baseTables() as $table) {
            if ($table === 'shared_store') {
                continue; // rate-limit and nonce counters; never a tenant's data
            }
            $isTenant = in_array($table, $this->tenantTables, true);
            $others = $isTenant ? "WHERE t.tenant_id IS DISTINCT FROM {$this->attacker}" : '';
            $parts[] = "SELECT 'o' AS side, " . $this->pdo->quote($table) . " AS tbl, "
                . "md5(coalesce(string_agg(md5(t::text), ',' ORDER BY md5(t::text)), '')) AS print FROM \"{$table}\" t {$others}";
            if ($isTenant) {
                $parts[] = "SELECT 'a', " . $this->pdo->quote($table) . ', '
                    . "md5(coalesce(string_agg(md5(t::text), ',' ORDER BY md5(t::text)), '')) FROM \"{$table}\" t WHERE t.tenant_id = {$this->attacker}";
            }
        }
        $others = [];
        $attacker = [];
        foreach ($this->query(implode("\nUNION ALL\n", $parts))->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['side'] === 'o') {
                $others[(string) $row['tbl']] = (string) $row['print'];
            } else {
                $attacker[(string) $row['tbl']] = (string) $row['print'];
            }
        }

        return [$others, $attacker];
    }

    /** @return array<string, array{string, array<string, mixed>}> key => [hash, row] */
    private function rowsNotOwnedByAttacker(string $table): array
    {
        $where = in_array($table, $this->tenantTables, true) ? "WHERE tenant_id IS DISTINCT FROM {$this->attacker}" : '';
        $keyColumns = $this->primaryKey($table);
        $rows = [];
        foreach ($this->query("SELECT * FROM \"{$table}\" {$where}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $encoded = (string) json_encode($row);
            $key = $keyColumns === []
                ? md5($encoded)
                : implode('|', array_map(static fn(string $c): string => (string) $row[$c], $keyColumns));
            $rows[$key] = [md5($encoded), $row];
        }

        return $rows;
    }

    /** @var array<string, list<string>> */
    private array $primaryKeys = [];

    /** @return list<string> */
    private function primaryKey(string $table): array
    {
        if (!isset($this->primaryKeys[$table])) {
            $this->primaryKeys[$table] = array_map('strval', $this->column(
                "SELECT kcu.column_name FROM information_schema.table_constraints tc
                   JOIN information_schema.key_column_usage kcu
                     ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
                  WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = current_schema()
                    AND tc.table_name = " . $this->pdo->quote($table) . ' ORDER BY kcu.ordinal_position'
            ));
        }

        return $this->primaryKeys[$table];
    }

    /**
     * @param array<string, array{string, array<string, mixed>}> $before
     * @param array<string, array{string, array<string, mixed>}> $after
     */
    private function diff(string $table, array $before, array $after, string $route): void
    {
        foreach ($after as $key => [$hash, $row]) {
            if (($before[$key][0] ?? null) === $hash || $this->belongsToAttacker($table, $row)) {
                continue;
            }
            $this->findings[] = ['kind' => 'write', 'route' => $route, 'table' => $table,
                'change' => isset($before[$key]) ? 'updated' : 'inserted', 'row' => $row,
                'was' => $before[$key][1] ?? null];
        }
        foreach ($before as $key => [, $row]) {
            if (!isset($after[$key]) && !$this->belongsToAttacker($table, $row)) {
                $this->findings[] = ['kind' => 'write', 'route' => $route, 'table' => $table, 'change' => 'deleted', 'was' => $row];
            }
        }
    }

    /**
     * A global-table row tied to a person who belongs to the attacker alone is
     * the attacker's own (its administrator's profile, emails, reset links).
     *
     * @param array<string, mixed> $row
     */
    private function belongsToAttacker(string $table, array $row): bool
    {
        $profile = match (true) {
            $table === 'profiles' => $row['id'] ?? null,
            array_key_exists('profile_id', $row) => $row['profile_id'],
            array_key_exists('profile_email_id', $row) => $this->scalar('SELECT profile_id FROM profile_emails WHERE id = ' . (int) $row['profile_email_id']),
            default => null,
        };
        if ($profile === null || $profile === false) {
            return false;
        }

        return (bool) $this->scalar(
            'SELECT COUNT(*) > 0 AND bool_and(tenant_id = ' . $this->attacker . ') FROM memberships WHERE profile_id = ' . (int) $profile
        );
    }

    // ─── requests ────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array{int, string, array<string, mixed>}
     */
    private function send(string $method, string $path, array $query, ?array $body, int $profile, int $tenant, array $headers = []): array
    {
        $_GET = $query;
        $_COOKIE = [];
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenFor($profile, $tenant),
            'Content-Type' => 'application/json',
            'Host' => 'localhost',
            'X-Requested-With' => 'XMLHttpRequest',
        ] + $headers;
        $request = new Request($method, $path . ($query === [] ? '' : '?' . http_build_query($query)), $headers, $body === null ? '' : (string) json_encode($body));

        try {
            $response = $this->kernel->handle($request);

            return [$response->getStatusCode(), $response->getBody(), $response->getHeaders()];
        } catch (\Throwable $e) {
            return [599, get_class($e) . ': ' . $e->getMessage(), []];
        } finally {
            ($this->resetRequest)();
        }
    }

    /** A fresh access token exactly as sign-in mints it, re-read each time (a request may bump the epoch). */
    private function tokenFor(int $profile, int $tenant): string
    {
        $row = $this->query(
            "SELECT p.token_epoch, e.email, r.name AS role
               FROM profiles p
               LEFT JOIN profile_emails e ON e.profile_id = p.id AND e.is_primary
               LEFT JOIN memberships m ON m.profile_id = p.id AND m.tenant_id = {$tenant}
               LEFT JOIN roles r ON r.id = m.role_id
              WHERE p.id = {$profile}
              ORDER BY m.is_primary DESC NULLS LAST LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return $this->jwt->create([
            'profile_id' => $profile,
            'active_tenant_id' => $tenant,
            'email' => (string) ($row['email'] ?? ''),
            'role' => (string) ($row['role'] ?? ''),
            'token_epoch' => (int) ($row['token_epoch'] ?? 0),
        ], 900, 'access');
    }

    // ─── paths and bodies ────────────────────────────────────────────────────

    private function loadSpec(): void
    {
        /** @var array<string, mixed> $spec */
        $spec = json_decode((string) file_get_contents($this->root . '/public/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->spec = $spec;
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (is_array($operation)) {
                    $this->specOps[strtoupper((string) $method) . ' ' . self::normalise((string) $path)] = $operation;
                }
            }
        }
    }

    private static function normalise(string $path): string
    {
        return (string) preg_replace('/\{([a-zA-Z_]+)(:[^}]*)?\}/', '{$1}', $path);
    }

    /** @return list<string> */
    private static function paramNames(string $path): array
    {
        return preg_match_all('/\{([a-zA-Z_]+)(:[^}]*)?\}/', $path, $m) > 0 ? $m[1] : [];
    }

    /** @param array<string, int|string> $params */
    private function fill(string $path, array $params): string
    {
        return (string) preg_replace_callback(
            '/\{([a-zA-Z_]+)(:[^}]*)?\}/',
            static fn(array $m): string => (string) ($params[$m[1]] ?? '1'),
            $path
        );
    }

    /**
     * Up to $cap parameter sets, each param filled from the victim table the
     * path names (`/roles/{id}` -> roles); a param no table matches gets ids
     * from every victim table in turn.
     *
     * @param array<string, list<int>> $ids
     * @return list<array<string, int>>
     */
    private function candidates(string $path, array $ids, int $cap): array
    {
        $names = self::paramNames($path);
        if ($names === []) {
            return [[]];
        }
        $flat = array_values(array_unique(array_merge([], ...array_values($ids))));
        $lists = [];
        foreach ($names as $name) {
            preg_match('#/([a-z0-9_-]+)/\{' . $name . '[:}]#', $path, $m);
            $table = ($name !== 'id' ? $this->tableFor($name, $ids) : null) ?? (isset($m[1]) ? $this->tableFor($m[1], $ids) : null);
            $lists[$name] = $table !== null ? $ids[$table] : $flat;
        }
        $out = [];
        for ($i = 0; $i < $cap; $i++) {
            $set = [];
            foreach ($lists as $name => $list) {
                $set[$name] = $list === [] ? 1 : $list[$i % count($list)];
            }
            $out[] = $set;
        }

        return array_values(array_unique($out, SORT_REGULAR));
    }

    /** @param array<string, list<int>> $ids */
    private function tableFor(string $hint, array $ids): ?string
    {
        $h = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', str_replace('-', '_', $hint)));
        $h = (string) preg_replace('/_?id$/', '', $h);
        if ($h === '') {
            return null;
        }
        foreach ([$h, $h . 's', $h . 'es', preg_replace('/y$/', 'ies', $h)] as $candidate) {
            if (isset($ids[$candidate])) {
                return $candidate;
            }
        }
        foreach (array_keys($ids) as $table) {
            if (str_ends_with($table, '_' . $h . 's') || str_ends_with($table, '_' . $h)) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Integer for a body field: a victim row's id for an id field, the target
     * tenant for a tenant field.
     *
     * @param array<string, list<int>> $ids
     * @return \Closure(string): int
     */
    private function intChooser(array $ids, ?int $tenantTarget): \Closure
    {
        $flat = array_values(array_unique(array_merge([], ...array_values($ids))));

        return function (string $key) use ($ids, $flat, $tenantTarget): int {
            if ($tenantTarget !== null && stripos($key, 'tenant') !== false) {
                return $tenantTarget;
            }
            if (preg_match('/^(.*?)_?[iI]ds?$/', $key, $m) === 1 && $m[1] !== '') {
                $table = $this->tableFor($m[1], $ids);
                if ($table !== null) {
                    return $ids[$table][0];
                }

                return $flat === [] ? 1 : $flat[crc32($key) % count($flat)];
            }

            return 1;
        };
    }

    /**
     * @param array<string, mixed> $route
     * @param \Closure(string): int $intFor
     * @return array<string, mixed>|null
     */
    private function bodyFor(array $route, \Closure $intFor, string $canary, bool $full): ?array
    {
        $operation = $this->specOps[$route['method'] . ' ' . self::normalise($route['path'])] ?? null;
        $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
        if ($schema === null) {
            return null;
        }
        $body = $this->synth($schema, '', $intFor, $canary, $full, 0);

        return is_array($body) ? $body : null;
    }

    /** @param \Closure(string): int $intFor */
    private function synth(mixed $schema, string $key, \Closure $intFor, string $canary, bool $full, int $depth): mixed
    {
        if ($depth > 5) {
            return null;
        }
        $s = $this->deref($schema);
        foreach (['allOf', 'oneOf', 'anyOf'] as $combinator) {
            if (isset($s[$combinator][0])) {
                $s = $this->deref($s[$combinator][0]) + $s;
            }
        }
        if (isset($s['enum'][0])) {
            return $s['enum'][0];
        }
        // An example is reused only when it is machine vocabulary (a ref, a slug):
        // human-text examples also appear in the catalogue, so as victim data
        // they would be indistinguishable from what every workspace sees.
        if (array_key_exists('example', $s) && preg_match('/(_id|Id|_ids|Ids|tenant)$/', $key) !== 1
            && (!is_string($s['example']) || preg_match('/\s/', $s['example']) !== 1)) {
            return $s['example'];
        }
        $type = $s['type'] ?? (isset($s['properties']) ? 'object' : 'string');
        if (is_array($type)) {
            $type = array_values(array_filter($type, static fn($t) => $t !== 'null'))[0] ?? 'string';
        }

        switch ($type) {
            case 'object':
                $object = [];
                $required = $s['required'] ?? [];
                foreach (($s['properties'] ?? []) as $property => $propertySchema) {
                    if ($full || in_array($property, $required, true)) {
                        $object[$property] = $this->synth($propertySchema, (string) $property, $intFor, $canary, $full, $depth + 1);
                    }
                }

                return $object;
            case 'array':
                return [$this->synth($s['items'] ?? [], $key, $intFor, $canary, $full, $depth + 1)];
            case 'integer':
            case 'number':
                return $intFor($key);
            case 'boolean':
                return true;
            default:
                $format = $s['format'] ?? '';
                return match (true) {
                    $format === 'email' || stripos($key, 'email') !== false => $canary . bin2hex(random_bytes(3)) . '@probe.example',
                    $format === 'date' => '2030-01-15',
                    $format === 'date-time' || preg_match('/(_at|At)$/', $key) === 1 => '2030-01-15T10:00:00Z',
                    $format === 'uri' || stripos($key, 'url') !== false => 'https://probe.example/' . $canary,
                    preg_match('/slug|key|code|locale|domain/i', $key) === 1 => $canary . '_' . bin2hex(random_bytes(2)),
                    default => $canary . ' ' . $key,
                };
        }
    }

    /** @return array<string, mixed> */
    private function deref(mixed $schema): array
    {
        if (!is_array($schema)) {
            return [];
        }
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $name = substr($schema['$ref'], strlen('#/components/schemas/'));

            return $this->deref($this->spec['components']['schemas'][$name] ?? []);
        }

        return $schema;
    }

    // ─── sql helpers ─────────────────────────────────────────────────────────

    private function query(string $sql): \PDOStatement
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Query failed: ' . $sql);
        }

        return $statement;
    }

    /** @return list<mixed> */
    private function column(string $sql): array
    {
        return array_values($this->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    private function scalar(string $sql): mixed
    {
        return $this->query($sql)->fetchColumn();
    }
}
