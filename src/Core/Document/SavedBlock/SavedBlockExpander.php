<?php

declare(strict_types=1);

namespace Whity\Core\Document\SavedBlock;

use Whity\Core\Document\Blocks\PluginBlockPrinter;

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
 *
 * Per-document parts (E.23): in the original, each run of consecutive text
 * or fields marked `perDocument` in a block's line is one part, keyed
 * `<block id>#<n>` (as the editor's saved-parts.ts finds them). A document's
 * `overrides` fill parts by key; a part it hasn't filled prints the
 * original's text.
 */
final class SavedBlockExpander
{
    /**
     * @param PluginBlockPrinter|null $plugins Plugin blocks (editor M7, E.17–E.18): turned into
     *        Whity's own blocks from their print views, after saved blocks are expanded, so a
     *        plugin block inside a saved block prints too.
     */
    public function __construct(
        private readonly SavedBlockRepository $repo,
        private readonly ?PluginBlockPrinter $plugins = null,
    ) {
    }

    /**
     * @param array<mixed> $blocks
     * @return list<mixed>
     */
    public function expand(int $tenantId, array $blocks, string $lang = 'en'): array
    {
        $cache = [];
        $expanded = $this->walk($tenantId, array_values($blocks), $cache, true);

        return $this->plugins?->lower($tenantId, $expanded, $lang) ?? $expanded;
    }

    /**
     * Plugin blocks in these (expanded) blocks whose plugin is off or gone, by block id.
     *
     * @param list<mixed> $blocks
     * @return array<string, string>
     */
    public function missingPlugins(int $tenantId, array $blocks): array
    {
        return $this->plugins?->missing($tenantId, $blocks) ?? [];
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
                $overrides = is_array($block['overrides'] ?? null) ? $block['overrides'] : [];
                array_push($out, ...self::fill($this->walk($tenantId, $content, $cache, false), $overrides));
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

    /** The blocks whose `content` is a line of text, and so can hold a part. */
    private const TEXT_TYPES = ['paragraph', 'heading', 'quote', 'bullet', 'number', 'checklist', 'callout'];

    /**
     * The blocks with each part a document filled replaced by its own text.
     *
     * @param list<mixed>          $blocks
     * @param array<mixed>         $overrides part key => Inline[]
     * @return list<mixed>
     */
    private static function fill(array $blocks, array $overrides): array
    {
        if ($overrides === []) {
            return $blocks;
        }
        $out = [];
        foreach ($blocks as $block) {
            if (is_array($block)) {
                $type = $block['type'] ?? null;
                if (in_array($type, self::TEXT_TYPES, true) && is_array($block['content'] ?? null)) {
                    $block['content'] = self::fillLine((string) ($block['id'] ?? ''), array_values($block['content']), $overrides);
                }
                if ($type === 'toggle' && is_array($block['children'] ?? null)) {
                    $block['children'] = self::fill(array_values($block['children']), $overrides);
                }
                if ($type === 'columns' && is_array($block['columns'] ?? null)) {
                    $block['columns'] = array_map(
                        static fn (mixed $c): mixed => is_array($c) ? self::fill(array_values($c), $overrides) : $c,
                        $block['columns'],
                    );
                }
            }
            $out[] = $block;
        }

        return $out;
    }

    /**
     * @param list<mixed>  $runs
     * @param array<mixed> $overrides
     * @return list<mixed>
     */
    private static function fillLine(string $blockId, array $runs, array $overrides): array
    {
        $out = [];
        $n = 0;
        $inPart = false;
        $key = null;
        foreach ($runs as $run) {
            $part = self::isPerDocument($run);
            if ($part && !$inPart) {
                $key = $blockId . '#' . $n++;
                $own = $overrides[$key] ?? null;
                if (is_array($own) && $own !== []) {
                    array_push($out, ...array_values($own));
                }
            }
            $inPart = $part;
            if ($part && is_array($overrides[$key] ?? null) && $overrides[$key] !== []) {
                continue; // replaced by the document's own text
            }
            $out[] = $run;
        }

        return $out;
    }

    private static function isPerDocument(mixed $run): bool
    {
        if (!is_array($run)) {
            return false;
        }
        $marked = false;
        foreach (is_array($run['marks'] ?? null) ? $run['marks'] : [] as $mark) {
            if (is_array($mark) && ($mark['type'] ?? null) === 'perDocument') {
                $marked = true;
            }
        }
        $type = $run['type'] ?? null;

        return ($type === 'text' && $marked) || ($type === 'field' && ($marked || ($run['perDocument'] ?? false) === true));
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
