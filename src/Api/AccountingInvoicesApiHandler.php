<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Accounting\AccountingRejectedException;
use Whity\Core\Accounting\Invoices;
use Whity\Core\Accounting\Taxes;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Accounting, phase A2a: tax codes, sales invoices and credit notes
 * (WHIT-1318, design section 32 §5.2, §7).
 *
 * PERMISSIONS
 * Reading on `invoices:read`; drafting, issuing, cancelling drafts and credit
 * notes on `invoices:write`; the invoice settings and changing tax codes on
 * `accounting:settings`.
 * Approving is never a permission: a workspace with an invoice route sends an
 * invoice for approval when it is issued (route subject `accounting.invoice`).
 *
 * AMOUNTS are minor units of the invoice's currency (`*_minor`, with
 * `currency` beside them); prices are typed per unit.
 *
 * AGENTS
 * The routes are MCP tools in `core.accounting`. No write declares an undo,
 * so whatever an agent changes is a proposal its person approves (AC.25).
 *
 * Ids from another workspace answer 404, never 403.
 */
final class AccountingInvoicesApiHandler
{
    /** @var array<string, string> body field => setting key */
    private const SETTING_KEYS = [
        'invoice_route_template_id' => SettingsRegistry::ACCOUNTING_INVOICE_ROUTE_TEMPLATE_ID,
        'invoice_payment_terms_days' => SettingsRegistry::ACCOUNTING_INVOICE_PAYMENT_TERMS_DAYS,
        'prices_include_tax' => SettingsRegistry::ACCOUNTING_PRICES_INCLUDE_TAX,
    ];

    public function __construct(
        private readonly Invoices $invoices,
        private readonly Taxes $taxes,
        private readonly SettingsService $settings,
    ) {
    }

    public function listTaxCodes(Request $request): Response
    {
        return $this->run($request, 'tax codes', function (int $tenantId) use ($request): Response {
            $q = self::query($request);

            return Response::json(['data' => $this->taxes->list($tenantId, array_filter([
                'direction' => $q['direction'] ?? null,
                'include_archived' => ($q['archived'] ?? '') === 'true' ? true : null,
            ], static fn (mixed $v): bool => $v !== null))]);
        });
    }

    public function createTaxCode(Request $request): Response
    {
        return $this->run($request, 'create tax code', fn (int $tenantId): Response => Response::json(['data' => $this->taxes->create($tenantId, JsonBody::parsed($request))], 201));
    }

    /** @param array<string, string> $params */
    public function updateTaxCode(Request $request, array $params): Response
    {
        return $this->run($request, 'update tax code', fn (int $tenantId): Response => Response::json(['data' => $this->taxes->update($tenantId, (int) $params['id'], JsonBody::parsed($request))]));
    }

    /** @param array<string, string> $params */
    public function archiveTaxCode(Request $request, array $params): Response
    {
        return $this->run($request, 'archive tax code', fn (int $tenantId): Response => Response::json(['data' => $this->taxes->archive($tenantId, (int) $params['id'])]));
    }

    /** @param array<string, string> $params */
    public function restoreTaxCode(Request $request, array $params): Response
    {
        return $this->run($request, 'restore tax code', fn (int $tenantId): Response => Response::json(['data' => $this->taxes->archive($tenantId, (int) $params['id'], false)]));
    }

