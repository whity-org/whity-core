<?php

declare(strict_types=1);

namespace Whity\Api;

use Throwable;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Billing\Cash\CashPayments;
use Whity\Core\Money\Currency;
use Whity\Core\Plugin\PluginOperatorGate;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Http\JsonBody;

/**
 * Cash payments for paid plugin plans (WHIT-1153). Only the operator, from
 * the system workspace, records and voids them; the workspace sees them in its
 * billing history ({@see ExternalBillingApiHandler::receipts()}).
 *
 *   GET  /api/billing/cash-payments?workspace=<id>   plans:manage, operator
 *   POST /api/billing/cash-payments                  plans:manage, operator
 *   POST /api/billing/cash-payments/{id}/void        plans:manage, operator
 */
final class CashPaymentsApiHandler
{
    public const AUDIT_RECORDED = 'billing.cash_payment_recorded';
    public const AUDIT_VOIDED = 'billing.cash_payment_voided';

    public function __construct(
        private readonly CashPayments $payments,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    public function list(Request $request): Response
    {
        if (($refusal = PluginOperatorGate::refusal()) !== null) {
            return $refusal;
        }
        parse_str((string) parse_url($request->getPath(), PHP_URL_QUERY), $query);
        $workspace = (int) ($query['workspace'] ?? ($_GET['workspace'] ?? 0));
        if ($workspace <= 0) {
            return Response::error('Choose a workspace with ?workspace=', 422);
        }

        return Response::json(['data' => $this->payments->forWorkspace($workspace)]);
    }

    public function record(Request $request): Response
    {
        if (($refusal = PluginOperatorGate::refusal()) !== null) {
            return $refusal;
        }
        $body = JsonBody::parsed($request);
        $currency = strtoupper(is_string($body['currency'] ?? null) ? $body['currency'] : 'JOD');
        try {
            $payment = $this->payments->record(
                (int) ($body['workspace'] ?? 0),
                is_string($body['planKey'] ?? null) ? $body['planKey'] : '',
                (int) ($body['quantity'] ?? 1),
                self::minor(is_scalar($body['amount'] ?? null) ? (string) $body['amount'] : '', $currency),
                $currency,
                is_string($body['receiptNumber'] ?? null) ? $body['receiptNumber'] : '',
                is_string($body['note'] ?? null) ? $body['note'] : null,
                is_string($body['paidOn'] ?? null) ? $body['paidOn'] : '',
                self::profileId($request),
            );
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 422, ['reason' => 'invalid_cash_payment']);
        } catch (Throwable $e) {
            error_log('[CashPaymentsApiHandler] ' . $e->getMessage());

            return Response::error('The request could not be processed', 500);
        }

        $this->audit?->record(self::AUDIT_RECORDED, [
            'tenant_id' => (int) $payment['tenant_id'],
            'actor_user_id' => self::profileId($request),
            'target_type' => 'plugin_cash_payment',
            'target_id' => (int) $payment['id'],
            'metadata' => [
                'plan' => $payment['plan_key'],
                'quantity' => $payment['quantity'],
                'amount_minor' => $payment['amount_minor'],
                'currency' => $payment['currency'],
                'receipt' => $payment['receipt_number'],
                'until' => $payment['period_end'],
            ],
        ]);

        return Response::json(['data' => $payment], 201);
    }

    /** @param array<string, string> $params */
    public function void(Request $request, array $params = []): Response
    {
        if (($refusal = PluginOperatorGate::refusal()) !== null) {
            return $refusal;
        }
        $reason = JsonBody::parsed($request)['reason'] ?? null;
        try {
            $payment = $this->payments->void((int) ($params['id'] ?? 0), is_string($reason) ? $reason : '', self::profileId($request));
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 422, ['reason' => 'invalid_void']);
        }

        $this->audit?->record(self::AUDIT_VOIDED, [
            'tenant_id' => (int) $payment['tenant_id'],
            'actor_user_id' => self::profileId($request),
            'target_type' => 'plugin_cash_payment',
            'target_id' => (int) $payment['id'],
            'metadata' => ['receipt' => $payment['receipt_number'], 'reason' => $payment['void_reason']],
        ]);

        return Response::json(['data' => $payment]);
    }

    /**
     * An amount as people write it (`15`, `15.000`) in the currency's minor
     * units, with the currency's own decimals.
     *
     * @throws \InvalidArgumentException
     */
    public static function minor(string $amount, string $currency): int
    {
        $amount = trim($amount);
        $exponent = Currency::exponentFor($currency);
        if (preg_match('/^\d+(?:\.(\d+))?$/', $amount, $m) !== 1 || strlen($m[1] ?? '') > $exponent) {
            throw new \InvalidArgumentException("Write the amount like 15 or 15.000, with at most {$exponent} decimals for {$currency}.");
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) ($whole . str_pad($fraction, $exponent, '0'));
    }

    private static function profileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
    }
}
