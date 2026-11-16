<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mention;

use PHPUnit\Framework\TestCase;
use Whity\Core\Mention\Mentions;

/** One mention shape for chat and document comments (merge map N4, WHIT-1120). */
final class MentionsTest extends TestCase
{
    public function testBareIdsReadAsPeopleAndTheChatShapeIsKept(): void
    {
        self::assertSame(
            [['type' => 'person', 'id' => 7], ['type' => 'agent', 'id' => 3], ['type' => 'everyone', 'id' => 0]],
            Mentions::parse([7, ['type' => 'agent', 'id' => 3], ['type' => 'everyone'], ['type' => 'person', 'id' => 7]], 10),
        );
    }

    public function testNothingIsAnEmptyListAndNonsenseIsRefused(): void
    {
        self::assertSame([], Mentions::parse(null, 10));
        foreach (['x', ['a' => 1], [0], [['type' => 'robot', 'id' => 1]], [['type' => 'person']], [1.5]] as $bad) {
            self::assertNull(Mentions::parse($bad, 10), json_encode($bad) ?: '');
        }
    }

    public function testTheCapCountsDistinctMentions(): void
    {
        self::assertCount(2, Mentions::parse([1, 2, 1, 2], 2) ?? []);
        self::assertNull(Mentions::parse([1, 2, 3], 2));
    }

    public function testPeopleAreTheProfileIdsNamed(): void
    {
        self::assertSame([7, 9], Mentions::people(Mentions::parse([7, ['type' => 'agent', 'id' => 3], 9], 10) ?? []));
    }
}
