<?php

declare(strict_types=1);

namespace Tests\Core\Document\Suggestion;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Suggestion\SuggestionPatch;

/**
 * {@see SuggestionPatch} (editor M5; IMPLEMENTATION-NOTES 3.3): every patch
 * shape, text matched across inline nodes by occurrence, marks of untouched
 * text kept, insertions anchored by `before`, and stale detection.
 */
final class SuggestionPatchTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function doc(): array
    {
        return [
            ['id' => 'h1', 'type' => 'heading', 'level' => 1, 'content' => [['type' => 'text', 'text' => 'Terms']]],
            ['id' => 'p1', 'type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Pay within '],
                ['type' => 'text', 'text' => '30 days', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' of the invoice, '],
                ['type' => 'field', 'path' => 'customer.name', 'label' => 'Customer name'],
                ['type' => 'text', 'text' => '. Late after 30 days.'],
            ]],
            ['id' => 't1', 'type' => 'toggle', 'open' => true, 'summary' => [['type' => 'text', 'text' => 'More']], 'children' => [
                ['id' => 'c1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Nested text']]],
            ]],
            ['id' => 'd1', 'type' => 'divider'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<string>
     */
    private static function ids(array $blocks): array
    {
        return array_map(static fn (array $b): string => $b['id'], $blocks);
    }

    // ── replace-text ─────────────────────────────────────────────────────

    public function testReplaceTextReplacesTheMatchAndKeepsTheMarksAroundIt(): void
    {
        $out = SuggestionPatch::apply(self::doc(), [
            'op' => 'replace-text', 'blockId' => 'p1', 'deleteText' => '30', 'occurrence' => 0,
            'insert' => [['type' => 'text', 'text' => '14', 'marks' => [['type' => 'bold']]]],
        ]);

        self::assertNotNull($out);
        self::assertSame([
            ['type' => 'text', 'text' => 'Pay within '],
            ['type' => 'text', 'text' => '14 days', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' of the invoice, '],
            ['type' => 'field', 'path' => 'customer.name', 'label' => 'Customer name'],
            ['type' => 'text', 'text' => '. Late after 30 days.'],
        ], $out[1]['content'], 'the bold run is split, refilled and merged back');
    }

    public function testOccurrenceChoosesWhichMatch(): void
    {
        $out = SuggestionPatch::apply(self::doc(), [
            'op' => 'replace-text', 'blockId' => 'p1', 'deleteText' => '30 days', 'occurrence' => 1,
            'insert' => [['type' => 'text', 'text' => '14 days']],
        ]);

        self::assertNotNull($out);
        self::assertSame('30 days', $out[1]['content'][1]['text'], 'the first match is untouched');
        self::assertSame('. Late after 14 days.', $out[1]['content'][4]['text']);
    }

    public function testAMatchSpansInlineNodesAndAFieldCountsAsOneCharacter(): void
    {
        $atom = SuggestionPatch::ATOM;
        $out = SuggestionPatch::apply(self::doc(), [
            'op' => 'replace-text', 'blockId' => 'p1', 'deleteText' => 'days of the invoice, ' . $atom . '.', 'occurrence' => 0,
            'insert' => [['type' => 'text', 'text' => 'days.']],
        ]);

        self::assertNotNull($out);
        self::assertSame([
            ['type' => 'text', 'text' => 'Pay within '],
            ['type' => 'text', 'text' => '30 ', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => 'days. Late after 30 days.'],
        ], $out[1]['content'], 'the field went with the text, and plain runs merged');
    }

    public function testAPureDeletion(): void
    {
        $out = SuggestionPatch::apply(self::doc(), [
            'op' => 'replace-text', 'blockId' => 'h1', 'deleteText' => 'rms', 'occurrence' => 0, 'insert' => [],
        ]);

        self::assertSame([['type' => 'text', 'text' => 'Te']], $out[0]['content'] ?? null);
    }

    public function testAnInsertionGoesRightAfterItsBeforeText(): void
    {
        $out = SuggestionPatch::apply(self::doc(), [
            'op' => 'replace-text', 'blockId' => 'p1', 'deleteText' => '', 'before' => 'after 30', 'occurrence' => 0,
            'insert' => [['type' => 'text', 'text' => ' working', 'marks' => [['type' => 'italic']]]],
        ]);

        self::assertNotNull($out);
        self::assertSame([
            ['type' => 'text', 'text' => '. Late after 30'],
            ['type' => 'text', 'text' => ' working', 'marks' => [['type' => 'italic']]],
            ['type' => 'text', 'text' => ' days.'],
        ], array_slice($out[1]['content'], 4));
    }

    public function testAnInsertionWithAnEmptyBeforeGoesAtTheStart(): void
    {
        $out = SuggestionPatch::apply(self::doc(), [
            'op' => 'replace-text', 'blockId' => 'h1', 'deleteText' => '', 'before' => '', 'occurrence' => 0,
            'insert' => [['type' => 'text', 'text' => 'Payment ']],
        ]);

        self::assertSame([['type' => 'text', 'text' => 'Payment Terms']], $out[0]['content'] ?? null);
    }

    public function testAnInsertionCountsOccurrencesOfItsBeforeText(): void
    {
        $out = SuggestionPatch::apply(self::doc(), [
            'op' => 'replace-text', 'blockId' => 'p1', 'deleteText' => '', 'before' => 'days', 'occurrence' => 1,
            'insert' => [['type' => 'text', 'text' => '!']],
        ]);

        self::assertSame('. Late after 30 days!.', $out[1]['content'][4]['text'] ?? null);
    }

    public function testInlineContentWithMarksIsInsertedAsIs(): void
    {
        $insert = [
            ['type' => 'text', 'text' => 'see ', 'marks' => [['type' => 'italic']]],
            ['type' => 'text', 'text' => 'terms', 'marks' => [['type' => 'link', 'href' => 'https://example.com/terms']]],
            ['type' => 'break'],
        ];
        $out = SuggestionPatch::apply(self::doc(), [
            'op' => 'replace-text', 'blockId' => 'h1', 'deleteText' => 'Terms', 'occurrence' => 0, 'insert' => $insert,
        ]);

        self::assertSame($insert, $out[0]['content'] ?? null);
    }

    public function testTextInANestedBlockAndAToggleSummary(): void
    {
        $nested = SuggestionPatch::apply(self::doc(), [
            'op' => 'replace-text', 'blockId' => 'c1', 'deleteText' => 'Nested', 'occurrence' => 0, 'insert' => [['type' => 'text', 'text' => 'Inner']],
        ]);
        self::assertSame('Inner text', $nested[2]['children'][0]['content'][0]['text'] ?? null);

        $summary = SuggestionPatch::apply(self::doc(), [
            'op' => 'replace-text', 'blockId' => 't1', 'deleteText' => 'More', 'occurrence' => 0, 'insert' => [['type' => 'text', 'text' => 'Less']],
        ]);
        self::assertSame('Less', $summary[2]['summary'][0]['text'] ?? null);
    }

    // ── format ───────────────────────────────────────────────────────────

    public function testFormatAddsAMarkToTheMatchOnly(): void
    {
        $out = SuggestionPatch::apply(self::doc(), [
            'op' => 'format', 'blockId' => 'p1', 'deleteText' => '', 'text' => 'within 30', 'occurrence' => 0,
            'mark' => ['type' => 'highlight'], 'add' => true,
        ]);

        self::assertNotNull($out);
        self::assertSame([
            ['type' => 'text', 'text' => 'Pay '],
            ['type' => 'text', 'text' => 'within ', 'marks' => [['type' => 'highlight']]],
            ['type' => 'text', 'text' => '30', 'marks' => [['type' => 'bold'], ['type' => 'highlight']]],
            ['type' => 'text', 'text' => ' days', 'marks' => [['type' => 'bold']]],
        ], array_slice($out[1]['content'], 0, 4));
    }

    public function testFormatRemovesAMarkAndMarksAField(): void
    {
        $atom = SuggestionPatch::ATOM;
        $removed = SuggestionPatch::apply(self::doc(), [
            'op' => 'format', 'blockId' => 'p1', 'text' => '30 days', 'occurrence' => 0, 'mark' => ['type' => 'bold'], 'add' => false,
        ]);
        self::assertSame('Pay within 30 days of the invoice, ', $removed[1]['content'][0]['text'] ?? null, 'unmarked runs merge');

        $field = SuggestionPatch::apply(self::doc(), [
            'op' => 'format', 'blockId' => 'p1', 'text' => $atom, 'occurrence' => 0, 'mark' => ['type' => 'italic'], 'add' => true,
        ]);
        self::assertSame([['type' => 'italic']], $field[1]['content'][3]['marks'] ?? null);
    }

    public function testFormatReplacesALinkWithANewHref(): void
    {
        $doc = [['id' => 'p', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'site', 'marks' => [['type' => 'link', 'href' => 'https://a.example']]]]]];
        $out = SuggestionPatch::apply($doc, [
            'op' => 'format', 'blockId' => 'p', 'text' => 'site', 'occurrence' => 0, 'mark' => ['type' => 'link', 'href' => 'https://b.example'], 'add' => true,
        ]);

        self::assertSame([['type' => 'link', 'href' => 'https://b.example']], $out[0]['content'][0]['marks'] ?? null);
    }

    // ── block ops ────────────────────────────────────────────────────────

    public function testInsertBlocksAfterABlockAndAtTheStart(): void
    {
        $new = [['id' => 'n1', 'type' => 'paragraph', 'content' => []], ['id' => 'n2', 'type' => 'divider']];

        $after = SuggestionPatch::apply(self::doc(), ['op' => 'insert-blocks', 'afterBlockId' => 'p1', 'blocks' => $new]);
        self::assertSame(['h1', 'p1', 'n1', 'n2', 't1', 'd1'], self::ids($after ?? []));

        $start = SuggestionPatch::apply(self::doc(), ['op' => 'insert-blocks', 'afterBlockId' => null, 'blocks' => $new]);
        self::assertSame(['n1', 'n2', 'h1', 'p1', 't1', 'd1'], self::ids($start ?? []));

        $nested = SuggestionPatch::apply(self::doc(), ['op' => 'insert-blocks', 'afterBlockId' => 'c1', 'blocks' => $new]);
        self::assertSame(['c1', 'n1', 'n2'], self::ids($nested[2]['children'] ?? []), 'after a nested block, in its container');
    }

    public function testDeleteBlocks(): void
    {
        $out = SuggestionPatch::apply(self::doc(), ['op' => 'delete-blocks', 'blockIds' => ['h1', 'c1']]);

        self::assertSame(['p1', 't1', 'd1'], self::ids($out ?? []));
        self::assertSame([], $out[1]['children'] ?? null);
    }

    public function testMoveBlock(): void
    {
        $down = SuggestionPatch::apply(self::doc(), ['op' => 'move-block', 'blockId' => 'h1', 'afterBlockId' => 'd1']);
        self::assertSame(['p1', 't1', 'd1', 'h1'], self::ids($down ?? []));

        $top = SuggestionPatch::apply(self::doc(), ['op' => 'move-block', 'blockId' => 'c1', 'afterBlockId' => null]);
        self::assertSame(['c1', 'h1', 'p1', 't1', 'd1'], self::ids($top ?? []), 'out of the toggle to the start');

        self::assertNull(
            SuggestionPatch::apply(self::doc(), ['op' => 'move-block', 'blockId' => 't1', 'afterBlockId' => 'c1']),
            'a block can\'t move inside itself',
        );
    }

    // ── stale ────────────────────────────────────────────────────────────

    public function testStaleWhenTheTextOrBlockHasGone(): void
    {
        $doc = self::doc();
        self::assertTrue(SuggestionPatch::isStale($doc, ['op' => 'replace-text', 'blockId' => 'p1', 'deleteText' => '60 days', 'occurrence' => 0, 'insert' => []]));
        self::assertTrue(SuggestionPatch::isStale($doc, ['op' => 'replace-text', 'blockId' => 'p1', 'deleteText' => '30 days', 'occurrence' => 2, 'insert' => []]), 'only two matches');
        self::assertTrue(SuggestionPatch::isStale($doc, ['op' => 'replace-text', 'blockId' => 'gone', 'deleteText' => 'x', 'occurrence' => 0, 'insert' => []]));
        self::assertTrue(SuggestionPatch::isStale($doc, ['op' => 'replace-text', 'blockId' => 'p1', 'deleteText' => '', 'before' => 'no such text', 'occurrence' => 0, 'insert' => [['type' => 'text', 'text' => 'x']]]));
        self::assertTrue(SuggestionPatch::isStale($doc, ['op' => 'replace-text', 'blockId' => 'd1', 'deleteText' => 'x', 'occurrence' => 0, 'insert' => []]), 'a divider has no text');
        self::assertTrue(SuggestionPatch::isStale($doc, ['op' => 'format', 'blockId' => 'h1', 'text' => 'Conditions', 'occurrence' => 0, 'mark' => ['type' => 'bold'], 'add' => true]));
        self::assertTrue(SuggestionPatch::isStale($doc, ['op' => 'insert-blocks', 'afterBlockId' => 'gone', 'blocks' => [['id' => 'n', 'type' => 'divider']]]));
        self::assertTrue(SuggestionPatch::isStale($doc, ['op' => 'insert-blocks', 'afterBlockId' => null, 'blocks' => [['id' => 'p1', 'type' => 'divider']]]), 'an id the draft uses');
        self::assertTrue(SuggestionPatch::isStale($doc, ['op' => 'delete-blocks', 'blockIds' => ['h1', 'gone']]));
        self::assertTrue(SuggestionPatch::isStale($doc, ['op' => 'move-block', 'blockId' => 'gone', 'afterBlockId' => null]));
        self::assertTrue(SuggestionPatch::isStale($doc, ['op' => 'move-block', 'blockId' => 'h1', 'afterBlockId' => 'gone']));

        self::assertFalse(SuggestionPatch::isStale($doc, ['op' => 'replace-text', 'blockId' => 'p1', 'deleteText' => '30 days', 'occurrence' => 1, 'insert' => []]));
    }

    public function testATextSuggestionSurvivesEditsElsewhereInTheBlock(): void
    {
        $patch = ['op' => 'replace-text', 'blockId' => 'h1', 'deleteText' => 'Terms', 'occurrence' => 0, 'insert' => [['type' => 'text', 'text' => 'Conditions']]];
        $edited = self::doc();
        $edited[0]['content'] = [['type' => 'text', 'text' => 'Our '], ['type' => 'text', 'text' => 'Terms', 'marks' => [['type' => 'underline']]], ['type' => 'text', 'text' => ' of sale']];

        $out = SuggestionPatch::apply($edited, $patch);
        self::assertSame([['type' => 'text', 'text' => 'Our Conditions of sale']], $out[0]['content'] ?? null);
    }

    // ── shape ────────────────────────────────────────────────────────────

    public function testMalformedPatchesAreNamed(): void
    {
        self::assertNull(SuggestionPatch::problem(['op' => 'delete-blocks', 'blockIds' => ['a']]));

        $bad = [
            'not an object' => [['op' => 'x']],
            'unknown op' => ['op' => 'rewrite'],
            'insert without before' => ['op' => 'replace-text', 'blockId' => 'p', 'deleteText' => '', 'occurrence' => 0, 'insert' => [['type' => 'text', 'text' => 'x']]],
            'empty insertion' => ['op' => 'replace-text', 'blockId' => 'p', 'deleteText' => '', 'before' => '', 'occurrence' => 0, 'insert' => []],
            'before too long' => ['op' => 'replace-text', 'blockId' => 'p', 'deleteText' => '', 'before' => str_repeat('a', 41), 'occurrence' => 0, 'insert' => [['type' => 'text', 'text' => 'x']]],
            'negative occurrence' => ['op' => 'replace-text', 'blockId' => 'p', 'deleteText' => 'a', 'occurrence' => -1, 'insert' => []],
            'unknown inline' => ['op' => 'replace-text', 'blockId' => 'p', 'deleteText' => 'a', 'occurrence' => 0, 'insert' => [['type' => 'image']]],
            'insertion mark in insert' => ['op' => 'replace-text', 'blockId' => 'p', 'deleteText' => 'a', 'occurrence' => 0, 'insert' => [['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'insertion', 'suggestionId' => '1']]]]],
            'format with deleteText' => ['op' => 'format', 'blockId' => 'p', 'deleteText' => 'a', 'text' => 'a', 'occurrence' => 0, 'mark' => ['type' => 'bold'], 'add' => true],
            'format a comment mark' => ['op' => 'format', 'blockId' => 'p', 'text' => 'a', 'occurrence' => 0, 'mark' => ['type' => 'comment', 'threadId' => '1'], 'add' => true],
            'link without href' => ['op' => 'format', 'blockId' => 'p', 'text' => 'a', 'occurrence' => 0, 'mark' => ['type' => 'link'], 'add' => true],
            'add not bool' => ['op' => 'format', 'blockId' => 'p', 'text' => 'a', 'occurrence' => 0, 'mark' => ['type' => 'bold'], 'add' => 1],
            'no blocks' => ['op' => 'insert-blocks', 'afterBlockId' => null, 'blocks' => []],
            'missing afterBlockId' => ['op' => 'insert-blocks', 'blocks' => [['id' => 'a', 'type' => 'divider']]],
            'duplicate block ids' => ['op' => 'insert-blocks', 'afterBlockId' => null, 'blocks' => [['id' => 'a', 'type' => 'divider'], ['id' => 'a', 'type' => 'divider']]],
            'suggestion attr' => ['op' => 'insert-blocks', 'afterBlockId' => null, 'blocks' => [['id' => 'a', 'type' => 'divider', 'suggestion' => ['id' => '1', 'action' => 'insert']]]],
            'duplicate delete ids' => ['op' => 'delete-blocks', 'blockIds' => ['a', 'a']],
            'move after itself' => ['op' => 'move-block', 'blockId' => 'a', 'afterBlockId' => 'a'],
        ];
        foreach ($bad as $why => $patch) {
            self::assertNotNull(SuggestionPatch::problem($patch), $why);
            /** @var array<string, mixed> $patch */
            self::assertNull(SuggestionPatch::apply(self::doc(), $patch), $why . ' never applies');
        }
    }

    public function testDraftsCarryingSuggestionMarksOrAttrsAreNamed(): void
    {
        self::assertNull(SuggestionPatch::suggestionArtifactProblem(self::doc()));
        self::assertNotNull(SuggestionPatch::suggestionArtifactProblem([
            ['id' => 'p', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'deletion', 'suggestionId' => '4']]]]],
        ]));
        self::assertNotNull(SuggestionPatch::suggestionArtifactProblem([
            ['id' => 't', 'type' => 'table', 'headerRow' => false, 'columns' => [], 'rows' => [[[['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'insertion', 'suggestionId' => '4']]]]]]],
        ]), 'in a table cell too');
        self::assertNotNull(SuggestionPatch::suggestionArtifactProblem([
            ['id' => 'c', 'type' => 'columns', 'columns' => [[['id' => 'x', 'type' => 'divider', 'suggestion' => ['id' => '1', 'action' => 'delete']]], []]],
        ]), 'in a column too');
    }
}
