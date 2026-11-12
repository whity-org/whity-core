<?php

declare(strict_types=1);

namespace Whity\Core\Document\Print;

/**
 * The papers a document can print on (6.1), by the name a document's
 * `page.size` carries.
 *
 * Core ships A4 (the default), A5, Letter and an 80 mm roll. The roll is the
 * extension point for a paid plugin's receipt (Front Office's `TplReceipt80`,
 * release 0.2.29): the plugin draws the receipt as a document whose
 * `page.size` is `80mm`, and core prints it on the roll. Core knows only the
 * paper, nothing about receipts.
 *
 * A roll has no natural height: render-service paginates against `heightMm`,
 * so the roll's is long enough for a receipt to fit one "page", and the
 * printer cuts after the content. Its margins are small, because 16 mm a side
 * would leave 48 mm to print on.
 */
final class PageSizes
{
    public const DEFAULT = 'A4';
    public const ROLL_80 = '80mm';

    /** @var array<string, array{widthMm: float, heightMm: float, marginMm: float}> */
    private const SIZES = [
        'A4'          => ['widthMm' => 210.0, 'heightMm' => 297.0, 'marginMm' => 16.0],
        'A5'          => ['widthMm' => 148.0, 'heightMm' => 210.0, 'marginMm' => 16.0],
        'Letter'      => ['widthMm' => 215.9, 'heightMm' => 279.4, 'marginMm' => 16.0],
        self::ROLL_80 => ['widthMm' => 80.0, 'heightMm' => 600.0, 'marginMm' => 4.0],
    ];

    /**
     * The paper a document names; A4 when it names none or one Whity doesn't know.
     *
     * @return array{widthMm: float, heightMm: float, marginMm: float}
     */
    public static function resolve(mixed $name): array
    {
        return is_string($name) && isset(self::SIZES[$name]) ? self::SIZES[$name] : self::SIZES[self::DEFAULT];
    }

    /** @return list<string> every name a document may use */
    public static function names(): array
    {
        return array_keys(self::SIZES);
    }
}
