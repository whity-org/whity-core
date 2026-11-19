<?php

declare(strict_types=1);

namespace Whity\Core\Search;

use PDO;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Text\SearchText;

/**
 * Inventory items in search and ⌘K (WHIT-1232): names in every language,
 * folded the Arabic way, and SKUs. A barcode typed or scanned exactly finds
 * its item too.
 */
final class ItemsSearchSource implements SearchSource
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function type(): string
    {
        return 'item';
    }

    public function label(): array
    {
        return ['en' => 'Items', 'ar' => 'الأصناف'];
    }

    public function permission(): string
    {
        return CorePermissions::INVENTORY_READ;
    }

    public function search(SearchContext $context, string $pattern, int $limit): array
    {
        $sql = 'SELECT i.id, i.name, (SELECT MIN(v.sku) FROM inv_variants v WHERE v.tenant_id = i.tenant_id AND v.item_id = i.id) AS sku
                  FROM inv_items i
                 WHERE i.tenant_id = :tenant_id AND i.archived_at IS NULL
                   AND (' . SearchText::sqlFold('i.names') . " LIKE :pattern ESCAPE '\'
                        OR EXISTS (SELECT 1 FROM inv_variants v2 WHERE v2.tenant_id = i.tenant_id AND v2.item_id = i.id
                                   AND LOWER(v2.sku) LIKE :pattern ESCAPE '\'))
                 ORDER BY i.id DESC LIMIT :limit";

        return array_map(
            static fn (array $row): SearchHit => new SearchHit(
                'item',
                (string) $row['id'],
                (string) $row['name'],
                isset($row['sku']) ? (string) $row['sku'] : null,
                '/inventory/items/' . $row['id'],
            ),
            CoreSearchSources::fetch($this->db, $sql, [':tenant_id' => $context->tenantId, ':pattern' => $pattern], $limit),
        );
    }
}
