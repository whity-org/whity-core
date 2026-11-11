<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;

/**
 * iCalendar (RFC 5545) text for one booking or a staff feed (ADR 0017,
 * sections 2 and 6). Times are written in UTC (`…Z`), which every client
 * converts, so no VTIMEZONE block is needed. Pure — worker-safe.
 */
final class Ics
{
    /**
     * @param list<array{uid: string, start: DateTimeImmutable, end: DateTimeImmutable, summary: string, description?: string, status?: string, sequence?: int, url?: string}> $events
     */
    public static function calendar(array $events, string $name = '', string $method = 'PUBLISH'): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Whity//Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:' . $method,
        ];
        if ($name !== '') {
            $lines[] = 'X-WR-CALNAME:' . self::text($name);
        }
        $stamp = self::utc(new DateTimeImmutable());
        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $event['uid'];
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART:' . self::utc($event['start']);
            $lines[] = 'DTEND:' . self::utc($event['end']);
            $lines[] = 'SUMMARY:' . self::text($event['summary']);
            $description = $event['description'] ?? '';
            if ($description !== '') {
                $lines[] = 'DESCRIPTION:' . self::text($description);
            }
            $url = $event['url'] ?? '';
            if ($url !== '') {
                $lines[] = 'URL:' . $url;
            }
            $lines[] = 'STATUS:' . ($event['status'] ?? 'CONFIRMED');
            $lines[] = 'SEQUENCE:' . ($event['sequence'] ?? 0);
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    public static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /** Escape a TEXT value (RFC 5545 3.3.11). */
    public static function text(string $value): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\\;', '\\,', '\\n', '\\n', '\\n'], $value);
    }

    /** Fold a content line at 75 octets without splitting a UTF-8 character. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = [];
        $current = '';
        foreach (mb_str_split($line) as $char) {
            $limit = $out === [] ? 75 : 74;
            if (strlen($current) + strlen($char) > $limit) {
                $out[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode("\r\n ", $out);
    }
}
