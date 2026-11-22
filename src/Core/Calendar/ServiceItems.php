<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use PDO;
use Whity\Core\i18n\LocalizedText;
use Whity\Core\Inventory\InventoryNames;
use Whity\Core\Inventory\ItemService;

/**
 * Every calendar service is an inventory service item (WHIT-1334, design
 * section 35 §5, register SL.8): the item holds the name and the price, so
 * the price list, quotes and later invoices sell the same thing at the same
 * price; the calendar service keeps how long it takes, who does it, the room
 * and online booking.
 *
 * The item's price is the price. The calendar keeps a copy on
 * `calendar_services` so its readers and the public booking page read it as
 * before:
 *  - a service saved in the calendar is written to its item here
 *    ({@see sync()}, called after the calendar saves);
 *  - an item changed in inventory is copied back onto its service
 *    ({@see fromItem()}, on `inventory.item.updated` and `.archived`).
 * Both directions compare before writing, so the round trip stops at once.
 *
 * Service items have no stock and don't count on the items meter (owner, Q2,
 * "Accept all"; {@see \Whity\Core\Usage\PlanLimitsReport::liveItems()}).
 */
final class ServiceItems
{
    public function __construct(
        private readonly PDO $db,
        private readonly ServiceRepository $services,
        private readonly ItemService $items,
    ) {
    }

    /**
     * Make or update the item of one calendar service.
     *
     * @return int the item's id
     */
    public function sync(int $tenantId, int $serviceId, ?int $actor = null): int
    {
        $service = $this->services->find($tenantId, $serviceId) ?? throw CalendarRejectedException::notFound('Service');
        $name = is_array($service['name']) ? array_filter($service['name'], static fn (mixed $v): bool => is_string($v) && trim($v) !== '') : [];
        $price = is_int($service['price_minor']) ? $service['price_minor'] : null;
        $currency = is_string($service['currency']) && preg_match('/^[A-Z]{3}$/', $service['currency']) === 1 ? $service['currency'] : null;
        $active = $service['active'] === true;

        $itemId = is_int($service['item_id']) ? $service['item_id'] : null;
        $item = $itemId !== null ? $this->items->row($tenantId, $itemId) : null;
        if ($item === null) {
            $made = $this->items->create($tenantId, array_filter([
                'name' => $name !== [] ? $name : ['en' => 'Service ' . $serviceId],
                'kind' => 'service',
                'sale_price_minor' => $price,
                'currency' => $currency,
            ], static fn (mixed $v): bool => $v !== null), $actor);
            $itemId = (int) $made['id'];
            $this->services->setItem($tenantId, $serviceId, $itemId);
            if (!$active) {
                $this->items->archive($tenantId, $itemId, true, $actor);
            }

            return $itemId;
        }

        $changes = [];
        if ($name !== [] && $name !== $this->names($item)) {
            $changes['name'] = $name;
        }
        if ($price !== (isset($item['sale_price_minor']) ? (int) $item['sale_price_minor'] : null)) {
            $changes['sale_price_minor'] = $price;
        }
        if ($currency !== null && $currency !== (is_string($item['currency'] ?? null) ? trim($item['currency']) : null)) {
            $changes['currency'] = $currency;
        }
        if ($changes !== []) {
            $this->items->update($tenantId, $itemId, $changes, $actor);
        }
        $archived = ($item['archived_at'] ?? null) !== null;
        if ($archived === $active) {
            $this->items->archive($tenantId, $itemId, !$active, $actor);
        }

        return $itemId;
    }

    /**
     * Link every service of a workspace that has no item yet.
     *
     * @return int how many were linked
     */
    public function linkAll(int $tenantId): int
    {
        $stmt = $this->db->prepare('SELECT id FROM calendar_services WHERE tenant_id = :t AND item_id IS NULL ORDER BY id');
        $stmt->execute([':t' => $tenantId]);
        $linked = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->sync($tenantId, (int) $id);
            $linked++;
        }

        return $linked;
    }

    /** Copy an item's name, price and archived state onto its calendar service, if it has one. */
    public function fromItem(int $tenantId, int $itemId): void
    {
        $serviceId = $this->services->idForItem($tenantId, $itemId);
        $item = $this->items->row($tenantId, $itemId);
        if ($serviceId === null || $item === null) {
            return;
        }
        $service = $this->services->find($tenantId, $serviceId);
        if ($service === null) {
            return;
        }
        $name = $this->names($item);
        $price = isset($item['sale_price_minor']) ? (int) $item['sale_price_minor'] : null;
        $currency = is_string($item['currency'] ?? null) && trim($item['currency']) !== '' ? trim($item['currency']) : (string) $service['currency'];
        $active = ($item['archived_at'] ?? null) === null;
        if ($name === $service['name'] && $price === $service['price_minor'] && $currency === $service['currency'] && $active === $service['active']) {
            return;
        }
        $this->services->copyFromItem($tenantId, $serviceId, $name !== [] ? $name : (array) $service['name'], $price, $currency, $active);
    }

    /**
     * The item's names in the calendar's shape: language => text.
     *
     * @param array<string, mixed> $item
     * @return array<string, string>
     */
    private function names(array $item): array
    {
        $names = InventoryNames::decode($item['names'] ?? '{}', (string) ($item['name'] ?? ''));
        $out = [];
        foreach ($names as $language => $text) {
            if (LocalizedText::isLanguageCode($language) && trim($text) !== '') {
                $out[$language] = $text;
            }
        }
        ksort($out);

        return $out;
    }
}
