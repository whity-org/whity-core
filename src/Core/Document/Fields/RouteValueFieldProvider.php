<?php

declare(strict_types=1);

namespace Whity\Core\Document\Fields;

use PDO;
use Whity\Core\Document\Routing\RouteStepInputs;

/**
 * The values people enter at route steps (WHIT-1016), offered to every
 * template as `route.value.<key>`: a committee meeting number printed on an
 * approval certificate, for example.
 *
 * The picker lists every value the workspace's route designs ask for. Like
 * {@see IssuedDocumentNumberFieldProvider}, it resolves to nothing here: a
 * value exists only on one route, so the final document stage fills it from
 * that route ({@see \Whity\Core\Document\Routing\RouteFinaliser}), and a
 * preview shows the sample.
 *
 * @i18n-keys documents
 *   fields.group.routeValues = Entered during review
 */
final class RouteValueFieldProvider implements DocumentFieldProvider
{
    public const PREFIX = 'route.value.';

    private FieldLabels $labels;

    public function __construct(private readonly PDO $db, ?FieldLabels $labels = null)
    {
        $this->labels = $labels ?? new FieldLabels();
    }

    public static function path(string $key): string
    {
        return self::PREFIX . $key;
    }

    public function recordType(): string
    {
        return '*';
    }

    public function recordLabel(): string
    {
        return $this->labels->get('fields.group.routeValues', 'Entered during review');
    }

    public function groups(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT inputs FROM document_route_template_steps
              WHERE tenant_id = :tenant_id AND inputs IS NOT NULL
              ORDER BY template_id ASC, position ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $stored) {
            foreach (RouteStepInputs::decode($stored) as $input) {
                // Two designs asking for the same key print the same field once.
                $items[$input['key']] ??= [
                    'path' => self::path($input['key']),
                    'label' => $input['label'],
                    'sample' => $input['label'],
                ];
            }
        }
        if ($items === []) {
            return [];
        }

        return [['group' => $this->recordLabel(), 'items' => array_values($items)]];
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): array
    {
        return ['label' => '', 'values' => []];
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        return [];
    }
}
