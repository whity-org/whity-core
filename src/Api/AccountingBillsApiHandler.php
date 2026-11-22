<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Accounting\AccountingRejectedException;
use Whity\Core\Accounting\Invoices;
use Whity\Core\Accounting\Payments;
use Whity\Core\Accounting\Receivables;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Accounting, phase A3a: supplier bills and supplier credits, payments made,
 * aged payables and the supplier statement (WHIT-1318, design section 32
 * §5.3, §5.4).
 *
 * A bill is the purchase side of the invoice engine: entered as a draft
 * with the supplier's own number, posted (numbered `BIL-2026-0001`, frozen,
 * Dr each line's cost and the tax to reclaim, Cr We owe suppliers), or sent
 * on the workspace's bill route first (`accounting.bill`). A supplier credit
 * mirrors all or part of a bill.
 *
 * PERMISSIONS
 * Bills on `bills:read` and `bills:write`; paying a supplier on
 * `payments:write`; aged payables and a supplier's statement on `bills:read`;
 * the bill route on `accounting:settings`.
 *
 * AGENTS
 * The routes are MCP tools in `core.accounting`. No write declares an undo,
 * so whatever an agent changes is a proposal its person approves (AC.25).
 *
 * Ids from another workspace, and invoices asked for as bills, answer 404.
 */
final class AccountingBillsApiHandler
{
    public function __construct(
        private readonly Invoices $invoices,
        private readonly Payments $payments,
        private readonly Receivables $receivables,
        private readonly SettingsService $settings,
    ) {
    }

    public function list(Request $request): Response
    {
        return $this->run($request, 'bills', function (int $tenantId) use ($request): Response {
            $q = self::query($request);
            $kind = $q['kind'] ?? null;
            if ($kind !== null && !in_array($kind, Invoices::PURCHASE_KINDS, true)) {
                throw AccountingRejectedException::field('kind', 'kind is purchase or purchase_credit');
            }
            $filter = array_filter([
                'side' => 'purchase',
                'kind' => $kind,
                'status' => $q['status'] ?? null,
                'contact_id' => isset($q['contact_id']) && ctype_digit($q['contact_id']) ? (int) $q['contact_id'] : null,
                'q' => $q['q'] ?? null,
                'from' => $q['from'] ?? null,
                'to' => $q['to'] ?? null,
            ], static fn (mixed $v): bool => $v !== null && $v !== '');
            $limit = isset($q['limit']) && ctype_digit($q['limit']) ? (int) $q['limit'] : 50;
            $offset = isset($q['offset']) && ctype_digit($q['offset']) ? (int) $q['offset'] : 0;

            return Response::json(['data' => $this->invoices->list($tenantId, $filter, $limit, $offset)]);
        });
    }

    public function create(Request $request): Response
    {
        return $this->run($request, 'create bill', function (int $tenantId, ?int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $key = $body['idempotency_key'] ?? null;
            $result = $this->invoices->create($tenantId, $actor, ['kind' => 'purchase'] + $body, [
                'external_ref' => is_string($key) && $key !== '' ? 'api:' . $key : null,
            ]);

            return Response::json(['data' => $result['invoice']], $result['repeated'] ? 200 : 201);
        });
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        return $this->run($request, 'bill', fn (int $tenantId): Response => Response::json(['data' => $this->bill($tenantId, (int) $params['id'])]));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        return $this->run($request, 'update bill', function (int $tenantId) use ($request, $params): Response {
            $this->bill($tenantId, (int) $params['id']);
            $changes = JsonBody::parsed($request);
            unset($changes['kind']);

            return Response::json(['data' => $this->invoices->update($tenantId, (int) $params['id'], $changes)]);
        });
    }

    /** @param array<string, string> $params */
    public function post(Request $request, array $params): Response
    {
        return $this->run($request, 'post bill', function (int $tenantId, ?int $actor) use ($params): Response {
            $this->bill($tenantId, (int) $params['id']);

            return Response::json(['data' => $this->invoices->issue($tenantId, (int) $params['id'], $actor)]);
        });
    }

    /** @param array<string, string> $params */
    public function cancel(Request $request, array $params): Response
    {
        return $this->run($request, 'cancel bill', function (int $tenantId, ?int $actor) use ($params): Response {
            $this->bill($tenantId, (int) $params['id']);

            return Response::json(['data' => $this->invoices->cancel($tenantId, (int) $params['id'], $actor)]);
        });
    }

