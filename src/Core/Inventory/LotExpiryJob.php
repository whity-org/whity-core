<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\Hooks\HookManager;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\JobInterface;

/**
 * Says which batches are expiring and which have expired (WHIT-1235, design
 * 31 §3.5): `stock.lot.expiring` for a batch with stock inside the
 * workspace's warning window, `stock.lot.expired` the day after it expires,
 * each once per batch. The Inventory page's "Expiring soon" and the Stock
 * assistant read the same lots. Queued once a day by `schedule:run`.
 */
final class LotExpiryJob implements JobInterface
{
    public const NAME = 'core.inventory.lot_expiry';

    /** @param (\Closure(): string)|null $today the date, `YYYY-MM-DD` (UTC); for tests */
    public function __construct(
        private readonly LotService $lots,
        private readonly HookManager $hooks,
        private readonly ?\Closure $today = null,
    ) {
    }

    public function handle(array $payload): array
    {
        $today = $this->today === null ? gmdate('Y-m-d') : ($this->today)();
        $told = ['expiring' => 0, 'expired' => 0];
        foreach ($this->lots->due($today) as $due) {
            $lot = $this->lots->get($due['tenant_id'], $due['id']);
            $event = [
                'tenant_id' => $due['tenant_id'], 'lot_id' => $due['id'], 'variant_id' => $due['variant_id'], 'item_id' => $lot['item_id'],
                'code' => $due['code'], 'expires_on' => $due['expires_on'], 'on_hand' => $lot['on_hand'],
            ];
            $expired = $due['state'] === 'expired';
            try {
                $this->hooks->dispatch($expired ? Events::STOCK_LOT_EXPIRED : Events::STOCK_LOT_EXPIRING, $event);
                $this->hooks->dispatchAsync($expired ? Events::STOCK_LOT_EXPIRED_ASYNC : Events::STOCK_LOT_EXPIRING_ASYNC, $event);
            } catch (\Throwable $e) {
                // One listener's failure doesn't keep the other batches from being told.
                error_log('[inventory] ' . ($expired ? 'stock.lot.expired' : 'stock.lot.expiring') . ' for lot ' . $due['id'] . ' failed: ' . $e->getMessage());
            }
            $this->lots->markTold($due['tenant_id'], $due['id'], $due['state'], $today);
            $told[$due['state']]++;
        }

        return $told;
    }
}
