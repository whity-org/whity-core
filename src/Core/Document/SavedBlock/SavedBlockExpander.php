<?php

declare(strict_types=1);

namespace Whity\Core\Document\SavedBlock;

/**
 * Synced saved blocks as what they print (editor M7; E.21, E.38).
 *
 * A document keeps a synced block as one `savedBlock` that points at the
 * library. Everything that prints or checks a draft (a preview PDF, a label
 * sheet, the publish check and its size measure) works on the library's
 * current blocks, so a draft, note or wiki page shows a change at once.
 * Publishing stores the version with the blocks expanded as they are at that
 * moment, so what a published template prints never changes until a person
 * publishes it again (E.38).
 *
 * A block that was deleted, or that isn't this workspace's, stays a
 * `savedBlock`: it has no print view, so publishing names it as a blocker and
 * a draft preview leaves it out, as the editor says it's no longer available.
 * Only one level is expanded: a saved block inside a saved block's own
 * blocks stays a reference, so a loop can't form.
 */
final class SavedBlockExpander
{
    public function __construct(private readonly SavedBlockRepository $repo)
    {
    }

    /**
     * @param array<mixed> $blocks
     * @return list<mixed>
     */
    public function expand(int $tenantId, array $blocks): array
    {
        $cache = [];

        return $this->walk($tenantId, array_values($blocks), $cache, true);
    }

    /**
     * @param list<mixed>                          $blocks
     * @param array<int, list<mixed>|null>         $cache  library blocks by id, null when absent
     * @return list<mixed>
     */
    private function walk(int $tenantId, array $blocks, array &$cache, bool $expandRefs): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                $out[] = $block;
                continue;
            }
            $type = $block['type'] ?? null;
            if ($type === 'savedBlock' && $expandRefs) {
                $content = $this->library($tenantId, $block['savedBlockId'] ?? null, $cache);
                if ($content === null) {
                    $out[] = $block;
                    continue;
                }
                array_push($out, ...$this->walk($tenantId, $content, $cache, false));
                continue;
            }
            if ($type === 'toggle' && is_array($block['children'] ?? null)) {
                $block['children'] = $this->walk($tenantId, array_values($block['children']), $cache, $expandRefs);
            }
            if ($type === 'columns' && is_array($block['columns'] ?? null)) {
                $block['columns'] = array_map(
                    fn (mixed $column): mixed => is_array($column) ? $this->walk($tenantId, array_values($column), $cache, $expandRefs) : $column,
                    $block['columns'],
                );
            }
            $out[] = $block;
        }

        return $out;
    }

    /**
     * A live saved block's current blocks, or null when it's deleted or not this workspace's.
     *
     * @param array<int, list<mixed>|null> $cache
     * @return list<mixed>|null
     */
    private function library(int $tenantId, mixed $id, array &$cache): ?array
    {
        if (!(is_int($id) || (is_string($id) && ctype_digit($id)))) {
            return null;
        }
        $id = (int) $id;
        if (!array_key_exists($id, $cache)) {
            $row = $this->repo->find($id, $tenantId);
            $cache[$id] = $row !== null && $row['archived_at'] === null && is_array($row['blocks'])
                ? array_values($row['blocks'])
                : null;
        }

        return $cache[$id];
    }
}
