<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\CustomerSafeRole;
use Whity\Core\Plugin\PublicForm\BotCheck;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Signup\CustomerSignupService;
use Whity\Core\Signup\WorkspaceSignupSettings;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;

/**
 * The workspace admin's side of customer sign-up (WHIT-915): Settings → Sign-up
 * and the queue of people waiting to join.
 *
 *   GET  /api/v1/signup/settings
 *   PUT  /api/v1/signup/settings
 *   GET  /api/v1/signup/requests?status=pending|approved|rejected
 *   POST /api/v1/signup/requests/{id}/approve
 *   POST /api/v1/signup/requests/{id}/reject
 *
 * Every route needs `signup:manage` IN THE CALLER'S OWN WORKSPACE, and every
 * statement binds that workspace. This is the tenant-scoped counterpart of the
 * operator's {@see RegistrationsApiHandler}, which stays system-tenant only.
 */
final class WorkspaceSignupApiHandler
{
    private const REQUEST_STATUSES = [
        CustomerSignupService::STATUS_PENDING,
        CustomerSignupService::STATUS_APPROVED,
        CustomerSignupService::STATUS_REJECTED,
    ];

    private const ROLE_REFUSED =
        'Choose a starting role with no admin permissions. A role that can manage the workspace, its people or its settings cannot be given to customers.';

    public function __construct(
        private readonly PDO $db,
        private readonly RoleChecker $roleChecker,
        private readonly WorkspaceSignupSettings $settings,
        private readonly CustomerSafeRole $safeRoles,
        private readonly CustomerSignupService $service,
        private readonly AuditLogger $audit,
        private readonly ?BotCheck $bot = null,
    ) {
    }

