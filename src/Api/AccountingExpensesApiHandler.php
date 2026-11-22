<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Accounting\AccountingRejectedException;
use Whity\Core\Accounting\Expenses;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Accounting, phase A3b: expense claims and petty cash (WHIT-1318, design
 * section 32 §5.3, register MO.1, Q5).
 *
 * PERMISSIONS
 * A person files and reads their own claims on `expenses:submit`; people
 * holding `bills:read` see everyone's (`?all=true`). Paying a claim back and
 * petty cash are on `payments:write`, reading petty cash on `payments:read`.
 * Approving a claim is the workspace's bill route, never a permission.
 *
 * AGENTS
 * The routes are MCP tools in `core.accounting`. No write declares an undo,
 * so whatever an agent changes is a proposal its person approves (AC.25).
 *
 * Another person's claim, without `bills:read`, answers 404.
 */
final class AccountingExpensesApiHandler
{
    /** @param \Closure(int, string, int): bool $can whether a profile holds a permission in a workspace */
    public function __construct(
        private readonly Expenses $expenses,
        private readonly \Closure $can,
    ) {
    }

    public function listClaims(Request $request): Response
    {
        return $this->run($request, 'expense claims', function (int $tenantId, ?int $actor) use ($request): Response {
            $q = self::query($request);
            $everyone = ($q['all'] ?? '') === 'true' && $this->seesAll($tenantId, $actor);
            $limit = isset($q['limit']) && ctype_digit($q['limit']) ? (int) $q['limit'] : 50;
            $offset = isset($q['offset']) && ctype_digit($q['offset']) ? (int) $q['offset'] : 0;

            return Response::json(['data' => $this->expenses->claims($tenantId, $everyone ? null : (int) $actor, $limit, $offset)]);
        });
    }

    public function claim(Request $request): Response
    {
        return $this->run($request, 'file expense claim', function (int $tenantId, ?int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $key = $body['idempotency_key'] ?? null;

            return Response::json(['data' => $this->expenses->claim($tenantId, (int) $actor, $body, [
                'external_ref' => is_string($key) && $key !== '' ? 'claim:' . $actor . ':' . $key : null,
            ])], 201);
        });
    }

    /** @param array<string, string> $params */
    public function showClaim(Request $request, array $params): Response
    {
        return $this->run($request, 'expense claim', fn (int $tenantId, ?int $actor): Response => Response::json([
            'data' => $this->expenses->get($tenantId, (int) $params['id'], $this->seesAll($tenantId, $actor) ? null : (int) $actor),
        ]));
    }

    /** @param array<string, string> $params */
    public function reimburse(Request $request, array $params): Response
    {
        return $this->run($request, 'pay back claim', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            foreach (['method', 'paid_on', 'reference'] as $text) {
                if (isset($body[$text]) && !is_string($body[$text])) {
                    throw AccountingRejectedException::field($text, $text . ' is text');
                }
            }
            if (isset($body['account_id']) && !is_int($body['account_id'])) {
                throw AccountingRejectedException::field('account_id', 'account_id is the bank or cash account it is paid from');
            }

            return Response::json(['data' => $this->expenses->reimburse($tenantId, (int) $params['id'], $actor, $body)], 201);
        });
    }

    public function pettyCash(Request $request): Response
    {
        return $this->run($request, 'petty cash', fn (int $tenantId): Response => Response::json([
            'data' => $this->expenses->pettyCash($tenantId, self::query($request)['as_of'] ?? gmdate('Y-m-d')),
        ]));
    }

    public function spend(Request $request): Response
    {
        return $this->run($request, 'petty cash spend', function (int $tenantId, ?int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $key = $body['idempotency_key'] ?? null;

            return Response::json(['data' => $this->expenses->spend($tenantId, $actor, $body, [
                'external_ref' => is_string($key) && $key !== '' ? 'petty:' . $key : null,
            ])], 201);
        });
    }

    private function seesAll(int $tenantId, ?int $actor): bool
    {
        return $actor !== null && ($this->can)($actor, CorePermissions::BILLS_READ, $tenantId);
    }

    /** @param callable(int, ?int): Response $work */
    private function run(Request $request, string $area, callable $work): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                return Response::error('A workspace is needed for expenses', 403);
            }
            $user = $request->user;
            $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;
            if ($actor === null) {
                return Response::error('A person is needed for expenses', 403);
            }

            return $work($tenantId, $actor);
        } catch (AccountingRejectedException $e) {
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (\Throwable $e) {
            error_log('[AccountingExpensesApiHandler] ' . $area . ' failed: ' . $e->getMessage());

            return Response::error('Something went wrong with the expense. Try again.', 500);
        }
    }

    /** @return array<string, string> */
    private static function query(Request $request): array
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
}
