<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Accounting\AccountingRejectedException;
use Whity\Core\Accounting\StockBooks;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Accounting, phase A4a: stock in the books (WHIT-1318, design section 32
 * §6, Q3).
 *
 * PERMISSIONS
 * Reading on `accounting:read`; the switch, bringing stock in and giving
 * stock with no cost its cost on `accounting:settings`.
 *
 * AGENTS
 * The routes are MCP tools in `core.accounting`. No write declares an undo,
 * so whatever an agent changes is a proposal its person approves (AC.25).
 */
final class AccountingStockApiHandler
{
    public function __construct(private readonly StockBooks $stock)
    {
    }

    public function show(Request $request): Response
    {
        return $this->run($request, 'stock in the books', fn (int $tenantId): Response => Response::json(['data' => $this->stock->status($tenantId)]));
    }

    public function update(Request $request): Response
    {
        return $this->run($request, 'stock switch', function (int $tenantId) use ($request): Response {
            $body = JsonBody::parsed($request);
            $on = $body['stock_in_books'] ?? null;
            if (!is_bool($on)) {
                throw AccountingRejectedException::field('stock_in_books', 'stock_in_books is true or false');
            }

            return Response::json(['data' => $this->stock->setOn($tenantId, $on)]);
        });
    }

    public function open(Request $request): Response
    {
        return $this->run($request, 'stock opening', function (int $tenantId, ?int $actor) use ($request): Response {
            $date = JsonBody::parsed($request)['date'] ?? null;
            if ($date !== null && !is_string($date)) {
                throw AccountingRejectedException::field('date', 'date is YYYY-MM-DD');
            }

            return Response::json(['data' => $this->stock->openNow($tenantId, $actor, $date)], 201);
        });
    }

    public function cost(Request $request): Response
    {
        return $this->run($request, 'stock cost', function (int $tenantId, ?int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $variant = $body['variant_id'] ?? null;
            $cost = $body['unit_cost_minor'] ?? null;
            $date = $body['date'] ?? null;
            if (!is_int($variant) || $variant <= 0) {
                throw AccountingRejectedException::field('variant_id', 'variant_id is the item variant to give a cost');
            }
            if (!is_int($cost) || $cost < 0) {
                throw AccountingRejectedException::field('unit_cost_minor', 'unit_cost_minor is the cost of one base unit, in minor units, zero or more');
            }
            if ($date !== null && !is_string($date)) {
                throw AccountingRejectedException::field('date', 'date is YYYY-MM-DD');
            }

            return Response::json(['data' => $this->stock->setCost($tenantId, $variant, $cost, $actor, $date)], 201);
        });
    }

    public function waiting(Request $request): Response
    {
        return $this->run($request, 'stock waiting', fn (int $tenantId): Response => Response::json(['data' => $this->stock->waitingAll($tenantId)]));
    }

    /** @param callable(int, ?int): Response $work */
    private function run(Request $request, string $area, callable $work): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                return Response::error('A workspace is needed for accounting', 403);
            }
            $user = $request->user;
            $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;

            return $work($tenantId, $actor);
        } catch (AccountingRejectedException $e) {
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (\Throwable $e) {
            \Whity\Core\Log\ScrubbedErrorLog::failure('[AccountingStockApiHandler] ' . $area . ' failed', $e);

            return Response::error('Something went wrong with stock in the books. Try again.', 500);
        }
    }
}
