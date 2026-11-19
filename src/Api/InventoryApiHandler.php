<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Contacts\ContactRejectedException;
use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\LocationService;
use Whity\Core\Inventory\ReasonCatalog;
use Whity\Core\Inventory\ReorderRules;
use Whity\Core\Inventory\StockLedger;
use Whity\Core\Inventory\StockOperations;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Inventory: units, categories, items, places and stock (WHIT-1232, design
 * section 31).
 *
 * PERMISSIONS
 * Reading on `inventory:read`; items, units, categories and places on
 * `inventory:write`; opening stock, adjustments and their undo on
 * `stock:adjust`. Approving is never a permission (register IV.19).
 *
 * AGENTS
 * The routes are MCP tools in `core.inventory`. No write declares an undo, so
 * whatever an agent changes is a proposal its person approves (IV.19).
 *
 * Ids from another workspace answer 404, never 403.
 */
final class InventoryApiHandler
{
    private const MAX_PER_PAGE = 200;

    public function __construct(
        private readonly UnitCatalog $units,
        private readonly ItemService $items,
        private readonly LocationService $locations,
        private readonly StockLedger $ledger,
        private readonly ?RoleChecker $roleChecker = null,
        private readonly ?StockOperations $operations = null,
        private readonly ?ReasonCatalog $reasons = null,
        private readonly ?ReorderRules $reorder = null,
        private readonly ?SettingsService $settings = null,
    ) {
    }

    /** The inventory settings people change on Inventory's own settings (WHIT-1233). */
    public const SETTING_KEYS = [
        'approve_adjustments_above' => SettingsRegistry::INVENTORY_APPROVE_ADJUSTMENTS_ABOVE,
        'approve_counts_above' => SettingsRegistry::INVENTORY_APPROVE_COUNTS_ABOVE,
        'adjustment_route_template_id' => SettingsRegistry::INVENTORY_ADJUSTMENT_ROUTE_TEMPLATE_ID,
        'count_route_template_id' => SettingsRegistry::INVENTORY_COUNT_ROUTE_TEMPLATE_ID,
        'transfers_one_step' => SettingsRegistry::INVENTORY_TRANSFERS_ONE_STEP,
    ];

    // ── units and categories ────────────────────────────────────────────

    public function listUnits(Request $request): Response
    {
        return $this->run($request, 'units', fn (int $tenantId): Response => Response::json(['data' => $this->units->list($tenantId)]));
    }

    public function createUnit(Request $request): Response
    {
        return $this->run($request, 'unit', fn (int $tenantId): Response => Response::json(['data' => $this->units->create($tenantId, JsonBody::parsed($request))], 201));
    }

    public function listCategories(Request $request): Response
    {
        return $this->run($request, 'categories', fn (int $tenantId): Response => Response::json(['data' => $this->items->categories($tenantId)]));
    }

    public function createCategory(Request $request): Response
    {
        return $this->run($request, 'category', fn (int $tenantId): Response => Response::json(['data' => $this->items->createCategory($tenantId, JsonBody::parsed($request))], 201));
    }

    // ── items ───────────────────────────────────────────────────────────

