<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Audit\AuditPresenter;
use Whity\Core\Audit\AuditSentences;
use Whity\Core\i18n\ServerLabels;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\CsvCell;
use Whity\Http\PaginationParams;
use Whity\Sdk\Http\StreamedResponse;

/**
 * Audit Log API Handler (WC-34).
 *
 * Exposes the security audit trail as a queryable, RBAC-protected, tenant-scoped
 * read endpoint: `GET /api/audit-logs`.
 *
 * Tenant scoping
 * --------------
 * Every query is scoped to the caller's tenant via {@see TenantContext}. The
 * SYSTEM tenant (id 0) — the platform administrator — sees entries across ALL
 * tenants; every other tenant sees ONLY its own rows. There is no path that
 * returns another tenant's audit data to a regular tenant, and an unresolved
 * tenant context fails closed (403). This mirrors the visibility rules of the
 * other admin handlers.
 *
 * Authorization
 * -------------
 * The route is gated on the {@see CorePermissions::AUDIT_READ} permission by the
 * {@see \Whity\Http\RbacMiddleware}. This handler ALSO re-checks the permission
 * against the authoritative store ({@see RoleChecker}) as defence in depth, so
 * the endpoint stays safe even if it were ever mounted without the route-level
 * guard.
 *
 * Filtering & pagination
 * ----------------------
 * Optional query parameters: `action`, `actor` (actor_user_id), `agent` (an agent
 * principal), `area`, `target_type`, `target_id`, `from` / `to` (inclusive ISO-8601
 * date or datetime bounds on `created_at`), `page` and `per_page`. Results are newest-first
 * (`created_at DESC, id DESC`). The response carries the page slice under
 * `data` and a `pagination` block.
 *
 * `target_id` (#882) is what makes "the history of THIS record" expressible.
 * Without it the trail could be narrowed to a KIND of thing — every role, every
 * user — but never to one row, so a record page had no way to ask for its own
 * entries and would have had to page the whole tenant's trail and filter
 * client-side. It is normally paired with `target_type`, and the two compose as
 * a conjunction like every other filter here; supplied alone it matches that id
 * across every target type, which is meaningful when `action` already pins the
 * kind (`action=role.updated&target_id=7`) and is the caller's business
 * otherwise. It is deliberately NOT a widening parameter: it can only ever
 * narrow rows the tenant scope has already admitted.
 *
 * Every entry also says what happened in words (W.4), from
 * {@see AuditPresenter}. `GET /api/audit-logs/export` writes the same rows, with
 * the same scope and filters, as a CSV that is safe to open in a spreadsheet.
 *
 * No direct DB writes happen here — this is a read-only handler; the single
 * writer is {@see \Whity\Core\Audit\AuditLogger}.
 */
final class AuditLogApiHandler
{
    /**
     * The system tenant id; a caller resolved to it sees every tenant's rows.
     */
    private const SYSTEM_TENANT_ID = 0;

    /** A bigger export is refused and asks for a narrower filter, never cut short. */
    public const EXPORT_MAX_ROWS = 50000;

    private const EXPORT_BATCH = 1000;

    private const COLUMNS = 'id, tenant_id, actor_user_id, actor_kind, agent_principal_id, on_behalf_of_profile_id,
                    action, target_type, target_id, metadata, ip_address, created_at';

    private PDO $db;
    private RoleChecker $roleChecker;
    private AuditPresenter $presenter;

    /**
     * @param PDO         $db          Database connection.
     * @param RoleChecker $roleChecker Authoritative RBAC resolver for the defence-in-depth check.
     * @param ServerLabels|null $labels Words in the caller's language; English without it.
     * @param AuditLogger|null  $audit  Records that the trail itself was exported.
     */
    public function __construct(
        PDO $db,
        RoleChecker $roleChecker,
        private readonly ?ServerLabels $labels = null,
        private readonly ?AuditLogger $audit = null,
    ) {
        $this->db = $db;
        $this->roleChecker = $roleChecker;
        $this->presenter = new AuditPresenter($db, $labels);
    }

