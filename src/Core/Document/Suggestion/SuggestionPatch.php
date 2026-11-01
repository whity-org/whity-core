<?php

declare(strict_types=1);

namespace Whity\Core\Document\Suggestion;

/**
 * A suggestion's patch (editor M5; IMPLEMENTATION-NOTES 3.3): what it would
 * change in a draft, anchored to block ids and text, never to offsets, so it
 * survives edits elsewhere in the document.
 *
 * One patch is one object with an `op`:
 *
 *   insert-blocks  { afterBlockId: string|null, blocks: Block[] }
 *                  null inserts at the start of the document.
 *   replace-text   { blockId, deleteText, insert: Inline[], occurrence, before? }
 *                  Replaces the occurrence-th match (0-based) of deleteText
 *                  with `insert`. With deleteText "" it is a pure insertion:
 *                  `before` (up to 40 characters of the block's text just
 *                  before the insertion point, "" for the start of the block)
 *                  is matched instead, and the insertion goes right after the
 *                  occurrence-th match of it.
 *   format         { blockId, deleteText: "", text, occurrence, mark, add }
 *                  Adds (or removes) `mark` on the occurrence-th match of text.
 *   delete-blocks  { blockIds: string[] }
 *   move-block     { blockId, afterBlockId: string|null }
 *
 * TEXT MATCHING. Text is matched within the block's flattened inline text:
 * its `content` (or a toggle's `summary`), every text run's text in order,
 * with each other inline (a field, a break, a formula) counting as the one
 * character U+FFFC. A match may span several inline nodes. The occurrence-th
 * match is the occurrence-th position, scanning left to right one character
 * at a time, where the needle starts (so matches may overlap). Replacing or
 * formatting splits text runs at the match's edges, so the marks of the text
 * around it are kept; adjacent runs with the same marks are merged after.
 *
 * Blocks are found by id anywhere in the document: at the top level, in a
 * toggle's children and in either column of a columns block.
 *
 * Nothing in a patch may carry the marks or attributes the editor draws
 * suggestions with (`insertion` / `deletion` marks, a block `suggestion`
 * attr): they are rendering, never content.
 *
 * {@see self::apply()} returns null when the patch no longer applies (the
 * block or the anchor text has gone): the suggestion is stale.
 */
final class SuggestionPatch
{
    public const INSERT_BLOCKS = 'insert-blocks';
    public const REPLACE_TEXT  = 'replace-text';
    public const FORMAT        = 'format';
    public const DELETE_BLOCKS = 'delete-blocks';
    public const MOVE_BLOCK    = 'move-block';

    public const OPS = [self::INSERT_BLOCKS, self::REPLACE_TEXT, self::FORMAT, self::DELETE_BLOCKS, self::MOVE_BLOCK];

    /** The suggestion `kind` each op is. */
    public const KIND_OF = [
        self::INSERT_BLOCKS => 'block',
        self::DELETE_BLOCKS => 'block',
        self::MOVE_BLOCK    => 'block',
        self::REPLACE_TEXT  => 'text',
        self::FORMAT        => 'format',
    ];

    /** What a field, break or formula counts as in the flattened text. */
    public const ATOM = "\u{FFFC}";

    public const MAX_BEFORE = 40;

    /** The marks a format suggestion may add or remove. */
    public const FORMAT_MARKS = ['bold', 'italic', 'underline', 'strike', 'code', 'link', 'highlight'];

    /** The marks and attribute the editor draws suggestions with; never content. */
    public const SUGGESTION_MARKS = ['insertion', 'deletion'];

    private const INLINE_TYPES = ['text', 'field', 'break', 'math'];
    private const MAX_TEXT = 10000;
    private const MAX_BLOCKS = 200;
    private const MAX_INLINES = 500;
    private const MAX_IDS = 500;

    /**
     * Why a patch is malformed, or null when its shape is valid. Says nothing
     * about whether it applies to a draft; {@see self::apply()} answers that.
     */
    public static function problem(mixed $patch): ?string
    {
        if (!is_array($patch) || array_is_list($patch)) {
            return 'patch must be an object with an op';
        }
        $op = $patch['op'] ?? null;
        if (!is_string($op) || !in_array($op, self::OPS, true)) {
            return 'patch.op must be one of: ' . implode(', ', self::OPS);
        }

        return match ($op) {
            self::INSERT_BLOCKS => self::insertBlocksProblem($patch),
            self::REPLACE_TEXT  => self::replaceTextProblem($patch),
            self::FORMAT        => self::formatProblem($patch),
            self::DELETE_BLOCKS => self::deleteBlocksProblem($patch),
            default             => self::moveBlockProblem($patch),
        };
    }

