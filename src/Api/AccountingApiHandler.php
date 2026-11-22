<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Accounting\AccountingReports;
use Whity\Core\Accounting\AccountingRejectedException;
use Whity\Core\Accounting\AccountingWorkspace;
use Whity\Core\Accounting\ChartOfAccounts;
use Whity\Core\Accounting\Ledger;
use Whity\Core\Accounting\ManualEntries;
use Whity\Core\Accounting\Periods;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\Validation\Field;
use Whity\Http\Validation\FieldValidator;

/**
 * Accounting, phase A1: the books, the chart of accounts, journal entries,
 * periods and the trial balance (WHIT-1318, design section 32).
 *
 * PERMISSIONS
 * Reading on `accounting:read`; manual entries and reversals on
 * `accounting:post`; closing and reopening months on `accounting:close`; the
 * chart on `accounting:settings`. Approving is never a permission (AC.24).
 *
 * AMOUNTS are minor units of the books' base currency in what this answers
 * (`*_minor`, with `currency` beside them); people type manual entries in
 * normal units ("12.500").
 *
 * AGENTS
 * The routes are MCP tools in `core.accounting`. No write declares an undo, so
 * whatever an agent changes is a proposal its person approves (AC.25).
 *
 * Ids from another workspace answer 404, never 403.
 */
final class AccountingApiHandler
{
    public function __construct(
        private readonly AccountingWorkspace $workspace,
        private readonly ChartOfAccounts $chart,
        private readonly Ledger $ledger,
        private readonly ManualEntries $manual,
        private readonly Periods $periods,
        private readonly AccountingReports $reports,
    ) {
    }

    // ── the books ───────────────────────────────────────────────────────

    public function showBooks(Request $request): Response
    {
        return $this->run($request, 'books', function (int $tenantId): Response {
            $books = $this->workspace->books($tenantId);

            return Response::json(['data' => [
                'base_currency' => $books['base_currency'],
                'currency_fixed' => $books['currency_fixed'],
                'tax_pack' => $books['tax_pack'],
                'year_start_month' => $books['year_start_month'],
                'lock_date' => $books['lock_date'],
            ]]);
        });
    }

    // ── the chart ───────────────────────────────────────────────────────

    public function listAccounts(Request $request): Response
    {
        return $this->run($request, 'accounts', fn (int $tenantId): Response => Response::json([
            'data' => $this->chart->list($tenantId, (self::query($request)['archived'] ?? '') === 'true'),
            'currency' => $this->workspace->books($tenantId)['base_currency'],
        ]));
    }

    public function createAccount(Request $request): Response
    {
        return $this->run($request, 'create account', fn (int $tenantId): Response => Response::json(['data' => $this->chart->create($tenantId, JsonBody::parsed($request))], 201));
    }

    /** @param array<string, string> $params */
    public function showAccount(Request $request, array $params): Response
    {
        return $this->run($request, 'account', fn (int $tenantId): Response => Response::json(['data' => $this->chart->get($tenantId, (int) $params['id'])]));
    }

    /** @param array<string, string> $params */
    public function updateAccount(Request $request, array $params): Response
    {
        return $this->run($request, 'update account', fn (int $tenantId): Response => Response::json(['data' => $this->chart->update($tenantId, (int) $params['id'], JsonBody::parsed($request))]));
    }

    /** @param array<string, string> $params */
    public function archiveAccount(Request $request, array $params): Response
    {
        return $this->run($request, 'archive account', fn (int $tenantId): Response => Response::json(['data' => $this->chart->archive($tenantId, (int) $params['id'])]));
    }

    /** @param array<string, string> $params */
    public function restoreAccount(Request $request, array $params): Response
    {
        return $this->run($request, 'restore account', fn (int $tenantId): Response => Response::json(['data' => $this->chart->restore($tenantId, (int) $params['id'])]));
    }

    /** @param array<string, string> $params */
    public function accountStatement(Request $request, array $params): Response
    {
        return $this->run($request, 'statement', function (int $tenantId) use ($request, $params): Response {
            $q = self::query($request);
            [$from, $to] = self::range($q);

            return Response::json(['data' => $this->reports->accountStatement(
                $tenantId,
                (int) $params['id'],
                $from,
                $to,
                $q['after_date'] ?? null,
                isset($q['after_line']) ? (int) $q['after_line'] : null,
                isset($q['limit']) ? (int) $q['limit'] : 200,
            )]);
        });
    }

    public function listJournals(Request $request): Response
    {
        return $this->run($request, 'journals', fn (int $tenantId): Response => Response::json(['data' => $this->chart->journals($tenantId)]));
    }

    // ── entries ─────────────────────────────────────────────────────────

    public function listEntries(Request $request): Response
    {
        return $this->run($request, 'entries', function (int $tenantId) use ($request): Response {
            $q = self::query($request);

            return Response::json(['data' => $this->ledger->entries($tenantId, [
                'journal_id' => isset($q['journal_id']) ? (int) $q['journal_id'] : null,
                'account_id' => isset($q['account_id']) ? (int) $q['account_id'] : null,
                'source_type' => $q['source_type'] ?? null,
                'source_id' => isset($q['source_id']) ? (int) $q['source_id'] : null,
                'from' => $q['from'] ?? null,
                'to' => $q['to'] ?? null,
                'before_id' => isset($q['before_id']) ? (int) $q['before_id'] : null,
            ], isset($q['limit']) ? (int) $q['limit'] : 50)]);
        });
    }