    public function listItems(Request $request): Response
    {
        return $this->run($request, 'items', function (int $tenantId) use ($request): Response {
            $q = self::query($request);
            $page = max(1, (int) ($q['page'] ?? 1));
            $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($q['per_page'] ?? 50)));
            $result = $this->items->list($tenantId, [
                'q' => $q['q'] ?? null,
                'kind' => $q['kind'] ?? null,
                'category_id' => isset($q['category_id']) ? (int) $q['category_id'] : null,
                'archived' => ($q['archived'] ?? '') === 'true',
            ], $perPage, ($page - 1) * $perPage);

            return Response::json([
                'data' => $result['data'],
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPage,
                    'total' => $result['total'],
                    'totalPages' => (int) ceil($result['total'] / $perPage),
                ],
            ]);
        });
    }

    public function lookupItem(Request $request): Response
    {
        return $this->run($request, 'lookup', function (int $tenantId) use ($request): Response {
            $code = self::query($request)['code'] ?? '';
            $hit = $this->items->lookup($tenantId, $code);
            if ($hit === null) {
                return Response::error('No item has that barcode or SKU', 404);
            }

            return Response::json(['data' => $hit]);
        });
    }

    public function createItem(Request $request): Response
    {
        return $this->run($request, 'create', function (int $tenantId, ?int $actor) use ($request): Response {
            return Response::json(['data' => $this->items->create($tenantId, JsonBody::parsed($request), $actor, $this->permissions($tenantId, $actor))], 201);
        });
    }

    /** @param array<string, string> $params */
    public function showItem(Request $request, array $params): Response
    {
        return $this->run($request, 'show', fn (int $tenantId): Response => Response::json(['data' => $this->items->show($tenantId, (int) $params['id'])]));
    }

    /** @param array<string, string> $params */
    public function updateItem(Request $request, array $params): Response
    {
        return $this->run($request, 'update', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            return Response::json(['data' => $this->items->update($tenantId, (int) $params['id'], JsonBody::parsed($request), $actor, $this->permissions($tenantId, $actor))]);
        });
    }

    /** @param array<string, string> $params */
    public function archiveItem(Request $request, array $params): Response
    {
        return $this->run($request, 'archive', fn (int $tenantId, ?int $actor): Response => Response::json(['data' => $this->items->archive($tenantId, (int) $params['id'], true, $actor)]));
    }

    /** @param array<string, string> $params */
    public function restoreItem(Request $request, array $params): Response
    {
        return $this->run($request, 'restore', fn (int $tenantId, ?int $actor): Response => Response::json(['data' => $this->items->archive($tenantId, (int) $params['id'], false, $actor)]));
    }

    // ── places ──────────────────────────────────────────────────────────

    public function listLocations(Request $request): Response
    {
        return $this->run($request, 'locations', function (int $tenantId) use ($request): Response {
            $q = self::query($request);

            return Response::json(['data' => $this->locations->list($tenantId, ($q['virtual'] ?? '') === 'true', ($q['inactive'] ?? '') === 'true')]);
        });
    }

    public function createLocation(Request $request): Response
    {
        return $this->run($request, 'location', fn (int $tenantId): Response => Response::json(['data' => $this->locations->create($tenantId, JsonBody::parsed($request))], 201));
    }

    /** @param array<string, string> $params */
    public function showLocation(Request $request, array $params): Response
    {
        return $this->run($request, 'location', fn (int $tenantId): Response => Response::json(['data' => $this->locations->show($tenantId, (int) $params['id'])]));
    }

    /** @param array<string, string> $params */
    public function updateLocation(Request $request, array $params): Response
    {
        return $this->run($request, 'location', fn (int $tenantId): Response => Response::json(['data' => $this->locations->update($tenantId, (int) $params['id'], JsonBody::parsed($request))]));
    }

    // ── stock ───────────────────────────────────────────────────────────

    public function levels(Request $request): Response
    {
        return $this->run($request, 'levels', function (int $tenantId) use ($request): Response {
            $q = self::query($request);

            return Response::json(['data' => $this->ledger->levels($tenantId, [
                'variant_id' => isset($q['variant_id']) ? (int) $q['variant_id'] : null,
                'item_id' => isset($q['item_id']) ? (int) $q['item_id'] : null,
                'location_id' => isset($q['location_id']) ? (int) $q['location_id'] : null,
                'nonzero' => ($q['nonzero'] ?? '') === 'true',
            ])]);
        });
    }

    public function moves(Request $request): Response
    {
        return $this->run($request, 'moves', function (int $tenantId) use ($request): Response {
            $q = self::query($request);
            $int = static fn (string $key): ?int => isset($q[$key]) ? (int) $q[$key] : null;
            $moves = $this->ledger->history($tenantId, [
                'variant_id' => $int('variant_id'),
                'item_id' => $int('item_id'),
                'location_id' => $int('location_id'),
                'source_type' => $q['source_type'] ?? null,
                'batch_key' => $q['batch_key'] ?? null,
                'before_id' => $int('before_id'),
            ], (int) ($q['limit'] ?? 50));
            $last = $moves === [] ? null : $moves[array_key_last($moves)]['id'];

            return Response::json(['data' => $moves, 'next_before_id' => count($moves) >= (int) ($q['limit'] ?? 50) ? $last : null]);
        });
    }

    public function adjust(Request $request): Response
    {
        return $this->run($request, 'adjust', function (int $tenantId, ?int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $lines = $body['lines'] ?? null;
            if (!is_array($lines) || !array_is_list($lines)) {
                throw InventoryRejectedException::field('lines', 'lines is a list of {variant_id, location_id, change}');
            }
            $kind = is_string($body['kind'] ?? null) ? $body['kind'] : 'adjustment';
            if ($kind === 'adjustment' && $this->operations !== null) {
                // WHIT-1233: an adjustment is an operation, so a line worth
                // more than the workspace's value waits for approval (IV.14).
                $places = array_values(array_unique(array_map(static fn (mixed $l): mixed => is_array($l) ? ($l['location_id'] ?? null) : null, $lines), SORT_REGULAR));
                if (count($places) !== 1) {
                    throw InventoryRejectedException::field('lines', 'One adjustment is at one place; send each place on its own');
                }
                $result = $this->operations->adjust($tenantId, $actor, [
                    'location_id' => $places[0],
                    'reason_code' => is_string($body['reason_code'] ?? null) ? $body['reason_code'] : '',
                    'note' => is_string($body['note'] ?? null) ? $body['note'] : '',
                    'occurred_at' => is_string($body['occurred_at'] ?? null) ? $body['occurred_at'] : null,
                    'lines' => $lines,
                ], $this->meta($request, $body));
                $op = $result['operation'];

                return Response::json(['data' => [
                    'batch_key' => $op['batch_key'],
                    'moves' => is_string($op['batch_key']) ? $this->ledger->batch($tenantId, $op['batch_key']) : [],
                    'repeated' => $result['repeated'],
                    'operation' => $op,
                ]], $result['repeated'] ? 200 : 201);
            }
            $result = $this->ledger->adjust(
                $tenantId,
                $actor,
                $kind,
                $lines,
                is_string($body['reason_code'] ?? null) ? $body['reason_code'] : '',
                is_string($body['note'] ?? null) ? $body['note'] : '',
                is_string($body['occurred_at'] ?? null) ? $body['occurred_at'] : null,
                is_string($body['idempotency_key'] ?? null) ? $body['idempotency_key'] : null,
            );

            return Response::json(['data' => $result], $result['repeated'] ? 200 : 201);
        });
    }

    /** @param array<string, string> $params */
    public function undo(Request $request, array $params): Response
    {
        return $this->run($request, 'undo', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            $body = JsonBody::parsed($request);

            return Response::json(['data' => $this->ledger->reverse($tenantId, $params['key'], $actor, is_string($body['note'] ?? null) ? $body['note'] : '')], 201);
        });
    }

    // ── stock operations (WHIT-1233) ────────────────────────────────────

    public function listOperations(Request $request): Response
    {
        return $this->run($request, 'operations', function (int $tenantId) use ($request): Response {
            $q = self::query($request);
            $limit = max(1, min(self::MAX_PER_PAGE, (int) ($q['limit'] ?? 50)));

            return Response::json(['data' => $this->ops()->list($tenantId, [
                'kind' => $q['kind'] ?? null,
                'status' => $q['status'] ?? null,
                'location_id' => isset($q['location_id']) ? (int) $q['location_id'] : null,
            ], $limit, max(0, (int) ($q['offset'] ?? 0)))]);
        });
    }

    /** @param array<string, string> $params */
    public function showOperation(Request $request, array $params): Response
    {
        return $this->run($request, 'operation', function (int $tenantId, ?int $actor) use ($params): Response {
            // A blind count hides expected from the people counting; those
            // who adjust stock see it (IV.15).
            return Response::json(['data' => $this->ops()->get($tenantId, (int) $params['id'], $this->isCounter($tenantId, $actor))]);
        });
    }

    public function createAdjustment(Request $request): Response
    {
        return $this->run($request, 'adjustment', function (int $tenantId, ?int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $result = $this->ops()->adjust($tenantId, $actor, $body, $this->meta($request, $body));

            return Response::json(['data' => $result['operation']], $result['repeated'] ? 200 : 201);
        });
    }

    /** @param array<string, string> $params */
    public function submitAdjustment(Request $request, array $params): Response
    {
        return $this->opAction($request, $params, 'adjustment', fn (int $t, int $id, ?int $actor): array => $this->ops()->submit($t, $id, $actor));
    }

    /** @param array<string, string> $params */
    public function cancelAdjustment(Request $request, array $params): Response
    {
        return $this->opAction($request, $params, 'adjustment', fn (int $t, int $id, ?int $actor): array => $this->ops()->cancel($t, $id, $actor));
    }

    public function startCount(Request $request): Response
    {
        return $this->run($request, 'count', function (int $tenantId, ?int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $result = $this->ops()->startCount($tenantId, $actor, $body, $this->meta($request, $body));

            return Response::json(['data' => $this->ops()->get($tenantId, (int) $result['operation']['id'], $this->isCounter($tenantId, $actor))], $result['repeated'] ? 200 : 201);
        });
    }

    /** @param array<string, string> $params */
    public function recordCount(Request $request, array $params): Response
    {
        return $this->opAction($request, $params, 'count', function (int $t, int $id, ?int $actor) use ($request): array {
            $lines = JsonBody::parsed($request)['lines'] ?? null;
            if (!is_array($lines) || !array_is_list($lines)) {
                throw InventoryRejectedException::field('lines', 'lines is a list of {variant_id, counted}');
            }
            $this->ops()->recordCount($t, $id, $actor, $lines);

            return $this->ops()->get($t, $id, $this->isCounter($t, $actor));
        });
    }

    /** @param array<string, string> $params */
    public function finishCount(Request $request, array $params): Response
    {
        return $this->opAction($request, $params, 'count', fn (int $t, int $id, ?int $actor): array => $this->ops()->finishCount($t, $id, $actor));
    }

    /** @param array<string, string> $params */
    public function cancelCount(Request $request, array $params): Response
    {
        return $this->opAction($request, $params, 'count', fn (int $t, int $id, ?int $actor): array => $this->ops()->cancel($t, $id, $actor));
    }

    public function createTransfer(Request $request): Response
    {
        return $this->run($request, 'transfer', function (int $tenantId, ?int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $result = $this->ops()->transfer($tenantId, $actor, $body, $this->meta($request, $body));

            return Response::json(['data' => $result['operation']], $result['repeated'] ? 200 : 201);
        });
    }

    /** @param array<string, string> $params */
    public function sendTransfer(Request $request, array $params): Response
    {
        return $this->opAction($request, $params, 'transfer', function (int $t, int $id, ?int $actor) use ($request): array {
            $oneStep = JsonBody::parsed($request)['one_step'] ?? null;
            if ($oneStep !== null && !is_bool($oneStep)) {
                throw InventoryRejectedException::field('one_step', 'one_step is true or false');
            }

            return $this->ops()->send($t, $id, $actor, $oneStep);
        });
    }

    /** @param array<string, string> $params */
    public function receiveTransfer(Request $request, array $params): Response
    {
        return $this->opAction($request, $params, 'transfer', function (int $t, int $id, ?int $actor) use ($request): array {
            $lines = JsonBody::parsed($request)['lines'] ?? [];
            if (!is_array($lines) || !array_is_list($lines)) {
                throw InventoryRejectedException::field('lines', 'lines is a list of {line_id, received}');
            }

            return $this->ops()->receive($t, $id, $actor, $lines);
        });
    }

    /** @param array<string, string> $params */
    public function cancelTransfer(Request $request, array $params): Response
    {
        return $this->opAction($request, $params, 'transfer', fn (int $t, int $id, ?int $actor): array => $this->ops()->cancel($t, $id, $actor));
    }

    // ── reasons, reorder levels and settings (WHIT-1233) ────────────────

    public function listReasons(Request $request): Response
    {
        return $this->run($request, 'reasons', function (int $tenantId) use ($request): Response {
            return Response::json(['data' => $this->reasonCatalog()->list($tenantId, (self::query($request)['archived'] ?? '') === 'true')]);
        });
    }

    public function createReason(Request $request): Response
    {
        return $this->run($request, 'reason', function (int $tenantId) use ($request): Response {
            return Response::json(['data' => $this->reasonCatalog()->create($tenantId, JsonBody::parsed($request))], 201);
        });
    }

    /** @param array<string, string> $params */
    public function updateReason(Request $request, array $params): Response
    {
        return $this->run($request, 'reason', function (int $tenantId) use ($request, $params): Response {
            return Response::json(['data' => $this->reasonCatalog()->update($tenantId, (int) $params['id'], JsonBody::parsed($request))]);
        });
    }

    public function listReorderRules(Request $request): Response
    {
        return $this->run($request, 'reorder', function (int $tenantId) use ($request): Response {
            $q = self::query($request);

            return Response::json(['data' => $this->reorderRules()->list($tenantId, [
                'variant_id' => isset($q['variant_id']) ? (int) $q['variant_id'] : null,
                'location_id' => isset($q['location_id']) ? (int) $q['location_id'] : null,
                'below' => ($q['below'] ?? '') === 'true',
            ])]);
        });
    }

    public function setReorderRule(Request $request): Response
    {
        return $this->run($request, 'reorder', function (int $tenantId) use ($request): Response {
            return Response::json(['data' => $this->reorderRules()->set($tenantId, JsonBody::parsed($request))]);
        });
    }

    /** @param array<string, string> $params */
    public function deleteReorderRule(Request $request, array $params): Response
    {
        return $this->run($request, 'reorder', function (int $tenantId) use ($params): Response {
            $this->reorderRules()->remove($tenantId, (int) $params['id']);

            return Response::json(['data' => ['deleted' => true]]);
        });
    }

    public function showSettings(Request $request): Response
    {
        return $this->run($request, 'settings', fn (int $tenantId): Response => Response::json(['data' => $this->settingsFor($tenantId)]));
    }

    public function updateSettings(Request $request): Response
    {
        return $this->run($request, 'settings', function (int $tenantId) use ($request): Response {
            $settings = $this->settings ?? throw new \LogicException('Settings are not wired');
            $changes = [];
            foreach (JsonBody::parsed($request) as $field => $value) {
                $key = self::SETTING_KEYS[$field] ?? throw InventoryRejectedException::field((string) $field, $field . ' is not an inventory setting');
                $text = match (true) {
                    is_bool($value) => $value ? 'true' : 'false',
                    is_int($value) => (string) $value,
                    $value === null => in_array($field, ['approve_adjustments_above', 'approve_counts_above'], true) ? '' : '0',
                    is_string($value) => trim($value),
                    default => throw InventoryRejectedException::field((string) $field, $field . ' doesn\'t look right'),
                };
                $problem = SettingsRegistry::validate($key, $text);
                if ($problem !== null) {
                    throw InventoryRejectedException::field((string) $field, $problem);
                }
                $changes[$key] = $text;
            }
            foreach ($changes as $key => $text) {
                $settings->setTenant($tenantId, $key, $text);
            }

            return Response::json(['data' => $this->settingsFor($tenantId)]);
        });
    }

    /** @return array<string, mixed> */
    private function settingsFor(int $tenantId): array
    {
        $effective = $this->settings?->effective($tenantId) ?? [];
        $defaults = SettingsRegistry::defaults();
        $out = [];
        foreach (self::SETTING_KEYS as $field => $key) {
            $value = (string) ($effective[$key] ?? $defaults[$key]);
            $out[$field] = match ($field) {
                'transfers_one_step' => $value === 'true',
                'adjustment_route_template_id', 'count_route_template_id' => (int) $value === 0 ? null : (int) $value,
                default => $value === '' ? null : $value,
            };
        }

        return $out;
    }

    private function isCounter(int $tenantId, ?int $actor): bool
    {
        return !($this->permissions($tenantId, $actor))(CorePermissions::STOCK_ADJUST);
    }

    /**
     * @param array<string, string> $params
     * @param callable(int, int, ?int): array<string, mixed> $work
     */
    private function opAction(Request $request, array $params, string $kind, callable $work): Response
    {
        return $this->run($request, $kind, function (int $tenantId, ?int $actor) use ($params, $kind, $work): Response {
            $id = (int) $params['id'];
            $row = $this->ops()->row($tenantId, $id);
            if ($row === null || $row['kind'] !== $kind) {
                throw InventoryRejectedException::notFound(ucfirst($kind));
            }

            return Response::json(['data' => $work($tenantId, $id, $actor)]);
        });
    }

    /**
     * The idempotency key a caller sent. An agent's change reaches here only
     * once its person approved the proposal, which keeps the agent on its own
     * trail (agent_proposals).
     *
     * @param array<string, mixed> $body
     * @return array{idempotency_key: ?string}
     */
    private function meta(Request $request, array $body): array
    {
        return [
            'idempotency_key' => is_string($body['idempotency_key'] ?? null) && $body['idempotency_key'] !== '' ? $body['idempotency_key'] : null,
        ];
    }

    private function ops(): StockOperations
    {
        return $this->operations ?? throw new \LogicException('Stock operations are not wired');
    }

    private function reasonCatalog(): ReasonCatalog
    {
        return $this->reasons ?? throw new \LogicException('Reasons are not wired');
    }

    private function reorderRules(): ReorderRules
    {
        return $this->reorder ?? throw new \LogicException('Reorder levels are not wired');
    }

    // ── plumbing ────────────────────────────────────────────────────────

    /** @param callable(int, ?int): Response $work */
    private function run(Request $request, string $area, callable $work): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                return Response::error('A workspace is needed for inventory', 403);
            }
            $user = $request->user;
            $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;

            return $work($tenantId, $actor);
        } catch (InventoryRejectedException $e) {
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (ContactRejectedException $e) {
            // The workspace's own fields on items answer with this one.
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (\Throwable $e) {
            error_log('[InventoryApiHandler] ' . $area . ' failed: ' . $e->getMessage());

            return Response::error('Something went wrong with inventory. Try again.', 500);
        }
    }

    /** @return callable(string): bool */
    private function permissions(int $tenantId, ?int $actor): callable
    {
        $checker = $this->roleChecker;

        return static fn (string $permission): bool => $checker !== null && $actor !== null
            && $checker->hasPermissionForProfile($actor, $permission, $tenantId);
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