    public function list(Request $request): Response
    {
        return $this->run($request, 'invoices', function (int $tenantId) use ($request): Response {
            $q = self::query($request);
            $filter = array_filter([
                'kind' => $q['kind'] ?? null,
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
        return $this->run($request, 'create invoice', function (int $tenantId, ?int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $key = $body['idempotency_key'] ?? null;
            $result = $this->invoices->create($tenantId, $actor, $body, [
                'external_ref' => is_string($key) && $key !== '' ? 'api:' . $key : null,
            ]);

            return Response::json(['data' => $result['invoice']], $result['repeated'] ? 200 : 201);
        });
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        return $this->run($request, 'invoice', fn (int $tenantId): Response => Response::json(['data' => $this->invoices->get($tenantId, (int) $params['id'])]));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        return $this->run($request, 'update invoice', fn (int $tenantId): Response => Response::json([
            'data' => $this->invoices->update($tenantId, (int) $params['id'], JsonBody::parsed($request)),
        ]));
    }

    /** @param array<string, string> $params */
    public function issue(Request $request, array $params): Response
    {
        return $this->run($request, 'issue invoice', fn (int $tenantId, ?int $actor): Response => Response::json([
            'data' => $this->invoices->issue($tenantId, (int) $params['id'], $actor),
        ]));
    }

    /** @param array<string, string> $params */
    public function cancel(Request $request, array $params): Response
    {
        return $this->run($request, 'cancel invoice', fn (int $tenantId, ?int $actor): Response => Response::json([
            'data' => $this->invoices->cancel($tenantId, (int) $params['id'], $actor),
        ]));
    }

    /** @param array<string, string> $params */
    public function credit(Request $request, array $params): Response
    {
        return $this->run($request, 'credit note', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $key = $body['idempotency_key'] ?? null;
            $result = $this->invoices->credit($tenantId, (int) $params['id'], $actor, $body, [
                'external_ref' => is_string($key) && $key !== '' ? 'api:' . $key : null,
            ]);

            return Response::json(['data' => $result['invoice']], $result['repeated'] ? 200 : 201);
        });
    }

    public function showSettings(Request $request): Response
    {
        return $this->run($request, 'invoice settings', fn (int $tenantId): Response => Response::json(['data' => $this->settingsFor($tenantId)]));
    }

    public function updateSettings(Request $request): Response
    {
        return $this->run($request, 'invoice settings', function (int $tenantId) use ($request): Response {
            $changes = [];
            foreach (JsonBody::parsed($request) as $field => $value) {
                $field = (string) $field;
                $key = self::SETTING_KEYS[$field] ?? throw AccountingRejectedException::field($field, $field . ' is not an invoice setting');
                $text = match (true) {
                    $field === 'prices_include_tax' && is_bool($value) => $value ? 'true' : 'false',
                    $field === 'invoice_route_template_id' && $value === null => '0',
                    $field !== 'prices_include_tax' && is_int($value) => (string) $value,
                    default => throw AccountingRejectedException::field($field, $field === 'prices_include_tax' ? $field . ' is true or false' : $field . ' is a whole number'),
                };
                $problem = SettingsRegistry::validate($key, $text);
                if ($problem !== null) {
                    throw AccountingRejectedException::field($field, $problem);
                }
                $changes[$key] = $text;
            }
            foreach ($changes as $key => $text) {
                $this->settings->setTenant($tenantId, $key, $text);
            }

            return Response::json(['data' => $this->settingsFor($tenantId)]);
        });
    }

    /** @return array{invoice_route_template_id: ?int, invoice_payment_terms_days: int, prices_include_tax: bool} */
    private function settingsFor(int $tenantId): array
    {
        $effective = $this->settings->effective($tenantId);
        $route = (int) ($effective[SettingsRegistry::ACCOUNTING_INVOICE_ROUTE_TEMPLATE_ID] ?? 0);

        return [
            'invoice_route_template_id' => $route === 0 ? null : $route,
            'invoice_payment_terms_days' => (int) ($effective[SettingsRegistry::ACCOUNTING_INVOICE_PAYMENT_TERMS_DAYS] ?? 0),
            'prices_include_tax' => (string) ($effective[SettingsRegistry::ACCOUNTING_PRICES_INCLUDE_TAX] ?? 'false') === 'true',
        ];
    }

    /** @param callable(int, ?int): Response $work */
    private function run(Request $request, string $area, callable $work): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                return Response::error('A workspace is needed for invoices', 403);
            }
            $user = $request->user;
            $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;

            return $work($tenantId, $actor);
        } catch (AccountingRejectedException $e) {
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (\Throwable $e) {
            error_log('[AccountingInvoicesApiHandler] ' . $area . ' failed: ' . $e->getMessage());

            return Response::error('Something went wrong with the invoice. Try again.', 500);
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
