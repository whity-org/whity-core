<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Contacts\ContactRejectedException;
use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\LocationService;
use Whity\Core\Inventory\StockLedger;
use Whity\Core\Inventory\UnitCatalog;
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
    ) {
    }

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
            $result = $this->ledger->adjust(
                $tenantId,
                $actor,
                is_string($body['kind'] ?? null) ? $body['kind'] : 'adjustment',
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
