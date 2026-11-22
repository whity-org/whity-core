<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\LotService;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Batches and serial numbers (WHIT-1235, design 31 §3.5): an item's batches
 * with what each holds where, one batch, what is expiring, and correcting a
 * batch's dates or notes. Reading needs `inventory:read`, correcting
 * `inventory:write`; the router checks both.
 */
final class InventoryLotApiHandler
{
    public function __construct(private readonly LotService $lots)
    {
    }

    public function list(Request $request): Response
    {
        return $this->run('list', function (int $tenantId) use ($request): Response {
            $q = self::query($request);
            $item = self::id($q['item_id'] ?? null);
            $variant = self::id($q['variant_id'] ?? null);
            if ($item === null && $variant === null) {
                throw InventoryRejectedException::field('item_id', 'Give item_id or variant_id');
            }

            return Response::json(['data' => $this->lots->list($tenantId, [
                'item_id' => $item, 'variant_id' => $variant, 'with_empty' => ($q['with_empty'] ?? '') === 'true',
            ])]);
        });
    }

    public function expiring(Request $request): Response
    {
        return $this->run('expiring', function (int $tenantId) use ($request): Response {
            $raw = self::query($request)['days'] ?? null;
            $days = null;
            if ($raw !== null && $raw !== '') {
                if (preg_match('/^\d{1,4}$/', $raw) !== 1 || (int) $raw > 3650) {
                    throw InventoryRejectedException::field('days', 'days is a whole number from 0 to 3650');
                }
                $days = (int) $raw;
            }

            return Response::json(['data' => $this->lots->expiring($tenantId, $days)]);
        });
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        return $this->run('show', fn (int $tenantId): Response => Response::json(['data' => $this->lots->get($tenantId, (int) $params['id'])]));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        return $this->run('update', fn (int $tenantId): Response => Response::json([
            'data' => $this->lots->update($tenantId, (int) $params['id'], array_intersect_key(JsonBody::parsed($request), ['expires_on' => true, 'made_on' => true, 'notes' => true])),
        ]));
    }

    private function run(string $area, callable $work): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                return Response::error('A workspace is needed for inventory', 403);
            }

            return $work($tenantId);
        } catch (InventoryRejectedException $e) {
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (\Throwable $e) {
            error_log('[InventoryLotApiHandler] ' . $area . ' failed: ' . $e->getMessage());

            return Response::error('Something went wrong with inventory. Try again.', 500);
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

    private static function id(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || preg_match('/^[1-9]\d{0,9}$/', $raw) !== 1) {
            throw InventoryRejectedException::field('item_id', 'item_id and variant_id are ids');
        }

        return (int) $raw;
    }
}