    /** @param array<string, string> $params */
    public function credit(Request $request, array $params): Response
    {
        return $this->run($request, 'supplier credit', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            $this->bill($tenantId, (int) $params['id']);
            $body = JsonBody::parsed($request);
            $key = $body['idempotency_key'] ?? null;
            $result = $this->invoices->credit($tenantId, (int) $params['id'], $actor, $body, [
                'external_ref' => is_string($key) && $key !== '' ? 'api:' . $key : null,
            ]);

            return Response::json(['data' => $result['invoice']], $result['repeated'] ? 200 : 201);
        });
    }

    public function pay(Request $request): Response
    {
        return $this->run($request, 'pay supplier', function (int $tenantId, ?int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $key = $body['idempotency_key'] ?? null;
            $result = $this->payments->pay($tenantId, $actor, $body, [
                'external_ref' => is_string($key) && $key !== '' ? 'api:' . $key : null,
            ]);

            return Response::json(['data' => $result['payment']], $result['repeated'] ? 200 : 201);
        });
    }

    public function aged(Request $request): Response
    {
        return $this->run($request, 'aged payables', function (int $tenantId) use ($request): Response {
            $q = self::query($request);

            return Response::json(['data' => $this->receivables->aged(
                $tenantId,
                $q['as_of'] ?? gmdate('Y-m-d'),
                isset($q['contact_id']) && ctype_digit($q['contact_id']) ? (int) $q['contact_id'] : null,
                'purchase',
            )]);
        });
    }

    /** @param array<string, string> $params */
    public function statement(Request $request, array $params): Response
    {
        return $this->run($request, 'supplier statement', function (int $tenantId) use ($request, $params): Response {
            $q = self::query($request);

            return Response::json(['data' => $this->receivables->statement(
                $tenantId,
                (int) $params['id'],
                $q['from'] ?? gmdate('Y-01-01'),
                $q['to'] ?? gmdate('Y-m-d'),
                'purchase',
            )]);
        });
    }

    public function showSettings(Request $request): Response
    {
        return $this->run($request, 'bill settings', fn (int $tenantId): Response => Response::json(['data' => $this->settingsFor($tenantId)]));
    }

    public function updateSettings(Request $request): Response
    {
        return $this->run($request, 'bill settings', function (int $tenantId) use ($request): Response {
            foreach (JsonBody::parsed($request) as $field => $value) {
                $field = (string) $field;
                if ($field !== 'bill_route_template_id') {
                    throw AccountingRejectedException::field($field, $field . ' is not a bill setting');
                }
                $text = match (true) {
                    $value === null => '0',
                    is_int($value) => (string) $value,
                    default => throw AccountingRejectedException::field($field, $field . ' is a route template id, or null for none'),
                };
                $problem = SettingsRegistry::validate(SettingsRegistry::ACCOUNTING_BILL_ROUTE_TEMPLATE_ID, $text);
                if ($problem !== null) {
                    throw AccountingRejectedException::field($field, $problem);
                }
                $this->settings->setTenant($tenantId, SettingsRegistry::ACCOUNTING_BILL_ROUTE_TEMPLATE_ID, $text);
            }

            return Response::json(['data' => $this->settingsFor($tenantId)]);
        });
    }

    /** @return array{bill_route_template_id: ?int} */
    private function settingsFor(int $tenantId): array
    {
        $route = (int) ($this->settings->effective($tenantId)[SettingsRegistry::ACCOUNTING_BILL_ROUTE_TEMPLATE_ID] ?? 0);

        return ['bill_route_template_id' => $route === 0 ? null : $route];
    }

    /** @return array<string, mixed> a bill or supplier credit; anything else is not found */
    private function bill(int $tenantId, int $id): array
    {
        $doc = $this->invoices->get($tenantId, $id);
        if (!in_array($doc['kind'], Invoices::PURCHASE_KINDS, true)) {
            throw AccountingRejectedException::notFound('Bill');
        }

        return $doc;
    }

    /** @param callable(int, ?int): Response $work */
    private function run(Request $request, string $area, callable $work): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                return Response::error('A workspace is needed for bills', 403);
            }
            $user = $request->user;
            $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;

            return $work($tenantId, $actor);
        } catch (AccountingRejectedException $e) {
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (\Throwable $e) {
            error_log('[AccountingBillsApiHandler] ' . $area . ' failed: ' . $e->getMessage());

            return Response::error('Something went wrong with the bill. Try again.', 500);
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
