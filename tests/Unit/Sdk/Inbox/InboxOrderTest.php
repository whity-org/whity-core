<?php

declare(strict_types=1);

namespace Tests\Unit\Sdk\Inbox;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Inbox\InboxCursor;
use Whity\Sdk\Inbox\InboxItem;
use Whity\Sdk\Inbox\InboxOrder;

/** The merged inbox's one order and its cursor (SDK 1.97, #881). */
final class InboxOrderTest extends TestCase
{
    private static function item(string $id, string $at, bool $urgent = false): InboxItem
    {
        return new InboxItem($id, 'Item ' . $id, null, $at, null, null, null, [], null, $urgent);
    }

    public function testUrgentComesBeforeNewer(): void
    {
        $urgentOld = self::item('1', '2026-01-01 00:00:00', true);
        $plainNew = self::item('2', '2026-11-01 00:00:00');

        self::assertLessThan(0, InboxOrder::compare('a', $urgentOld, 'a', $plainNew));
        self::assertGreaterThan(0, InboxOrder::compare('a', $plainNew, 'a', $urgentOld));
    }

    public function testNewestFirstAcrossTimestampFormats(): void
    {
        // Postgres text and ISO 8601 with a zone name the same instant differently.
        $pg = self::item('1', '2026-11-11 05:00:00');
        $iso = self::item('2', '2026-11-11T08:00:01+03:00');

        self::assertGreaterThan(0, InboxOrder::compare('a', $pg, 'b', $iso), 'the ISO one is a second newer');
    }

    public function testTiesBreakBySourceThenIdDescendingNumerically(): void
    {
        $at = '2026-11-11 05:00:00';
        self::assertLessThan(0, InboxOrder::compare('a', self::item('1', $at), 'b', self::item('9', $at)));
        self::assertLessThan(0, InboxOrder::compare('a', self::item('10', $at), 'a', self::item('9', $at)));
        self::assertSame(0, InboxOrder::compare('a', self::item('7', $at), 'a', self::item('7', $at)));
    }

    public function testUnreadableTimestampSortsAsOldest(): void
    {
        self::assertNull(InboxOrder::epochMicros('not a date'));
        self::assertGreaterThan(
            0,
            InboxOrder::compare('a', self::item('1', 'not a date'), 'a', self::item('2', '1970-01-02 00:00:00'))
        );
    }

    public function testCursorRoundTripsAndIsAfterIsStrict(): void
    {
        $item = self::item('42', '2026-11-11 05:00:00', true);
        $cursor = InboxCursor::decode(InboxCursor::fromItem('acme:tasks', $item)->encode());

        self::assertNotNull($cursor);
        self::assertTrue($cursor->urgent);
        self::assertSame('acme:tasks', $cursor->source);
        self::assertSame('42', $cursor->id);
        self::assertFalse(InboxOrder::isAfter('acme:tasks', $item, $cursor), 'the cursor item itself is not after itself');
        self::assertTrue(InboxOrder::isAfter('acme:tasks', self::item('41', '2026-11-11 05:00:00', true), $cursor));
    }

    public function testGarbageCursorsDecodeToNull(): void
    {
        foreach (['', '!!!', base64_encode('[1,2,3]'), base64_encode('[2,"2026-01-01","a","1"]'), base64_encode('[0,"nope","a","1"]')] as $raw) {
            self::assertNull(InboxCursor::decode($raw), $raw);
        }
    }

    public function testItemCarriesUrgentOnTheWire(): void
    {
        self::assertTrue(self::item('1', '2026-11-11', true)->toArray()['urgent']);
        self::assertFalse(self::item('1', '2026-11-11')->toArray()['urgent']);
    }
}
