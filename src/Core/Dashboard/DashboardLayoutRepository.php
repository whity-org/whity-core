<?php

declare(strict_types=1);

namespace Whity\Core\Dashboard;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * How one person arranged their dashboard in one workspace (SDK 1.115,
 * WHIT-1177): the order of the cards and widgets from their apps, which they
 * hid, and the width they chose for each widget.
 *
 * A row exists only for an item the person moved, hid or resized; everything
 * else is shown, after the arranged ones, in the order the apps declare it, so
 * a newly installed app appears for everyone. Keys are what an item goes by: a
 * home card by its plugin name (`salons`), a widget by plugin and id
 * (`salons:todays-bookings`). An uninstalled app's rows do nothing, and its
 * items come back as they were.
 */
final class DashboardLayoutRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array<string, array{position: int|null, hidden: bool, size: string|null}>
     */
    public function rowsFor(int $tenantId, int $profileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT item_key, position, hidden, size FROM dashboard_layout
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['item_key']] = [
                'position' => $row['position'] === null ? null : (int) $row['position'],
                'hidden' => DbBool::of($row['hidden']),
                'size' => $row['size'] === null ? null : (string) $row['size'],
            ];
        }

        return $rows;
    }

    /** Hide or show one item, keeping its place and size. */
    public function setHidden(int $tenantId, int $profileId, string $key, bool $hidden): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO dashboard_layout (tenant_id, profile_id, item_key, hidden)
             VALUES (:tenant_id, :profile_id, :item_key, :hidden)
             ON CONFLICT (tenant_id, profile_id, item_key) DO UPDATE SET hidden = excluded.hidden'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':item_key', $key);
        $stmt->bindValue(':hidden', $hidden, PDO::PARAM_BOOL);
        $stmt->execute();
    }

    /**
     * Replace the arrangement of the items the person can see now with
     * `$items`, in order. Rows for items they can't see now (an app turned off
     * for a while) are kept, so those come back where they were.
     *
     * @param list<string> $visibleKeys
     * @param list<array{key: string, hidden: bool, size: string|null}> $items
     */
    public function replace(int $tenantId, int $profileId, array $visibleKeys, array $items): void
    {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            if ($visibleKeys !== []) {
                $marks = implode(', ', array_fill(0, count($visibleKeys), '?'));
                $delete = $this->db->prepare(
                    "DELETE FROM dashboard_layout WHERE tenant_id = ? AND profile_id = ? AND item_key IN ({$marks})"
                );
                $delete->execute([$tenantId, $profileId, ...$visibleKeys]);
            }

            $insert = $this->db->prepare(
                'INSERT INTO dashboard_layout (tenant_id, profile_id, item_key, position, hidden, size)
                 VALUES (:tenant_id, :profile_id, :item_key, :position, :hidden, :size)'
            );
            foreach ($items as $position => $item) {
                $insert->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
                $insert->bindValue(':profile_id', $profileId, PDO::PARAM_INT);
                $insert->bindValue(':item_key', $item['key']);
                $insert->bindValue(':position', $position, PDO::PARAM_INT);
                $insert->bindValue(':hidden', $item['hidden'], PDO::PARAM_BOOL);
                $insert->bindValue(':size', $item['size'], $item['size'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $insert->execute();
            }
            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Sort items by the person's order: arranged ones by position, then the
     * rest in the order given. Stable.
     *
     * @template T of array<string, mixed>
     * @param list<T> $items each with its `key`
     * @param array<string, array{position: int|null, hidden: bool, size: string|null}> $rows
     * @return list<T>
     */
    public static function ordered(array $items, array $rows): array
    {
        $order = [];
        foreach ($items as $i => $item) {
            $key = $item['key'] ?? null;
            $position = is_string($key) ? ($rows[$key]['position'] ?? null) : null;
            $order[$i] = [$position ?? PHP_INT_MAX, $i];
        }
        uasort($order, static fn (array $a, array $b): int => $a <=> $b);

        $out = [];
        foreach (array_keys($order) as $i) {
            $out[] = $items[$i];
        }

        return $out;
    }
}