    /**
     * The draft's blocks with the patch applied, or null when it no longer
     * applies (stale). The patch must be valid ({@see self::problem()}).
     *
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed>       $patch
     * @return list<array<string, mixed>>|null
     */
    public static function apply(array $blocks, array $patch): ?array
    {
        if (self::problem($patch) !== null) {
            return null;
        }

        return match ($patch['op']) {
            self::INSERT_BLOCKS => self::applyInsertBlocks($blocks, $patch),
            self::REPLACE_TEXT  => self::applyToText($blocks, (string) $patch['blockId'], static fn (array $inlines): ?array => self::replaceText($inlines, $patch)),
            self::FORMAT        => self::applyToText($blocks, (string) $patch['blockId'], static fn (array $inlines): ?array => self::format($inlines, $patch)),
            self::DELETE_BLOCKS => self::applyDeleteBlocks($blocks, $patch),
            default             => self::applyMoveBlock($blocks, $patch),
        };
    }

    /**
     * Whether a patch no longer applies to these blocks.
     *
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed>       $patch
     */
    public static function isStale(array $blocks, array $patch): bool
    {
        return self::apply($blocks, $patch) === null;
    }

    /**
     * Why these blocks carry what the editor draws suggestions with (an
     * `insertion` or `deletion` mark, or a block `suggestion` attr), or null
     * when they don't. A draft and a patch never hold either.
     *
     * @param array<int|string, mixed> $blocks
     */
    public static function suggestionArtifactProblem(array $blocks): ?string
    {
        if (self::blocksCarrySuggestionAttr($blocks)) {
            return 'blocks must not carry a suggestion attr; a suggestion is made through POST .../suggestions';
        }
        if (self::carriesSuggestionMarks($blocks)) {
            return 'blocks must not carry insertion or deletion marks; a suggestion is made through POST .../suggestions';
        }

        return null;
    }

    /**
     * Why an Inline[] is malformed (for a patch's `insert` and a comment's
     * body), or null.
     */
    public static function inlinesProblem(mixed $inlines, string $name, bool $allowEmpty = true): ?string
    {
        if (!is_array($inlines) || !array_is_list($inlines) || count($inlines) > self::MAX_INLINES) {
            return "{$name} must be a list of at most " . self::MAX_INLINES . ' inline nodes';
        }
        if (!$allowEmpty && $inlines === []) {
            return "{$name} must not be empty";
        }
        foreach ($inlines as $node) {
            if (!is_array($node) || !in_array($node['type'] ?? null, self::INLINE_TYPES, true)) {
                return "{$name} items must be inline nodes: text, field, break or math";
            }
            $type = $node['type'];
            if ($type === 'text' && (!is_string($node['text'] ?? null) || $node['text'] === '' || mb_strlen($node['text']) > self::MAX_TEXT)) {
                return "{$name}: a text node needs a non-empty text";
            }
            if ($type === 'field' && (!is_string($node['path'] ?? null) || $node['path'] === '')) {
                return "{$name}: a field node needs a path";
            }
            if ($type === 'math' && !is_string($node['tex'] ?? null)) {
                return "{$name}: a math node needs a tex";
            }
            if (array_key_exists('marks', $node)) {
                if (!is_array($node['marks']) || !array_is_list($node['marks'])) {
                    return "{$name}: marks must be a list";
                }
                foreach ($node['marks'] as $mark) {
                    if (!is_array($mark) || !is_string($mark['type'] ?? null)) {
                        return "{$name}: each mark needs a type";
                    }
                    if (in_array($mark['type'], self::SUGGESTION_MARKS, true)) {
                        return "{$name} must not carry insertion or deletion marks";
                    }
                }
            }
        }

        return null;
    }

    /**
     * The block's flattened inline text (see the class doc), or null when the
     * block has no inline text or isn't there.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public static function blockText(array $blocks, string $blockId): ?string
    {
        $block = self::findBlock($blocks, $blockId);
        $inlines = $block !== null ? self::inlinesOf($block) : null;

        return $inlines !== null ? self::flatten($inlines) : null;
    }

    /**
     * Whether a block with this id is anywhere in the document.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public static function hasBlock(array $blocks, string $blockId): bool
    {
        return isset(self::allIds($blocks)[$blockId]);
    }

    /** The plain text of an Inline[], atoms as U+FFFC. */
    public static function plainText(mixed $inlines): string
    {
        return is_array($inlines) ? self::flatten(array_values(array_filter($inlines, 'is_array'))) : '';
    }

