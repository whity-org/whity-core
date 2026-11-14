<?php

declare(strict_types=1);

namespace Whity\Core\Document\SavedBlock;

/**
 * The parts of a synced saved block that each document fills itself (E.23,
 * E.23a), found the same way as the editor's `saved-parts.ts`.
 *
 * A part is text carrying the `perDocument` mark, or a merge field with
 * `perDocument` set (or the mark). Since design 3.4 (E.23a) each part has a
 * stable key, `pd_` + 8 characters, minted once when the part is marked and
 * carried on the mark (`{type: 'perDocument', key}`) or the field
 * (`perDocument: key`); adjacent runs with the same key are one part, and a
 * document's `overrides` are keyed by it.
 *
 * Rows from before 3.4 have no key: each run of consecutive marked text and
 * fields in a block's line is one part, named by where it sits,
 * `<block id>#<n>`. Migration 270 mints keys for those; the legacy name is
 * still worked out for every part, so overrides stored under it (frozen
 * published versions, which are never rewritten) keep filling the same part.
 */
final class PerDocumentParts
{
    /** The blocks whose `content` is a line of text, and so can hold a part. */
    public const TEXT_TYPES = ['paragraph', 'heading', 'quote', 'bullet', 'number', 'checklist', 'callout'];

    public const KEY_PREFIX = 'pd_';

    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_-';

    /** Longest label kept for a part (the "Fill in" prompt). */
    private const MAX_LABEL = 120;

    /** A fresh part key: `pd_` + 8 characters of a nanoid alphabet. */
    public static function mint(): string
    {
        $out = self::KEY_PREFIX;
        for ($i = 0; $i < 8; $i++) {
            $out .= self::ALPHABET[random_int(0, 63)];
        }

        return $out;
    }

    /**
     * The part a run belongs to: null when it isn't one, '' for a part with
     * no key yet (before 3.4), else its key.
     */
    public static function keyOf(mixed $run): ?string
    {
        if (!is_array($run)) {
            return null;
        }
        $type = $run['type'] ?? null;
        $markKey = null;
        foreach (is_array($run['marks'] ?? null) ? $run['marks'] : [] as $mark) {
            if (is_array($mark) && ($mark['type'] ?? null) === 'perDocument') {
                $markKey = is_string($mark['key'] ?? null) ? $mark['key'] : '';
            }
        }
        if ($type === 'text') {
            return $markKey;
        }
        if ($type === 'field') {
            $own = $run['perDocument'] ?? null;
            if (is_string($own) && $own !== '') {
                return $own;
            }
            if ($own === true) {
                return $markKey ?? '';
            }

            return $markKey;
        }

        return null;
    }

    /**
     * A block's line split into plain runs and parts, in order. Each part has
     * its `key` (its own, or the legacy name when it has none) and `legacy`,
     * the name pre-3.4 rows gave it.
     *
     * @param list<mixed> $runs
     * @return list<array{key: string|null, legacy: string|null, keyed: bool, runs: list<mixed>}>
     */
    public static function segments(string $blockId, array $runs): array
    {
        $out = [];
        $n = 0;
        $open = false;
        $current = null; // the open segment's key ('' = legacy), or null for plain runs
        $buffer = [];
        foreach ($runs as $run) {
            $key = self::keyOf($run);
            if ($open && $key === $current) {
                $buffer[] = $run;
                continue;
            }
            if ($open) {
                $out[] = self::segment($blockId, $current, $n, $buffer);
            }
            $open = true;
            $current = $key;
            $buffer = [$run];
        }
        if ($open) {
            $out[] = self::segment($blockId, $current, $n, $buffer);
        }

        return $out;
    }

    /**
     * @param list<mixed> $runs
     * @return array{key: string|null, legacy: string|null, keyed: bool, runs: list<mixed>}
     */
    private static function segment(string $blockId, ?string $key, int &$n, array $runs): array
    {
        if ($key === null) {
            return ['key' => null, 'legacy' => null, 'keyed' => false, 'runs' => $runs];
        }
        $legacy = $blockId . '#' . $n++;

        return ['key' => $key === '' ? $legacy : $key, 'legacy' => $legacy, 'keyed' => $key !== '', 'runs' => $runs];
    }

    /**
     * What a document holds for a part: its own key first, then the legacy
     * name. Null when it holds nothing (an empty list counts as nothing).
     *
     * @param array<mixed> $overrides
     * @param array{key: string|null, legacy: string|null} $segment
     * @return list<mixed>|null
     */
    public static function lookup(array $overrides, array $segment): ?array
    {
        foreach ([$segment['key'], $segment['legacy']] as $name) {
            if ($name !== null && is_array($overrides[$name] ?? null) && $overrides[$name] !== []) {
                return array_values($overrides[$name]);
            }
        }

        return null;
    }

