<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Accounting\AccountingRejectedException;
use Whity\Core\Accounting\Payments;
use Whity\Core\Accounting\Receivables;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Accounting, phase A2b: payments received, allocations, cheques, transfers,
 * aged receivables and the customer statement (WHIT-1318, design section 32
 * §5.4, §8).
 *
 * PERMISSIONS
 * Reading payments on `payments:read`; recording, allocating, depositing and
 * bouncing cheques, and transfers on `payments:write`; aged receivables and a
 * customer's statement on `invoices:read`.
 *
 * AMOUNTS are minor units of the books' currency (`*_minor`, with `currency`
 * beside them); a payment may be typed in normal units as `amount` ("125.500").
 *
 * AGENTS
 * The routes are MCP tools in `core.accounting`. No write declares an undo,
 * so whatever an agent changes is a proposal its person approves (AC.25).
 *
 * Ids from another workspace answer 404, never 403.
 */
final class AccountingPaymentsApiHandler
{
    public function __construct(
        private readonly Payments $payments,
        private readonly Receivables $receivables,
    ) {
    }

    public function list(Request $request): Response
    {
        return $this->run($request, 'payments', function (int $tenantId) use ($request): Response {
            $q = self::query($request);
            $filter = array_filter([
                'direction' => $q['direction'] ?? null,
                'contact_id' => isset($q['contact_id']) && ctype_digit($q['contact_id']) ? (int) $q['contact_id'] : null,
                'method' => $q['method'] ?? null,
                'cheque_status' => $q['cheque_status'] ?? null,
                'from' => $q['from'] ?? null,
                'to' => $q['to'] ?? null,
                'q' => $q['q'] ?? null,
            ], static fn (mixed $v): bool => $v !== null && $v !== '');
            $limit = isset($q['limit']) && ctype_digit($q['limit']) ? (int) $q['limit'] : 50;
            $offset = isset($q['offset']) && ctype_digit($q['offset']) ? (int) $q['offset'] : 0;

            return Response::json(['data' => $this->payments->list($tenantId, $filter, $limit, $offset)]);
        });
    }

    public function receive(Request $request): Response
    {
        return $this->run($request, 'record payment', function (int $tenantId, ?int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $key = $body['idempotency_key'] ?? null;
            $result = $this->payments->receive($tenantId, $actor, $body, [
                'external_ref' => is_string($key) && $key !== '' ? 'api:' . $key : null,
            ]);

            return Response::json(['data' => $result['payment']], $result['repeated'] ? 200 : 201);
        });
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        return $this->run($request, 'payment', fn (int $tenantId): Response => Response::json(['data' => $this->payments->get($tenantId, (int) $params['id'])]));
    }

    /** @param array<string, string> $params */
    public function allocate(Request $request, array $params): Response
    {
        return $this->run($request, 'allocate payment', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $invoiceId = $body['invoice_id'] ?? null;
            $amount = $body['amount_minor'] ?? null;
            if (!is_int($invoiceId)) {
                throw AccountingRejectedException::field('invoice_id', 'invoice_id is the invoice to settle');
            }
            if ($amount !== null && !is_int($amount)) {
                throw AccountingRejectedException::field('amount_minor', 'amount_minor is a whole number of minor units, or leave it out for all that fits');
            }

            return Response::json(['data' => $this->payments->allocate($tenantId, (int) $params['id'], $invoiceId, $amount, $actor)]);
        });
    }

    /** @param array<string, string> $params */
    public function deposit(Request $request, array $params): Response
    {
        return $this->run($request, 'deposit cheque', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            $body = JsonBody::parsed($request);

            return Response::json(['data' => $this->payments->deposit(
                $tenantId,
                (int) $params['id'],
                $actor,
                self::optionalText($body, 'deposited_on'),
                $body['account_id'] ?? null,
            )]);
        });
    }

    /** @param array<string, string> $params */
    public function bounce(Request $request, array $params): Response
    {
        return $this->run($request, 'bounce cheque', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $fee = $body['fee_minor'] ?? 0;
            if (!is_int($fee)) {
                throw AccountingRejectedException::field('fee_minor', 'fee_minor is the bank\'s fee in minor units, or 0');
            }

            return Response::json(['data' => $this->payments->bounce(
                $tenantId,
                (int) $params['id'],
                $actor,
                self::optionalText($body, 'bounced_on'),
                $fee,
                self::optionalText($body, 'note') ?? '',
            )]);
        });
    }

    public function transfer(Request $request): Response
    {
        return $this->run($request, 'transfer', function (int $tenantId, ?int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $key = $body['idempotency_key'] ?? null;
            $result = $this->payments->transfer($tenantId, $actor, $body, [
                'external_ref' => is_string($key) && $key !== '' ? 'api:' . $key : null,
            ]);

            return Response::json(['data' => $result['payment']], $result['repeated'] ? 200 : 201);
        });
    }

    public function aged(Request $request): Response
    {
        return $this->run($request, 'aged receivables', function (int $tenantId) use ($request): Response {
            $q = self::query($request);

            return Response::json(['data' => $this->receivables->aged(
                $tenantId,
                $q['as_of'] ?? gmdate('Y-m-d'),
                isset($q['contact_id']) && ctype_digit($q['contact_id']) ? (int) $q['contact_id'] : null,
            )]);
        });
    }

    /** @param array<string, string> $params */
    public function statement(Request $request, array $params): Response
    {
        return $this->run($request, 'customer statement', function (int $tenantId) use ($request, $params): Response {
            $q = self::query($request);

            return Response::json(['data' => $this->receivables->statement(
                $tenantId,
                (int) $params['id'],
                $q['from'] ?? gmdate('Y-01-01'),
                $q['to'] ?? gmdate('Y-m-d'),
            )]);
        });
    }

    /** @param array<string, mixed> $body */
    private static function optionalText(array $body, string $field): ?string
    {
        $value = $body[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw AccountingRejectedException::field($field, $field . ' is text');
        }

        return $value;
    }

    /** @param callable(int, ?int): Response $work */
    private function run(Request $request, string $area, callable $work): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                return Response::error('A workspace is needed for payments', 403);
            }
            $user = $request->user;
            $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;

            return $work($tenantId, $actor);
        } catch (AccountingRejectedException $e) {
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (\Throwable $e) {
            error_log('[AccountingPaymentsApiHandler] ' . $area . ' failed: ' . $e->getMessage());

            return Response::error('Something went wrong with the payment. Try again.', 500);
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