    // ── shape ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $p */
    private static function insertBlocksProblem(array $p): ?string
    {
        if (!array_key_exists('afterBlockId', $p) || !self::nullableId($p['afterBlockId'])) {
            return 'patch.afterBlockId must be a block id, or null for the start';
        }
        $blocks = $p['blocks'] ?? null;
        if (!is_array($blocks) || !array_is_list($blocks) || $blocks === [] || count($blocks) > self::MAX_BLOCKS) {
            return 'patch.blocks must be a list of 1 to ' . self::MAX_BLOCKS . ' blocks';
        }
        foreach ($blocks as $block) {
            if (!is_array($block) || !self::isId($block['id'] ?? null) || !is_string($block['type'] ?? null) || $block['type'] === '') {
                return 'patch.blocks items must be blocks with a string id and a string type';
            }
        }
        $duplicate = false;
        self::allIds($blocks, $duplicate);
        if ($duplicate) {
            return 'patch.blocks ids must be unique';
        }

        return self::suggestionArtifactProblem($blocks);
    }

    /** @param array<string, mixed> $p */
    private static function replaceTextProblem(array $p): ?string
    {
        if (!self::isId($p['blockId'] ?? null)) {
            return 'patch.blockId must be a block id';
        }
        $delete = $p['deleteText'] ?? null;
        if (!is_string($delete) || mb_strlen($delete) > self::MAX_TEXT) {
            return 'patch.deleteText must be a string ("" for a pure insertion)';
        }
        $problem = self::inlinesProblem($p['insert'] ?? null, 'patch.insert');
        if ($problem !== null) {
            return $problem;
        }
        if (!self::isOccurrence($p['occurrence'] ?? null)) {
            return 'patch.occurrence must be a whole number from 0';
        }
        if ($delete === '') {
            if ($p['insert'] === []) {
                return 'patch.insert must not be empty when deleteText is ""';
            }
            $before = $p['before'] ?? null;
            if (!is_string($before) || mb_strlen($before) > self::MAX_BEFORE) {
                return 'patch.before must be the text just before the insertion point, at most ' . self::MAX_BEFORE . ' characters ("" for the start of the block)';
            }
        }

        return null;
    }

    /** @param array<string, mixed> $p */
    private static function formatProblem(array $p): ?string
    {
        if (!self::isId($p['blockId'] ?? null)) {
            return 'patch.blockId must be a block id';
        }
        if (array_key_exists('deleteText', $p) && $p['deleteText'] !== '') {
            return 'patch.deleteText must be "" for a format suggestion';
        }
        if (!is_string($p['text'] ?? null) || $p['text'] === '' || mb_strlen($p['text']) > self::MAX_TEXT) {
            return 'patch.text must be the non-empty text to format';
        }
        if (!self::isOccurrence($p['occurrence'] ?? null)) {
            return 'patch.occurrence must be a whole number from 0';
        }
        $mark = $p['mark'] ?? null;
        if (!is_array($mark) || !in_array($mark['type'] ?? null, self::FORMAT_MARKS, true)) {
            return 'patch.mark must be a mark whose type is one of: ' . implode(', ', self::FORMAT_MARKS);
        }
        if ($mark['type'] === 'link' && (!is_string($mark['href'] ?? null) || $mark['href'] === '' || strlen($mark['href']) > 2048)) {
            return 'patch.mark: a link needs an href';
        }
        if (!is_bool($p['add'] ?? null)) {
            return 'patch.add must be true (add the mark) or false (remove it)';
        }

        return null;
    }