    /**
     * Gives every part without a key one, writing it on its marks and fields.
     * `$map` maps each part's legacy name to its key: reused when present (so
     * a saved block's versions agree), filled in for every part seen.
     *
     * @param list<mixed>           $blocks
     * @param array<string, string> $map
     * @param (callable(): string)|null $mint
     * @return list<mixed>
     */
    public static function assignKeys(array $blocks, array &$map, ?callable $mint = null): array
    {
        $mint ??= self::mint(...);
        $out = [];
        foreach ($blocks as $block) {
            if (is_array($block)) {
                $type = $block['type'] ?? null;
                if (in_array($type, self::TEXT_TYPES, true) && is_array($block['content'] ?? null)) {
                    $runs = [];
                    foreach (self::segments((string) ($block['id'] ?? ''), array_values($block['content'])) as $seg) {
                        if ($seg['key'] === null) {
                            array_push($runs, ...$seg['runs']);
                            continue;
                        }
                        $legacy = (string) $seg['legacy'];
                        if ($seg['keyed']) {
                            $map[$legacy] ??= $seg['key'];
                            array_push($runs, ...$seg['runs']);
                            continue;
                        }
                        $key = $map[$legacy] ??= $mint();
                        foreach ($seg['runs'] as $run) {
                            $runs[] = self::withKey($run, $key);
                        }
                    }
                    $block['content'] = $runs;
                }
                if ($type === 'toggle' && is_array($block['children'] ?? null)) {
                    $block['children'] = self::assignKeys(array_values($block['children']), $map, $mint);
                }
                if ($type === 'columns' && is_array($block['columns'] ?? null)) {
                    $columns = [];
                    foreach ($block['columns'] as $i => $column) {
                        $columns[$i] = is_array($column) ? self::assignKeys(array_values($column), $map, $mint) : $column;
                    }
                    $block['columns'] = $columns;
                }
            }
            $out[] = $block;
        }

        return $out;
    }

    /**
     * The parts in reading order, each once, with what the creator gave them
     * (label, required, placeholder) kept by key. A part with no label is
     * labelled with its own text.
     *
     * @param list<mixed>                          $blocks
     * @param array<string, array<string, mixed>>  $given  part by key
     * @return list<array{key: string, label: array{en: string, ar?: string}, kind: string, required?: bool, placeholder?: list<mixed>}>
     */
    public static function fromBlocks(array $blocks, array $given = []): array
    {
        $found = [];
        self::collect($blocks, $found);
        $out = [];
        foreach ($found as $key => $runs) {
            $fields = array_filter($runs, static fn (mixed $r): bool => is_array($r) && ($r['type'] ?? null) === 'field');
            $part = [
                'key'   => $key,
                'label' => self::label($given[$key]['label'] ?? null) ?? ['en' => self::text($runs, $key)],
                'kind'  => count($fields) === count($runs) ? 'field' : 'text',
            ];
            if (is_bool($given[$key]['required'] ?? null)) {
                $part['required'] = $given[$key]['required'];
            }
            if (is_array($given[$key]['placeholder'] ?? null) && array_is_list($given[$key]['placeholder'])) {
                $part['placeholder'] = $given[$key]['placeholder'];
            }
            $out[] = $part;
        }

        return $out;
    }

    /**
     * @param list<mixed>                     $blocks
     * @param array<string, list<mixed>>      $found
     */
    private static function collect(array $blocks, array &$found): void
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? null;
            if (in_array($type, self::TEXT_TYPES, true) && is_array($block['content'] ?? null)) {
                foreach (self::segments((string) ($block['id'] ?? ''), array_values($block['content'])) as $seg) {
                    if ($seg['key'] !== null) {
                        $found[$seg['key']] = [...($found[$seg['key']] ?? []), ...$seg['runs']];
                    }
                }
            }
            if ($type === 'toggle' && is_array($block['children'] ?? null)) {
                self::collect(array_values($block['children']), $found);
            }
            if ($type === 'columns' && is_array($block['columns'] ?? null)) {
                foreach ($block['columns'] as $column) {
                    if (is_array($column)) {
                        self::collect(array_values($column), $found);
                    }
                }
            }
        }
    }

    private static function withKey(mixed $run, string $key): mixed
    {
        if (!is_array($run)) {
            return $run;
        }
        if (is_array($run['marks'] ?? null)) {
            $run['marks'] = array_map(
                static fn (mixed $m): mixed => is_array($m) && ($m['type'] ?? null) === 'perDocument' ? ['type' => 'perDocument', 'key' => $key] : $m,
                $run['marks'],
            );
        }
        if (($run['type'] ?? null) === 'field' && ($run['perDocument'] ?? null) !== null) {
            $run['perDocument'] = $key;
        }

        return $run;
    }

    /** @return array{en: string, ar?: string}|null */
    private static function label(mixed $label): ?array
    {
        if (!is_array($label) || !is_string($label['en'] ?? null) || trim($label['en']) === '') {
            return null;
        }
        $out = ['en' => mb_substr(trim($label['en']), 0, self::MAX_LABEL)];
        if (is_string($label['ar'] ?? null) && trim($label['ar']) !== '') {
            $out['ar'] = mb_substr(trim($label['ar']), 0, self::MAX_LABEL);
        }

        return $out;
    }

    /** @param list<mixed> $runs */
    private static function text(array $runs, string $fallback): string
    {
        $text = '';
        foreach ($runs as $run) {
            if (!is_array($run)) {
                continue;
            }
            $text .= match ($run['type'] ?? null) {
                'text'  => (string) ($run['text'] ?? ''),
                'field' => (string) (($run['label'] ?? '') !== '' ? $run['label'] : ($run['path'] ?? '')),
                default => '',
            };
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? $fallback : mb_substr($text, 0, self::MAX_LABEL);
    }
}
