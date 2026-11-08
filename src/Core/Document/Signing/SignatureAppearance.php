<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

/**
 * A drawn signature (WHIT-922, #1726): the strokes a person drew, as points.
 *
 * Kept as strokes rather than as an image, so it is small, exact, and drawn
 * again anywhere (the signing screen, the audit page, later the PDF block)
 * without decoding a picture. The shape is
 * `{"width": W, "height": H, "strokes": [[[x, y], [x, y], ...], ...]}` with
 * coordinates inside the box.
 *
 * A drawn signature is REQUIRED on every signature (the owner's decision C1).
 * People who cannot draw with a pointer cannot sign yet; see the PR notes.
 */
final class SignatureAppearance
{
    private const MAX_SIDE = 2000;
    private const MAX_STROKES = 200;
    private const MAX_POINTS = 5000;
    /** Fewer points than this is a dot or a slip, not a signature. */
    private const MIN_POINTS = 5;

    /**
     * Validate and normalise. Throws with a sentence for the signer.
     *
     * @return array{width: int, height: int, strokes: list<list<array{0: float|int, 1: float|int}>>}
     *
     * @throws \InvalidArgumentException
     */
    public static function normalise(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Draw your signature to sign.');
        }
        $width = $value['width'] ?? null;
        $height = $value['height'] ?? null;
        $strokes = $value['strokes'] ?? null;
        if (!is_int($width) || !is_int($height) || $width < 1 || $height < 1
            || $width > self::MAX_SIDE || $height > self::MAX_SIDE
        ) {
            throw new \InvalidArgumentException('The drawn signature has no usable size.');
        }
        if (!is_array($strokes) || !array_is_list($strokes) || $strokes === [] || count($strokes) > self::MAX_STROKES) {
            throw new \InvalidArgumentException('Draw your signature to sign.');
        }

        $points = 0;
        $out = [];
        foreach ($strokes as $stroke) {
            if (!is_array($stroke) || !array_is_list($stroke) || $stroke === []) {
                throw new \InvalidArgumentException('The drawn signature could not be read.');
            }
            $line = [];
            foreach ($stroke as $point) {
                if (!is_array($point) || !array_is_list($point) || count($point) !== 2) {
                    throw new \InvalidArgumentException('The drawn signature could not be read.');
                }
                [$x, $y] = $point;
                if ((!is_int($x) && !is_float($x)) || (!is_int($y) && !is_float($y))
                    || $x < 0 || $y < 0 || $x > $width || $y > $height
                ) {
                    throw new \InvalidArgumentException('The drawn signature could not be read.');
                }
                // One decimal is finer than any pointer, and keeps the record small.
                $line[] = [round((float) $x, 1), round((float) $y, 1)];
                $points++;
            }
            $out[] = $line;
        }
        if ($points < self::MIN_POINTS) {
            throw new \InvalidArgumentException('Draw your signature to sign.');
        }
        if ($points > self::MAX_POINTS) {
            throw new \InvalidArgumentException('The drawn signature is too detailed. Clear it and draw it again.');
        }

        return ['width' => $width, 'height' => $height, 'strokes' => $out];
    }

    /**
     * The one encoding stored and hashed.
     *
     * @param array{width: int, height: int, strokes: list<list<array{0: float|int, 1: float|int}>>} $appearance
     */
    public static function encode(array $appearance): string
    {
        return (string) json_encode(
            ['height' => $appearance['height'], 'strokes' => $appearance['strokes'], 'width' => $appearance['width']],
            JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