    public function getSettings(Request $request): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        try {
            return Response::json(['data' => $this->view($ctx['tenantId'])], 200);
        } catch (\Throwable $e) {
            error_log('[customer-signup] settings read failed: ' . $e->getMessage());

            return Response::error('Failed to read sign-up settings', 500);
        }
    }

    public function updateSettings(Request $request): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $tenantId = $ctx['tenantId'];

        $body = JsonBody::parsed($request);
        $current = $this->settings->get($tenantId);

        $mode = array_key_exists('mode', $body) ? $body['mode'] : $current['mode'];
        if (!is_string($mode) || !in_array($mode, WorkspaceSignupSettings::MODES, true)) {
            return Response::error('Validation failed', 422, ['mode' => 'Choose Off, Approval required or Open.']);
        }

        $roleId = array_key_exists('role_id', $body) ? $body['role_id'] : $current['role_id'];
        if ($roleId !== null && (!is_int($roleId) || $roleId <= 0)) {
            return Response::error('Validation failed', 422, ['role_id' => 'Choose a role.']);
        }
        if ($roleId === null && $mode !== WorkspaceSignupSettings::MODE_OFF) {
            return Response::error('Validation failed', 422, ['role_id' => 'Choose the role people get when they join.']);
        }
        if ($roleId !== null && !$this->safeRoles->isCustomerSafe($roleId, $tenantId)) {
            return Response::error('Validation failed', 422, ['role_id' => self::ROLE_REFUSED]);
        }

        $domains = WorkspaceSignupSettings::normaliseDomains(
            array_key_exists('allowed_domains', $body) ? $body['allowed_domains'] : $current['allowed_domains']
        );
        if ($domains === null) {
            return Response::error('Validation failed', 422, [
                'allowed_domains' => 'Enter domain names such as example.com, up to ' . WorkspaceSignupSettings::MAX_DOMAINS . '.',
            ]);
        }

        try {
            $this->settings->save($tenantId, $mode, $roleId, $domains, $ctx['userId']);
        } catch (\Throwable $e) {
            error_log('[customer-signup] settings save failed: ' . $e->getMessage());

            return Response::error('Failed to save sign-up settings', 500);
        }

        $this->audit->record('tenant.signup.settings_updated', [
            'tenant_id' => $tenantId,
            'actor_user_id' => $ctx['userId'],
            'target_type' => 'tenant',
            'target_id' => $tenantId,
            'metadata' => [
                'mode' => $mode,
                'role_id' => $roleId,
                'allowed_domains' => $domains,
                'previous_mode' => $current['mode'],
            ],
        ]);

        return Response::json(['data' => $this->view($tenantId)], 200);
    }

    public function listRequests(Request $request): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $status = $this->queryParam($request, 'status') ?? CustomerSignupService::STATUS_PENDING;
        if (!in_array($status, self::REQUEST_STATUSES, true)) {
            return Response::error('Validation failed', 422, ['status' => 'Use pending, approved or rejected.']);
        }

        try {
            $p = PaginationParams::fromPath($request->getPath());
            $page = $this->service->listForTenant($ctx['tenantId'], $status, $p->perPage, $p->offset);

            return Response::json(['data' => $page['items'], 'pagination' => $p->meta($page['total'])], 200);
        } catch (\Throwable $e) {
            error_log('[customer-signup] list failed: ' . $e->getMessage());

            return Response::error('Failed to list sign-up requests', 500);
        }
    }

    /** @param array<string, mixed> $params */
    public function approve(Request $request, array $params = []): Response
    {
        return $this->decide($request, $params, true);
    }

    /** @param array<string, mixed> $params */
    public function reject(Request $request, array $params = []): Response
    {
        return $this->decide($request, $params, false);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $params */
    private function decide(Request $request, array $params, bool $approve): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::error('A valid request id is required', 422);
        }

        try {
            $result = $approve
                ? $this->service->approve($id, $ctx['tenantId'], $ctx['userId'])
                : $this->service->reject($id, $ctx['tenantId'], $ctx['userId']);
        } catch (\Throwable $e) {
            error_log('[customer-signup] decision failed: ' . $e->getMessage());

            return Response::error('Failed to update the request', 500);
        }

        return match ($result['result']) {
            CustomerSignupService::DECIDE_DONE => $this->decided($ctx, $id, $approve, $result['membership_id']),
            CustomerSignupService::DECIDE_ROLE_UNSAFE => Response::error(
                'The starting role in Sign-up settings is missing or has admin permissions. Choose a customer-safe role first.',
                409
            ),
            CustomerSignupService::DECIDE_CONFLICT => Response::error(
                'This person already has a suspended membership or an open invitation here. Resolve that in People first.',
                409
            ),
            default => Response::error('No request waiting for a decision has that id', 404),
        };
    }

    /** @param array{tenantId: int, userId: int} $ctx */
    private function decided(array $ctx, int $id, bool $approve, ?int $membershipId): Response
    {
        $status = $approve ? CustomerSignupService::STATUS_APPROVED : CustomerSignupService::STATUS_REJECTED;

        $this->audit->record($approve ? 'tenant.signup.approved' : 'tenant.signup.rejected', [
            'tenant_id' => $ctx['tenantId'],
            'actor_user_id' => $ctx['userId'],
            'target_type' => 'customer_signup',
            'target_id' => $id,
            'metadata' => ['membership_id' => $membershipId],
        ]);

        return Response::json(['data' => ['id' => $id, 'status' => $status]], 200);
    }

    /** @return array<string, mixed> */
    private function view(int $tenantId): array
    {
        $current = $this->settings->get($tenantId);
        $roles = $this->safeRoles->listForTenant($tenantId);

        $roleName = null;
        $roleSafe = false;
        if ($current['role_id'] !== null) {
            $roleSafe = $this->safeRoles->isCustomerSafe($current['role_id'], $tenantId);
            $stmt = $this->db->prepare('SELECT name FROM roles WHERE id = :id AND (tenant_id = :tid OR tenant_id IS NULL)');
            $stmt->execute([':id' => $current['role_id'], ':tid' => $tenantId]);
            $name = $stmt->fetchColumn();
            $roleName = $name === false ? null : (string) $name;
        }

        // @tenant-guard-ignore: tenants registry, the caller's own row by id
        $slugStmt = $this->db->prepare('SELECT slug FROM tenants WHERE id = :id');
        $slugStmt->execute([':id' => $tenantId]);
        $slug = $slugStmt->fetchColumn();

        return [
            'mode' => $current['mode'],
            'role_id' => $current['role_id'],
            'role_name' => $roleName,
            // False when the saved role has since gained admin permissions: sign-ups
            // are refused until an admin chooses again.
            'role_customer_safe' => $current['role_id'] === null ? null : $roleSafe,
            'allowed_domains' => $current['allowed_domains'],
            'eligible_roles' => $roles,
            'pending_count' => $this->service->pendingCount($tenantId),
            'workspace_slug' => $slug === false ? null : (string) $slug,
            'bot_check_ready' => $this->bot?->isReady() ?? false,
            'updated_at' => $current['updated_at'],
        ];
    }

    /** @return array{tenantId: int, userId: int}|Response */
    private function authorize(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null || $tenantId <= 0) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;

        if ($userId === null
            || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::SIGNUP_MANAGE, $tenantId)
        ) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::SIGNUP_MANAGE]);
        }

        return ['tenantId' => $tenantId, 'userId' => $userId];
    }

    private function queryParam(Request $request, string $name): ?string
    {
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $params = [];
            parse_str($query, $params);
            if (is_string($params[$name] ?? null)) {
                return $params[$name];
            }
        }

        return is_string($_GET[$name] ?? null) ? $_GET[$name] : null;
    }
}