    /** @param array<string, string> $params */
    public function showEntry(Request $request, array $params): Response
    {
        return $this->run($request, 'entry', fn (int $tenantId): Response => Response::json(['data' => $this->ledger->entry($tenantId, (int) $params['id'])]));
    }

    /** @param array<string, string> $params */
    public function reverseEntry(Request $request, array $params): Response
    {
        return $this->run($request, 'reverse', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $checked = FieldValidator::check($body, ['note' => Field::text()->max(500), 'date' => Field::text()->max(10)]);
            if (!$checked->ok()) {
                return $checked->refusal('Check the note and the date');
            }
            $date = $checked->value('date');
            $result = $this->ledger->reverse($tenantId, (int) $params['id'], $actor, $checked->value('note'), $date === '' ? null : $date, 'reversal');

            return Response::json(['data' => $result['entry']], 201);
        });
    }

    // ── manual entries ──────────────────────────────────────────────────

    public function listManualEntries(Request $request): Response
    {
        return $this->run($request, 'manual entries', function (int $tenantId) use ($request): Response {
            $q = self::query($request);

            return Response::json(['data' => $this->manual->list($tenantId, [
                'status' => $q['status'] ?? null,
                'before_id' => isset($q['before_id']) ? (int) $q['before_id'] : null,
            ], isset($q['limit']) ? (int) $q['limit'] : 50)]);
        });
    }

    public function createManualEntry(Request $request): Response
    {
        return $this->run($request, 'create entry', fn (int $tenantId, ?int $actor): Response => Response::json(['data' => $this->manual->create($tenantId, $actor, JsonBody::parsed($request))], 201));
    }

    /** @param array<string, string> $params */
    public function showManualEntry(Request $request, array $params): Response
    {
        return $this->run($request, 'manual entry', fn (int $tenantId): Response => Response::json(['data' => $this->manual->get($tenantId, (int) $params['id'])]));
    }

    /** @param array<string, string> $params */
    public function updateManualEntry(Request $request, array $params): Response
    {
        return $this->run($request, 'update entry', fn (int $tenantId): Response => Response::json(['data' => $this->manual->update($tenantId, (int) $params['id'], JsonBody::parsed($request))]));
    }

    /** @param array<string, string> $params */
    public function postManualEntry(Request $request, array $params): Response
    {
        return $this->run($request, 'post entry', fn (int $tenantId, ?int $actor): Response => Response::json(['data' => $this->manual->post($tenantId, (int) $params['id'], $actor)]));
    }

    /** @param array<string, string> $params */
    public function cancelManualEntry(Request $request, array $params): Response
    {
        return $this->run($request, 'cancel entry', fn (int $tenantId): Response => Response::json(['data' => $this->manual->cancel($tenantId, (int) $params['id'])]));
    }

    // ── periods ─────────────────────────────────────────────────────────

    public function listPeriods(Request $request): Response
    {
        return $this->run($request, 'periods', function (int $tenantId) use ($request): Response {
            $year = self::query($request)['year'] ?? null;

            return Response::json(['data' => $this->periods->list($tenantId, is_string($year) && ctype_digit($year) ? (int) $year : null)]);
        });
    }

    /** @param array<string, string> $params */
    public function closePeriod(Request $request, array $params): Response
    {
        return $this->run($request, 'close period', fn (int $tenantId, ?int $actor): Response => Response::json(['data' => $this->periods->close($tenantId, (int) $params['id'], $actor)]));
    }

    /** @param array<string, string> $params */
    public function reopenPeriod(Request $request, array $params): Response
    {
        return $this->run($request, 'reopen period', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            $checked = FieldValidator::check(JsonBody::parsed($request), ['reason' => Field::text()->required()->max(500)]);
            if (!$checked->ok()) {
                return $checked->refusal('Say why the month is reopened');
            }

            return Response::json(['data' => $this->periods->reopen($tenantId, (int) $params['id'], $actor, $checked->value('reason'))]);
        });
    }

    // ── reports ─────────────────────────────────────────────────────────

    public function trialBalance(Request $request): Response
    {
        return $this->run($request, 'trial balance', function (int $tenantId) use ($request): Response {
            $q = self::query($request);
            [$from, $to] = self::range($q);

            return Response::json(['data' => $this->reports->trialBalance($tenantId, $from, $to, isset($q['ou_id']) ? (int) $q['ou_id'] : null)]);
        });
    }

    // ── plumbing ────────────────────────────────────────────────────────

    /**
     * `from` and `to`, defaulting to this month so far.
     *
     * @param array<string, string> $q
     * @return array{0: string, 1: string}
     */
    private static function range(array $q): array
    {
        return [$q['from'] ?? gmdate('Y-m-01'), $q['to'] ?? gmdate('Y-m-d')];
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
            error_log('[AccountingApiHandler] ' . $area . ' failed: ' . $e->getMessage());

            return Response::error('Something went wrong with the books. Try again.', 500);
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
