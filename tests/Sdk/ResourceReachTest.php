<?php

declare(strict_types=1);

namespace Tests\Sdk;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Rbac\ResourceReach;

/**
 * #1074: the value a ResourceScope returns can only narrow a list.
 */
final class ResourceReachTest extends TestCase
{
    public function testFilterReturnsASubsetInInputOrder(): void
    {
        $reach = ResourceReach::of([3, 1]);

        self::assertSame([3, 1], $reach->filter([5, 3, 4, 1]));
        self::assertSame([], $reach->filter([]));
    }

    public function testFilterOnUnrestrictedReturnsTheInputUnchanged(): void
    {
        self::assertSame([5, 3], ResourceReach::unrestricted()->filter([5, 3]));
    }

    public function testNoneContainsNothing(): void
    {
        $reach = ResourceReach::none();

        self::assertTrue($reach->isEmpty());
        self::assertFalse($reach->isUnrestricted());
        self::assertFalse($reach->contains(1));
        self::assertSame([], $reach->ids());
        self::assertSame([], $reach->filter([1, 2]));
    }

    public function testIdsAreDistinctAndAscending(): void
    {
        self::assertSame([1, 2, 7], ResourceReach::of([7, 2, 7, 1])->ids());
    }

    public function testIdsOnUnrestrictedThrowsRatherThanReadingAsEmpty(): void
    {
        $this->expectException(\LogicException::class);

        ResourceReach::unrestricted()->ids();
    }
}