    /**
     * GET /api/audit-logs — list audit entries for the caller's tenant.
     *
     * @param Request $request The incoming request.
     * @return Response JSON `{ data: [...], pagination: {...} }` (200) or an error.
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();

            // Fail closed when the tenant context is unresolved.
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            // Defence in depth: re-assert the permission against the store. The
            // acting user id is attached to the request by the middleware.
            $actor = $request->user;
            $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
                ? $actor->profile_id
                : null;
            if ($userId === null || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::AUDIT_READ, $tenantId)) {
                return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::AUDIT_READ]);
            }

            $query = $this->queryOf($request);
            [$where, $params] = $this->filters($tenantId, $query);

            // Total count for pagination metadata.
            // @tenant-guard-ignore: tenant_id predicate is appended to $where only for non-system tenants; system tenant (id 0) reads all audit rows by design
            $countStmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM audit_log' . $where);
            $countStmt->execute($params);
            $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
            $total = $countRow !== false ? (int) ($countRow['cnt'] ?? 0) : 0;

            $p = PaginationParams::fromQuery($query);

            // Newest-first; bound LIMIT/OFFSET as integers (cast, not interpolated
            // arbitrary input — both are validated non-negative ints above).
            // @tenant-guard-ignore: tenant_id predicate is appended to $where only for non-system tenants; system tenant (id 0) reads all audit rows by design
            $listStmt = $this->db->prepare(
                'SELECT ' . self::COLUMNS . '
                 FROM audit_log' . $where . '
                 ORDER BY created_at DESC, id DESC
                 LIMIT :limit OFFSET :offset'
            );
            foreach ($params as $key => $value) {
                $listStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $listStmt->bindValue(':limit', $p->perPage, PDO::PARAM_INT);
            $listStmt->bindValue(':offset', $p->offset, PDO::PARAM_INT);
            $listStmt->execute();

            /** @var list<array<string, mixed>> $rows */
            $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json([
                'data' => $this->toPublicEntries($rows),
                'pagination' => $p->meta($total),
            ], 200);
        } catch (\Exception $e) {
            error_log('[AuditLogApiHandler] list failed: ' . $e->getMessage());
            return Response::error('Failed to fetch audit logs', 500);
        }
    }

    /**
     * GET /api/audit-logs/facets — who and which agents the filters can offer, by
     * name, and the areas. Only people and agents in rows the caller could already
     * read, so this can't list anybody the trail itself wouldn't show them.
     */
    public function facets(Request $request): Response
    {
        try {
            $tenantId = $this->authorizedTenant($request);
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            [$where, $params] = $this->filters($tenantId, []);
            $and = $where === '' ? ' WHERE ' : $where . ' AND ';

            // @tenant-guard-ignore: tenant_id predicate is in $where for every tenant but the system tenant, which reads all audit rows by design
            $actors = $this->db->prepare(
                'SELECT DISTINCT actor_user_id FROM audit_log' . $and . 'actor_user_id IS NOT NULL LIMIT 500'
            );
            $actors->execute($params);

            // @tenant-guard-ignore: tenant_id predicate is in $where for every tenant but the system tenant, which reads all audit rows by design
            $principals = $this->db->prepare(
                'SELECT DISTINCT agent_principal_id FROM audit_log' . $and . 'agent_principal_id IS NOT NULL LIMIT 500'
            );
            $principals->execute($params);

            /** @var list<mixed> $actorIds */
            $actorIds = $actors->fetchAll(PDO::FETCH_COLUMN);
            /** @var list<mixed> $principalIds */
            $principalIds = $principals->fetchAll(PDO::FETCH_COLUMN);
            $facets = $this->presenter->facets(
                array_map('intval', $actorIds),
                array_map('strval', $principalIds),
            );

            $areas = [];
            foreach (AuditSentences::AREAS as $area) {
                $areas[] = ['id' => $area, 'name' => $this->areaLabel($area)];
            }

            return Response::json(['data' => $facets + ['areas' => $areas]], 200);
        } catch (\Exception $e) {
            error_log('[AuditLogApiHandler] facets failed: ' . $e->getMessage());
            return Response::error('Failed to fetch audit filters', 500);
        }
    }

    /**
     * GET /api/audit-logs/export — the rows {@see self::list()} would show, as a
     * CSV. Same tenant scope, same permission, same filters.
     *
     * `technical=1` adds the codes, ids and IP addresses, as Show technical
     * details does on the page.
     *
     * The file is built before anything is sent, in temp storage rather than
     * memory. A failure halfway is then a proper error, not a download that looks
     * complete and isn't, and the words are still in the caller's language.
     *
     * @return \Whity\Sdk\Http\Response
     */
    public function export(Request $request): \Whity\Sdk\Http\Response
    {
        try {
            $tenantId = $this->authorizedTenant($request);
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $query = $this->queryOf($request);
            [$where, $params] = $this->filters($tenantId, $query);

            // @tenant-guard-ignore: tenant_id predicate is in $where for every tenant but the system tenant, which reads all audit rows by design
            $count = $this->db->prepare('SELECT COUNT(*) FROM audit_log' . $where);
            $count->execute($params);
            $total = (int) $count->fetchColumn();
            if ($total > self::EXPORT_MAX_ROWS) {
                return Response::error(
                    'That is too many entries to export at once. Choose a shorter period.',
                    422,
                    ['total' => $total, 'limit' => self::EXPORT_MAX_ROWS]
                );
            }

            $stream = fopen('php://temp/maxmemory:' . (5 * 1024 * 1024), 'w+b');
            if ($stream === false) {
                return Response::error('Failed to export audit logs', 500);
            }
            $this->writeExport($stream, $tenantId, $query);
            rewind($stream);

            $this->audit?->record('audit.exported', [
                'target_type' => 'audit_log',
                'tenant_id' => $tenantId,
                'metadata' => ['rows' => $total, 'filters' => array_intersect_key($query, array_flip(
                    ['action', 'actor', 'agent', 'area', 'target_type', 'target_id', 'from', 'to', 'technical']
                ))],
            ]);

            return new StreamedResponse(
                200,
                static function () use ($stream): void {
                    fpassthru($stream);
                    fclose($stream);
                },
                [
                    'Content-Type' => 'text/csv; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="audit-log-' . gmdate('Y-m-d') . '.csv"',
                    'Cache-Control' => 'no-store',
                ]
            );
        } catch (\Exception $e) {
            error_log('[AuditLogApiHandler] export failed: ' . $e->getMessage());
            return Response::error('Failed to export audit logs', 500);
        }
    }

    /**
     * Write the matching rows to a stream, newest first, a batch at a time so a
     * big export doesn't hold every row in memory.
     *
     * @param resource              $stream
     * @param array<string, string> $query
     */
    public function writeExport($stream, int $tenantId, array $query): void
    {
        [$where, $params] = $this->filters($tenantId, $query);
        $technical = ($query['technical'] ?? '') === '1';
        $isSystem = $tenantId === self::SYSTEM_TENANT_ID;

        // A byte order mark, or Excel reads the Arabic as mojibake.
        fwrite($stream, "\xEF\xBB\xBF");

        $header = [
            $this->label('audit.csv.when', 'When (UTC)'),
            $this->label('audit.csv.what', 'What happened'),
            $this->label('audit.csv.who', 'Who'),
            $this->label('audit.csv.agent', 'Agent'),
            $this->label('audit.csv.area', 'Area'),
            $this->label('audit.csv.how', 'How'),
        ];
        if ($isSystem) {
            $header[] = $this->label('audit.csv.workspace', 'Workspace');
        }
        if ($technical) {
            $header[] = $this->label('audit.csv.code', 'Event code');
            $header[] = $this->label('audit.csv.targetType', 'Target type');
            $header[] = $this->label('audit.csv.targetId', 'Target id');
            $header[] = $this->label('audit.csv.ip', 'IP address');
        }
        CsvCell::writeRow($stream, $header);

        $areaNames = [];
        foreach (AuditSentences::AREAS as $area) {
            $areaNames[$area] = $this->areaLabel($area);
        }

        $and = $where === '' ? ' WHERE ' : $where . ' AND ';
        $before = null;
        do {
            // @tenant-guard-ignore: tenant_id predicate is in $where for every tenant but the system tenant, which reads all audit rows by design
            $stmt = $this->db->prepare(
                'SELECT ' . self::COLUMNS . ' FROM audit_log'
                . ($before === null ? $where : $and . 'id < :before')
                . ' ORDER BY id DESC LIMIT :limit'
            );
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            if ($before !== null) {
                $stmt->bindValue(':before', $before, PDO::PARAM_INT);
            }
            $stmt->bindValue(':limit', self::EXPORT_BATCH, PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $presented = $this->presenter->present($rows);
            $workspaces = $isSystem ? $this->workspaceNames($rows) : [];

            foreach ($rows as $i => $row) {
                $words = $presented[$i];
                $cells = [
                    (string) ($row['created_at'] ?? ''),
                    $words['sentence'],
                    $words['actor']['forPerson'] ?? $words['actor']['name'],
                    $words['actor']['agent'] ?? '',
                    $areaNames[$words['area']] ?? $words['area'],
                    $words['how'] ?? '',
                ];
                if ($isSystem) {
                    $cells[] = $workspaces[(int) $row['tenant_id']] ?? '';
                }
                if ($technical) {
                    $cells[] = (string) ($row['action'] ?? '');
                    $cells[] = (string) ($row['target_type'] ?? '');
                    $cells[] = (string) ($row['target_id'] ?? '');
                    $cells[] = (string) ($row['ip_address'] ?? '');
                }
                CsvCell::writeRow($stream, $cells);
                $before = (int) $row['id'];
            }
        } while (count($rows) === self::EXPORT_BATCH);
    }

    /**
     * GET /api/me/audit-logs — the CALLER's own activity feed.
     *
     * Self-service analogue of {@see self::list()}: no AUDIT_READ permission is
     * required (every authenticated user may see their own history), and
     * `actor_user_id` is pinned to the caller — the `actor` filter from the
     * admin endpoint does not apply here; a caller can never widen this to see
     * another profile's rows. Tenant-scoped the same way (system tenant, id 0,
     * still sees only ITS OWN actions across tenants — self-service is about
     * "who did this", not "which tenant", so system-tenant callers are not
     * widened to every tenant's audit rows the way admin `list()` widens them).
     *
     * @param Request $request The incoming request.
     * @return Response JSON `{ data: [...], pagination: {...} }` (200) or an error.
     */
    public function listOwn(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            $actor = $request->user;
            $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
                ? $actor->profile_id
                : null;
            if ($userId === null) {
                return Response::error('Unauthorized', 401);
            }

            $query = $this->queryOf($request);

            // Always scoped to this tenant AND this actor — neither is
            // caller-controlled, unlike the admin endpoint's optional filters.
            $conditions = ['tenant_id = :tenant_id', 'actor_user_id = :actor_user_id'];
            $params = [':tenant_id' => $tenantId, ':actor_user_id' => $userId];

            if (isset($query['action']) && $query['action'] !== '') {
                $conditions[] = 'action = :action';
                $params[':action'] = (string) $query['action'];
            }

            if (isset($query['target_type']) && $query['target_type'] !== '') {
                $conditions[] = 'target_type = :target_type';
                $params[':target_type'] = (string) $query['target_type'];
            }

            // Same narrowing filter as the admin endpoint (#882) — kept symmetric
            // deliberately. The two are documented as analogues differing only in
            // `actor` (pinned to the caller here, so it cannot be widened); an
            // extra asymmetry would need a reason, and "the record page happened
            // to call the other one" is not one.
            if (isset($query['target_id']) && ctype_digit((string) $query['target_id'])) {
                $conditions[] = 'target_id = :target_id';
                $params[':target_id'] = (int) $query['target_id'];
            }

            if (isset($query['from']) && $query['from'] !== '') {
                $conditions[] = 'created_at >= :from';
                $params[':from'] = (string) $query['from'];
            }

            if (isset($query['to']) && $query['to'] !== '') {
                $conditions[] = 'created_at <= :to';
                $params[':to'] = (string) $query['to'];
            }

            // @tenant-guard-ignore: $conditions ALWAYS begins with 'tenant_id = :tenant_id' (set unconditionally above); every query below binds it. The scanner cannot resolve the imploded builder array, so it must be told this WHERE clause is tenant-scoped. Covers both queries that consume $where.
            $where = ' WHERE ' . implode(' AND ', $conditions);

            $countStmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM audit_log' . $where);
            $countStmt->execute($params);
            $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
            $total = $countRow !== false ? (int) ($countRow['cnt'] ?? 0) : 0;

            $p = PaginationParams::fromQuery($query);

            $listStmt = $this->db->prepare(
                'SELECT ' . self::COLUMNS . '
                 FROM audit_log' . $where . '
                 ORDER BY created_at DESC, id DESC
                 LIMIT :limit OFFSET :offset'
            );
            foreach ($params as $key => $value) {
                $listStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $listStmt->bindValue(':limit', $p->perPage, PDO::PARAM_INT);
            $listStmt->bindValue(':offset', $p->offset, PDO::PARAM_INT);
            $listStmt->execute();

            /** @var list<array<string, mixed>> $rows */
            $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json([
                'data' => $this->toPublicEntries($rows),
                'pagination' => $p->meta($total),
            ], 200);
        } catch (\Exception $e) {
            error_log('[AuditLogApiHandler] listOwn failed: ' . $e->getMessage());
            return Response::error('Failed to fetch audit logs', 500);
        }
    }

    /**
     * The tenant to read, once the caller is confirmed to hold audit:read in it.
     *
     * @return int|Response
     */
    private function authorizedTenant(Request $request): int|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
        if ($userId === null || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::AUDIT_READ, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::AUDIT_READ]);
        }

        return $tenantId;
    }

    /**
     * Query params from both $_GET (production) and the path query string (tests).
     * This mirrors PaginationParams::fromPath internals.
     *
     * @return array<string, string>
     */
    private function queryOf(Request $request): array
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $query[$k] = $v;
                }
            }
        }

        return $query;
    }

    /**
     * The WHERE clause: always tenant-scoped (system tenant sees all), plus any
     * supplied filters. All values are bound, never interpolated.
     *
     * @param array<string, string> $query
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function filters(int $tenantId, array $query): array
    {
        $conditions = [];
        $params = [];

        if ($tenantId !== self::SYSTEM_TENANT_ID) {
            $conditions[] = 'tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }

        if (isset($query['action']) && $query['action'] !== '') {
            // A trailing dot asks for a family (`notification.`), which is how another
            // page links to its own trail. No action code ends in a dot, so an exact
            // match could only ever have come back empty.
            $action = (string) $query['action'];
            if (str_ends_with($action, '.')) {
                $conditions[] = "action LIKE :action ESCAPE '\'";
                $params[':action'] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $action) . '%';
            } else {
                $conditions[] = 'action = :action';
                $params[':action'] = $action;
            }
        }

        if (isset($query['target_type']) && $query['target_type'] !== '') {
            $conditions[] = 'target_type = :target_type';
            $params[':target_type'] = (string) $query['target_type'];
        }

        if (isset($query['actor']) && ctype_digit((string) $query['actor'])) {
            $conditions[] = 'actor_user_id = :actor';
            $params[':actor'] = (int) $query['actor'];
        }

        if (isset($query['agent']) && $query['agent'] !== '') {
            $conditions[] = 'agent_principal_id = :agent';
            $params[':agent'] = (string) $query['agent'];
        }

        // Bound as an INT after a digit check, like `actor` above: the column
        // is an integer and a non-numeric value is dropped rather than
        // compared, so no cast error can reach the driver.
        if (isset($query['target_id']) && ctype_digit((string) $query['target_id'])) {
            $conditions[] = 'target_id = :target_id';
            $params[':target_id'] = (int) $query['target_id'];
        }

        if (isset($query['from']) && $query['from'] !== '') {
            $conditions[] = 'created_at >= :from';
            $params[':from'] = (string) $query['from'];
        }

        if (isset($query['to']) && $query['to'] !== '') {
            $conditions[] = 'created_at <= :to';
            $params[':to'] = (string) $query['to'];
        }

        if (isset($query['area']) && $query['area'] !== '') {
            $this->areaFilter((string) $query['area'], $conditions, $params);
        }

        return [$conditions === [] ? '' : (' WHERE ' . implode(' AND ', $conditions)), $params];
    }

    /**
     * Narrow to one area. An area nobody knows matches nothing rather than being
     * dropped, because dropping a filter would show more than was asked for.
     *
     * @param list<string>              $conditions
     * @param array<string, int|string> $params
     */
    private function areaFilter(string $area, array &$conditions, array &$params): void
    {
        if (!in_array($area, AuditSentences::AREAS, true)) {
            $conditions[] = '1 = 0';

            return;
        }

        $like = static function (string $prefix, int $i, string $name) use (&$params): string {
            $param = ':' . $name . $i;
            // `_` is a wildcard to LIKE, and action prefixes contain it.
            $params[$param] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';

            return "action LIKE {$param} ESCAPE '\\'";
        };

        $own = AuditSentences::prefixes($area);
        $excluded = $own === null ? AuditSentences::allPrefixes() : AuditSentences::earlierPrefixes($area);

        if ($own !== null) {
            $any = [];
            foreach ($own as $i => $prefix) {
                $any[] = $like($prefix, $i, 'area');
            }
            $conditions[] = '(' . implode(' OR ', $any) . ')';
        }
        foreach ($excluded as $i => $prefix) {
            $conditions[] = 'NOT ' . $like($prefix, $i, 'not_area');
        }
    }

    private function areaLabel(string $area): string
    {
        $english = [
            'security' => 'Sign-in and security',
            'people' => 'People',
            'documents' => 'Documents',
            'agents' => 'AI and agents',
            'plugins' => 'Plugins',
            'billing' => 'Billing',
            'workspace' => 'Workspace',
            'other' => 'Other',
        ];

        return $this->label('audit.area.' . $area, $english[$area] ?? $area);
    }

    private function label(string $key, string $english): string
    {
        return $this->labels?->label('admin', $key, $english) ?? $english;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function workspaceNames(array $rows): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['tenant_id'], $rows)));
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT id, name FROM tenants WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);

        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function toPublicEntries(array $rows): array
    {
        $presented = $this->presenter->present($rows);

        return array_map(
            fn (array $row, array $words): array => $this->toPublicEntry($row) + $words,
            $rows,
            $presented,
        );
    }

    /**
     * Shape a raw audit_log row into the public API contract.
     *
     * Casts integer-like columns (PDO returns them as strings under Postgres) and
     * decodes the JSON metadata so the client receives a structured object. The
     * shape uses camelCase keys for the fields the frontend binds.
     *
     * @param array<string, mixed> $row A raw audit_log row.
     * @return array<string, mixed> The public entry.
     */
    private function toPublicEntry(array $row): array
    {
        $metadata = [];
        if (isset($row['metadata']) && is_string($row['metadata']) && $row['metadata'] !== '') {
            $decoded = json_decode($row['metadata'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        // The published contract (WC-167) declares metadata: object. An empty
        // PHP array would JSON-encode as [] — emit a real empty OBJECT instead.
        $publicMetadata = $metadata === [] ? new \stdClass() : $metadata;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'tenantId' => isset($row['tenant_id']) ? (int) $row['tenant_id'] : null,
            'actorUserId' => isset($row['actor_user_id'])
                ? (int) $row['actor_user_id']
                : null,
            'action' => (string) ($row['action'] ?? ''),
            'targetType' => isset($row['target_type'])
                ? (string) $row['target_type']
                : null,
            'targetId' => isset($row['target_id'])
                ? (int) $row['target_id']
                : null,
            'metadata' => $publicMetadata,
            'ipAddress' => isset($row['ip_address'])
                ? (string) $row['ip_address']
                : null,
            'createdAt' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        ];
    }

}
