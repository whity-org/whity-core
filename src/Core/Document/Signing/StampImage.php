<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

/**
 * A company stamp's image (#1729): a PNG or JPEG, sent and kept as a data URL.
 *
 * Kept in the row rather than as a stored file because it is small, it is
 * copied into every stamp record as evidence, and the final PDF draws it
 * without another read. The bytes are checked to be the type the data URL
 * names, and the picture must have a sensible size.
 */
final class StampImage
{
    /** 512 KB of image. A scanned stamp at 600 dpi is well under this. */
    public const MAX_BYTES = 524_288;

    private const MIN_SIDE = 32;
    private const MAX_SIDE = 4000;

    /**
     * Validate a data URL and return it in canonical form. Throws with a sentence
     * for the person uploading it, in `clientMessage`.
     *
     * @throws StampImageRejectedException
     */
    public static function normalise(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new StampImageRejectedException('Add the stamp\'s image, a PNG or JPEG file.');
        }
        if (preg_match('#^data:(image/png|image/jpeg);base64,([A-Za-z0-9+/=\s]+)$#', $value, $m) !== 1) {
            throw new StampImageRejectedException('The stamp\'s image must be a PNG or JPEG file.');
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);
        if ($bytes === false || $bytes === '') {
            throw new StampImageRejectedException('The stamp\'s image could not be read. Choose the file again.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new StampImageRejectedException('The stamp\'s image is too large. Use a file under 512 KB.');
        }

        $isPng = str_starts_with($bytes, "\x89PNG\r\n\x1a\n");
        $isJpeg = str_starts_with($bytes, "\xFF\xD8\xFF");
        if (($m[1] === 'image/png' && !$isPng) || ($m[1] === 'image/jpeg' && !$isJpeg)) {
            throw new StampImageRejectedException('The stamp\'s image must be a PNG or JPEG file.');
        }

        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            throw new StampImageRejectedException('The stamp\'s image could not be read. Choose the file again.');
        }
        [$width, $height] = $size;
        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE || $width > self::MAX_SIDE || $height > self::MAX_SIDE) {
            throw new StampImageRejectedException('The stamp\'s image must be between 32 and 4000 pixels on each side.');
        }

        return 'data:' . $m[1] . ';base64,' . base64_encode($bytes);
    }

    public static function hash(string $dataUrl): string
    {
        return hash('sha256', $dataUrl);
    }
}
