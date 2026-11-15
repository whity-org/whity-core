<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\Routing;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Print\PrintLabels;
use Whity\Core\Document\Print\WhityDocumentPrinter;
use Whity\Core\Document\Routing\RouteStepExclusions;
use Whity\Core\Document\Routing\RouteStepKind;

/**
 * WHIT-1018: which people questions a step leaves out, and a table row that
 * prints once per approval.
 */
final class RouteStepExclusionsTest extends TestCase
{
    public function testQuestionKeysAreKeptOnceInOrder(): void
    {
        self::assertSame(['supervisor', 'co_supervisor'], RouteStepExclusions::parse(['supervisor', 'co_supervisor', 'supervisor']));
        self::assertSame([], RouteStepExclusions::parse(null));
        self::assertNull(RouteStepExclusions::encode([]));
        self::assertSame(['supervisor'], RouteStepExclusions::decode(RouteStepExclusions::encode(['supervisor'])));
        self::assertSame([], RouteStepExclusions::decode('not json'), 'a stored value that cannot be read leaves nobody out');
    }

    public function testABadListIsRefused(): void
    {
        foreach (['supervisor', ['Supervisor'], ['super visor'], [5], ['a' => 'supervisor'], array_map(static fn (int $i): string => 'q' . $i, range(1, 11))] as $raw) {
            try {
                RouteStepExclusions::parse($raw);
                self::fail('This must be refused: ' . json_encode($raw));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheFinalDocumentStageHasNobodyToLeaveOut(): void
    {
        RouteStepExclusions::assertAllowed(['supervisor'], null);
        RouteStepExclusions::assertAllowed([], RouteStepKind::FINALISE);
        $this->expectException(InvalidArgumentException::class);
        RouteStepExclusions::assertAllowed(['supervisor'], RouteStepKind::FINALISE);
    }

    public function testATableRowWithAListFieldPrintsOncePerItemAndNotAtAllForNone(): void
    {
        $field = static fn (string $path): array => [['type' => 'field', 'path' => $path]];
        $text = static fn (string $t): array => [['type' => 'text', 'text' => $t]];
        $table = [[
            'type' => 'table',
            'headerRow' => true,
            'rows' => [
                [$text('Member'), $text('Date')],
                [$field('route.approvals.*.name'), $field('route.approvals.*.date')],
                [$text('Signed by the secretary'), $field('route.step.3.approvals.*.name')],
            ],
        ]];

        $printed = $this->table($table, [
            'route.approvals.count' => '2',
            'route.approvals.1.name' => 'Awss Haddad',
            'route.approvals.1.date' => '2026-11-14',
            'route.approvals.2.name' => 'Ahmad Khalil',
            'route.approvals.2.date' => '2026-11-15',
        ]);

        self::assertSame(['Member', 'Date'], $printed['columns']);
        self::assertSame([['Awss Haddad', '2026-11-14'], ['Ahmad Khalil', '2026-11-15']], $printed['rows'], 'the stage-3 row has no approvals, so it is left out');

        $plain = $this->table([[
            'type' => 'table',
            'rows' => [[$text('Total'), $field('route.approvals.count')]],
        ]], ['route.approvals.count' => '2']);
        self::assertSame([['Total', '2']], $plain['rows'], 'a row with no list field prints as it is');
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, string|null> $values
     * @return array<string, mixed>
     */
    private function table(array $blocks, array $values): array
    {
        $printer = new WhityDocumentPrinter($values, [], static fn (): ?array => null, static fn (): ?array => null, static fn (): ?string => null, new PrintLabels());
        $payload = $printer->payload(['title' => 'Certificate', 'lang' => 'en', 'page' => null, 'blocks' => $blocks], null, null, null);
        foreach ($payload['content'] as $block) {
            if (($block['type'] ?? null) === 'table') {
                return $block;
            }
        }
        self::fail('No table was printed.');
    }
}