    /** @param array<string, mixed> $p */
    private static function deleteBlocksProblem(array $p): ?string
    {
        $ids = $p['blockIds'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > self::MAX_IDS) {
            return 'patch.blockIds must be a list of 1 to ' . self::MAX_IDS . ' block ids';
        }
        foreach ($ids as $id) {
            if (!self::isId($id)) {
                return 'patch.blockIds items must be block ids';
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            return 'patch.blockIds must be unique';
        }

        return null;
    }

    /** @param array<string, mixed> $p */
    private static function moveBlockProblem(array $p): ?string
    {
        if (!self::isId($p['blockId'] ?? null)) {
            return 'patch.blockId must be a block id';
        }
        if (!array_key_exists('afterBlockId', $p) || !self::nullableId($p['afterBlockId'])) {
            return 'patch.afterBlockId must be a block id, or null for the start';
        }
        if ($p['afterBlockId'] === $p['blockId']) {
            return 'patch.afterBlockId must not be the block being moved';
        }

        return null;
    }

    private static function isId(mixed $v): bool
    {
        return is_string($v) && $v !== '' && strlen($v) <= 64;
    }

    private static function nullableId(mixed $v): bool
    {
        return $v === null || self::isId($v);
    }

    private static function isOccurrence(mixed $v): bool
    {
        return is_int($v) && $v >= 0 && $v <= 100000;
    }

    // ── block ops ────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed>       $p
     * @return list<array<string, mixed>>|null
     */
    private static function applyInsertBlocks(array $blocks, array $p): ?array
    {
        /** @var list<array<string, mixed>> $new */
        $new = $p['blocks'];
        $existing = self::allIds($blocks);
        foreach (array_keys(self::allIds($new)) as $id) {
            if (isset($existing[$id])) {
                return null; // an id the draft now uses
            }
        }

        return self::insertAfter($blocks, $p['afterBlockId'], $new);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed>       $p
     * @return list<array<string, mixed>>|null
     */
    private static function applyDeleteBlocks(array $blocks, array $p): ?array
    {
        $existing = self::allIds($blocks);
        $gone = [];
        foreach ($p['blockIds'] as $id) {
            if (!isset($existing[$id])) {
                return null;
            }
            $gone[$id] = true;
        }

        return self::mapContainers($blocks, static fn (array $list): array => array_values(array_filter(
            $list,
            static fn (array $b): bool => !isset($gone[$b['id'] ?? ''])
        )));
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed>       $p
     * @return list<array<string, mixed>>|null
     */
    private static function applyMoveBlock(array $blocks, array $p): ?array
    {
        $id = (string) $p['blockId'];
        $after = $p['afterBlockId'];
        $moved = self::findBlock($blocks, $id);
        if ($moved === null) {
            return null;
        }
        if ($after !== null) {
            if (!self::hasBlock($blocks, $after) || isset(self::allIds([$moved])[$after])) {
                return null; // gone, or inside the block being moved
            }
        }
        $without = self::mapContainers($blocks, static fn (array $list): array => array_values(array_filter(
            $list,
            static fn (array $b): bool => ($b['id'] ?? null) !== $id
        )));

        return self::insertAfter($without, $after, [$moved]);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<array<string, mixed>> $new
     * @return list<array<string, mixed>>|null
     */
    private static function insertAfter(array $blocks, ?string $afterId, array $new): ?array
    {
        if ($afterId === null) {
            return array_merge($new, $blocks);
        }
        $done = false;
        $out = self::mapContainers($blocks, static function (array $list) use ($afterId, $new, &$done): array {
            if ($done) {
                return $list;
            }
            foreach ($list as $i => $b) {
                if (($b['id'] ?? null) === $afterId) {
                    $done = true;
                    array_splice($list, $i + 1, 0, $new);

                    return array_values($list);
                }
            }

            return $list;
        });

        return $done ? $out : null;
    }

    /**
     * Applies $fn to every list of blocks in the document, outermost first,
     * then descends into what it returned.
     *
     * @param list<array<string, mixed>>                                  $blocks
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $fn
     * @return list<array<string, mixed>>
     */
    private static function mapContainers(array $blocks, callable $fn): array
    {
        $blocks = $fn($blocks);
        foreach ($blocks as $i => $block) {
            if (is_array($block['children'] ?? null) && array_is_list($block['children'])) {
                $blocks[$i]['children'] = self::mapContainers(self::blockList($block['children']), $fn);
            }
            if (($block['type'] ?? null) === 'columns' && is_array($block['columns'] ?? null)) {
                foreach ($block['columns'] as $c => $column) {
                    if (is_array($column) && array_is_list($column)) {
                        $blocks[$i]['columns'][$c] = self::mapContainers(self::blockList($column), $fn);
                    }
                }
            }
        }

        return $blocks;
    }

    /**
     * @param array<int|string, mixed> $list
     * @return list<array<string, mixed>>
     */
    private static function blockList(array $list): array
    {
        return array_values(array_filter($list, 'is_array'));
    }

    /**
     * Every block id in the document, nested ones included.
     *
     * @param array<int|string, mixed> $blocks
     * @return array<string, true>
     */
    private static function allIds(array $blocks, bool &$duplicate = false): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (is_string($block['id'] ?? null)) {
                if (isset($ids[$block['id']])) {
                    $duplicate = true;
                }
                $ids[$block['id']] = true;
            }
            foreach (self::childLists($block) as $list) {
                foreach (self::allIds($list, $duplicate) as $id => $_) {
                    if (isset($ids[$id])) {
                        $duplicate = true;
                    }
                    $ids[$id] = true;
                }
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $block
     * @return list<array<int|string, mixed>>
     */
    private static function childLists(array $block): array
    {
        $lists = [];
        if (is_array($block['children'] ?? null)) {
            $lists[] = $block['children'];
        }
        if (($block['type'] ?? null) === 'columns' && is_array($block['columns'] ?? null)) {
            foreach ($block['columns'] as $column) {
                if (is_array($column)) {
                    $lists[] = $column;
                }
            }
        }

        return $lists;
    }

    /**
     * @param array<int|string, mixed> $blocks
     * @return array<string, mixed>|null
     */
    private static function findBlock(array $blocks, string $id): ?array
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['id'] ?? null) === $id) {
                return $block;
            }
            foreach (self::childLists($block) as $list) {
                $found = self::findBlock($list, $id);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    // ── text ops ─────────────────────────────────────────────────────────

    /**
     * Runs $fn on the inline text of the block with this id.
     *
     * @param list<array<string, mixed>>                                          $blocks
     * @param callable(list<array<string, mixed>>): (list<array<string, mixed>>|null) $fn
     * @return list<array<string, mixed>>|null
     */
    private static function applyToText(array $blocks, string $blockId, callable $fn): ?array
    {
        $found = false;
        $stale = false;
        $out = self::mapContainers($blocks, static function (array $list) use ($blockId, $fn, &$found, &$stale): array {
            foreach ($list as $i => $block) {
                if (($block['id'] ?? null) !== $blockId || $found) {
                    continue;
                }
                $found = true;
                $key = self::inlineKey($block);
                $changed = $key !== null ? $fn(self::blockList($block[$key])) : null;
                if ($changed === null) {
                    $stale = true;
                } else {
                    $list[$i][$key] = $changed;
                }
            }

            return $list;
        });

        return $found && !$stale ? $out : null;
    }

    /** @param array<string, mixed> $block */
    private static function inlineKey(array $block): ?string
    {
        if (is_array($block['content'] ?? null) && array_is_list($block['content'])) {
            return 'content';
        }
        if (($block['type'] ?? null) === 'toggle' && is_array($block['summary'] ?? null) && array_is_list($block['summary'])) {
            return 'summary';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $block
     * @return list<array<string, mixed>>|null
     */
    private static function inlinesOf(array $block): ?array
    {
        $key = self::inlineKey($block);

        return $key !== null ? self::blockList($block[$key]) : null;
    }

    /**
     * @param list<array<string, mixed>> $inlines
     * @param array<string, mixed>       $p
     * @return list<array<string, mixed>>|null
     */
    private static function replaceText(array $inlines, array $p): ?array
    {
        $flat = self::flatten($inlines);
        $delete = (string) $p['deleteText'];
        $occurrence = (int) $p['occurrence'];
        if ($delete !== '') {
            $start = self::nth($flat, $delete, $occurrence);
            if ($start === null) {
                return null;
            }
            $end = $start + mb_strlen($delete);
        } else {
            $before = (string) $p['before'];
            if ($before === '') {
                if ($occurrence !== 0) {
                    return null;
                }
                $start = 0;
            } else {
                $at = self::nth($flat, $before, $occurrence);
                if ($at === null) {
                    return null;
                }
                $start = $at + mb_strlen($before);
            }
            $end = $start;
        }

        [$head, $rest] = self::splitAt($inlines, $start);
        [, $tail] = self::splitAt($rest, $end - $start);
        /** @var list<array<string, mixed>> $insert */
        $insert = $p['insert'];

        return self::normalize(array_merge($head, $insert, $tail));
    }

    /**
     * @param list<array<string, mixed>> $inlines
     * @param array<string, mixed>       $p
     * @return list<array<string, mixed>>|null
     */
    private static function format(array $inlines, array $p): ?array
    {
        $text = (string) $p['text'];
        $start = self::nth(self::flatten($inlines), $text, (int) $p['occurrence']);
        if ($start === null) {
            return null;
        }
        [$head, $rest] = self::splitAt($inlines, $start);
        [$middle, $tail] = self::splitAt($rest, mb_strlen($text));
        /** @var array<string, mixed> $mark */
        $mark = $p['mark'];
        $add = $p['add'] === true;
        foreach ($middle as $i => $node) {
            if (!in_array($node['type'] ?? null, ['text', 'field'], true)) {
                continue; // a break or a formula takes no marks
            }
            $marks = array_values(array_filter(
                is_array($node['marks'] ?? null) ? $node['marks'] : [],
                static fn (mixed $m): bool => is_array($m) && ($m['type'] ?? null) !== $mark['type']
            ));
            if ($add) {
                $marks[] = $mark;
            }
            if ($marks === []) {
                unset($middle[$i]['marks']);
            } else {
                $middle[$i]['marks'] = $marks;
            }
        }

        return self::normalize(array_merge($head, array_values($middle), $tail));
    }

    /** @param list<array<string, mixed>> $inlines */
    private static function flatten(array $inlines): string
    {
        $out = '';
        foreach ($inlines as $node) {
            $out .= ($node['type'] ?? null) === 'text' ? (string) ($node['text'] ?? '') : self::ATOM;
        }

        return $out;
    }

    /** The character offset of the n-th (0-based, overlapping) match, or null. */
    private static function nth(string $haystack, string $needle, int $n): ?int
    {
        if ($needle === '') {
            return null;
        }
        $offset = 0;
        for ($i = 0; ; $i++) {
            $pos = mb_strpos($haystack, $needle, $offset);
            if ($pos === false) {
                return null;
            }
            if ($i === $n) {
                return $pos;
            }
            $offset = $pos + 1;
        }
    }

    /**
     * Splits an Inline[] at a character offset, cutting a text run in two
     * (both halves keep its marks) when the offset falls inside it.
     *
     * @param list<array<string, mixed>> $inlines
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private static function splitAt(array $inlines, int $offset): array
    {
        $head = [];
        $tail = [];
        $pos = 0;
        foreach ($inlines as $node) {
            $isText = ($node['type'] ?? null) === 'text';
            $len = $isText ? mb_strlen((string) ($node['text'] ?? '')) : 1;
            if ($pos + $len <= $offset) {
                $head[] = $node;
            } elseif ($pos >= $offset) {
                $tail[] = $node;
            } else {
                // Only a text run is longer than one character, so only it is cut.
                $cut = $offset - $pos;
                $text = (string) $node['text'];
                $left = $node;
                $left['text'] = mb_substr($text, 0, $cut);
                $right = $node;
                $right['text'] = mb_substr($text, $cut);
                $head[] = $left;
                $tail[] = $right;
            }
            $pos += $len;
        }

        return [$head, $tail];
    }

    /**
     * Drops empty text runs and merges adjacent runs with the same marks.
     *
     * @param list<array<string, mixed>> $inlines
     * @return list<array<string, mixed>>
     */
    private static function normalize(array $inlines): array
    {
        $out = [];
        foreach ($inlines as $node) {
            if (($node['type'] ?? null) === 'text') {
                if (($node['text'] ?? '') === '') {
                    continue;
                }
                $last = count($out) - 1;
                if ($last >= 0 && ($out[$last]['type'] ?? null) === 'text'
                    && self::marksKey($out[$last]) === self::marksKey($node)) {
                    $out[$last]['text'] = (string) $out[$last]['text'] . (string) $node['text'];
                    continue;
                }
            }
            $out[] = $node;
        }

        return $out;
    }

    /** @param array<string, mixed> $node */
    private static function marksKey(array $node): string
    {
        $marks = is_array($node['marks'] ?? null) ? $node['marks'] : [];
        $keys = array_map(static function (mixed $m): string {
            if (is_array($m)) {
                ksort($m);
            }

            return (string) json_encode($m);
        }, $marks);
        sort($keys);

        return implode('|', $keys);
    }

    // ── suggestion artifacts ─────────────────────────────────────────────

    /** @param array<int|string, mixed> $blocks */
    private static function blocksCarrySuggestionAttr(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (array_key_exists('suggestion', $block)) {
                return true;
            }
            foreach (self::childLists($block) as $list) {
                if (self::blocksCarrySuggestionAttr($list)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Any `marks` list, at any depth, holding an insertion or deletion mark. */
    private static function carriesSuggestionMarks(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $child) {
            if ($key === 'marks' && is_array($child)) {
                foreach ($child as $mark) {
                    if (is_array($mark) && in_array($mark['type'] ?? null, self::SUGGESTION_MARKS, true)) {
                        return true;
                    }
                }
            }
            if (is_array($child) && self::carriesSuggestionMarks($child)) {
                return true;
            }
        }

        return false;
    }
}
