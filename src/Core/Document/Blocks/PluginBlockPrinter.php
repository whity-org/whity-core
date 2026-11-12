<?php

declare(strict_types=1);

namespace Whity\Core\Document\Blocks;

use Whity\Sdk\Frontend\Blocks\BlockText;

/**
 * Plugin blocks as Whity prints them (editor M7, E.17–E.18).
 *
 * A plugin block's print view is a declaration in the screen vocabulary's
 * display blocks; this turns it into Whity's own document blocks (headings,
 * paragraphs, lists, tables, dividers) with the block's data filled in where
 * the view names `{{data.key}}`. Whity's printer then draws those like any
 * other: no plugin code takes part in printing.
 *
 * A plugin block that can't be printed stays as it is, so the publish check
 * names it: its plugin is off or gone (E.19, `missing-plugin`), or it has no
 * print view (E.18, `block-without-print-view`).
 */
final class PluginBlockPrinter
{
    public function __construct(private readonly DocumentBlockRegistry $registry)
    {
    }

    /**
     * @param list<mixed> $blocks
     * @return list<mixed>
     */
    public function lower(int $tenantId, array $blocks, string $lang = 'en'): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                $out[] = $block;
                continue;
            }
            $type = $block['type'] ?? null;
            if ($type === 'plugin') {
                $definition = $this->registry->definition($tenantId, (string) ($block['plugin'] ?? ''), (string) ($block['blockType'] ?? ''));
                if ($definition === null || !is_array($definition['print'] ?? null)) {
                    $out[] = $block;
                    continue;
                }
                $data = is_array($block['data'] ?? null) ? $block['data'] : [];
                $tree = BlockText::resolve($definition['print'], $lang);
                array_push($out, ...self::print($tree, self::values($definition, $data, $lang), (string) ($block['id'] ?? 'plugin')));
                continue;
            }
            if ($type === 'toggle' && is_array($block['children'] ?? null)) {
                $block['children'] = $this->lower($tenantId, array_values($block['children']), $lang);
            }
            if ($type === 'columns' && is_array($block['columns'] ?? null)) {
                $block['columns'] = array_map(
                    fn (mixed $c): mixed => is_array($c) ? $this->lower($tenantId, array_values($c), $lang) : $c,
                    $block['columns'],
                );
            }
            $out[] = $block;
        }

        return $out;
    }

    /**
     * The plugins whose blocks in these blocks have no definition here, by the
     * ids of their blocks: off, uninstalled, or no longer declaring them.
     *
     * @param list<mixed> $blocks
     * @return array<string, string> block id => plugin
     */
    public function missing(int $tenantId, array $blocks): array
    {
        $missing = [];
        $walk = function (array $list) use (&$walk, &$missing, $tenantId): void {
            foreach ($list as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $type = $block['type'] ?? null;
                if ($type === 'plugin') {
                    $plugin = (string) ($block['plugin'] ?? '');
                    if ($this->registry->definition($tenantId, $plugin, (string) ($block['blockType'] ?? '')) === null) {
                        $missing[(string) ($block['id'] ?? '')] = $plugin;
                    }
                }
                if ($type === 'toggle' && is_array($block['children'] ?? null)) {
                    $walk($block['children']);
                }
                if ($type === 'columns' && is_array($block['columns'] ?? null)) {
                    foreach ($block['columns'] as $column) {
                        if (is_array($column)) {
                            $walk($column);
                        }
                    }
                }
            }
        };
        $walk($blocks);

        return $missing;
    }

    /**
     * Each field's printed text: a select prints its option's label.
     *
     * @param array<string, mixed> $definition
     * @param array<mixed> $data
     * @return array<string, string>
     */
    private static function values(array $definition, array $data, string $lang): array
    {
        $values = [];
        foreach (is_array($definition['fields'] ?? null) ? $definition['fields'] : [] as $field) {
            if (!is_array($field) || !is_string($field['key'] ?? null)) {
                continue;
            }
            $raw = $data[$field['key']] ?? null;
            $text = is_scalar($raw) ? (string) $raw : '';
            if (($field['type'] ?? null) === 'select' && is_array($field['options'] ?? null)) {
                foreach ($field['options'] as $option) {
                    if (is_array($option) && ($option['value'] ?? null) === $text && is_array($option['label'] ?? null)) {
                        $text = (string) ($option['label'][$lang] ?? $option['label']['en'] ?? $text);
                    }
                }
            }
            $values[$field['key']] = $text;
        }

        return $values;
    }

    /** @param array<string, string> $values */
    private static function fill(mixed $text, array $values): string
    {
        if (!is_string($text)) {
            return '';
        }

        return (string) preg_replace_callback(
            '/\{\{\s*data\.([a-z][a-z0-9_]{0,39})\s*\}\}/',
            static fn (array $m): string => $values[$m[1]] ?? '',
            $text,
        );
    }

    /** @return list<array{type: 'text', text: string}> */
    private static function runs(string $text): array
    {
        return $text === '' ? [] : [['type' => 'text', 'text' => $text]];
    }

    /**
     * @param array<mixed> $tree
     * @param array<string, string> $values
     * @return list<array<string, mixed>>
     */
    private static function print(array $tree, array $values, string $idPrefix): array
    {
        $out = [];
        $n = 0;
        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }
            $id = $idPrefix . '-p' . $n++;
            switch ($node['type'] ?? null) {
                case 'heading':
                    // Print headings sit under the document's own: H1-H3, as the editor's are.
                    $out[] = ['id' => $id, 'type' => 'heading', 'level' => max(1, min(3, (int) ($node['level'] ?? 2) - 1)), 'content' => self::runs(self::fill($node['text'] ?? '', $values))];
                    break;
                case 'text':
                    $out[] = ['id' => $id, 'type' => 'paragraph', 'content' => self::runs(self::fill($node['value'] ?? '', $values))];
                    break;
                case 'divider':
                    $out[] = ['id' => $id, 'type' => 'divider'];
                    break;
                case 'list':
                    foreach (is_array($node['items'] ?? null) ? $node['items'] : [] as $i => $item) {
                        $out[] = ['id' => $id . '-' . $i, 'type' => ($node['ordered'] ?? false) === true ? 'number' : 'bullet', 'level' => 0, 'content' => self::runs(self::fill($item, $values))];
                    }
                    break;
                case 'keyValue':
                    $rows = [];
                    foreach (is_array($node['items'] ?? null) ? $node['items'] : [] as $item) {
                        if (is_array($item)) {
                            $rows[] = [self::runs(self::fill($item['label'] ?? '', $values)), self::runs(self::fill($item['value'] ?? '', $values))];
                        }
                    }
                    if ($rows !== []) {
                        $out[] = ['id' => $id, 'type' => 'table', 'headerRow' => false, 'columns' => [['align' => 'start'], ['align' => 'start']], 'rows' => $rows];
                    }
                    break;
                case 'table':
                    $columns = array_values(array_filter(is_array($node['columns'] ?? null) ? $node['columns'] : [], 'is_array'));
                    $rows = [array_map(static fn (array $c): array => self::runs(self::fill($c['label'] ?? '', $values)), $columns)];
                    foreach (is_array($node['rows'] ?? null) ? $node['rows'] : [] as $row) {
                        if (is_array($row)) {
                            $rows[] = array_map(static fn (array $c): array => self::runs(self::fill($row[$c['key'] ?? ''] ?? '', $values)), $columns);
                        }
                    }
                    $out[] = ['id' => $id, 'type' => 'table', 'headerRow' => true, 'columns' => array_map(static fn (): array => ['align' => 'start'], $columns), 'rows' => $rows];
                    break;
            }
        }

        return $out;
    }
}
