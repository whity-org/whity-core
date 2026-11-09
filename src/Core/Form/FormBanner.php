<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * The picture at the top of a form (WHIT-944, migration 223): one of the patterns
 * the browser draws from the workspace's main colour, or an image the author
 * uploaded.
 *
 * A pattern is stored as its style and a seed, never as a picture, so it follows
 * the brand when the main colour changes and costs nothing to keep. The drawing
 * lives in `packages/features/src/forms/banner-art.ts`; this class only knows
 * which styles exist, so a stored banner can't name one the browser can't draw.
 *
 * An image is stored beside the workspace's branding assets
 * (`{tenant}/branding/form-banner-…`), so the public, hardened
 * `GET /api/v1/branding/asset/{tenantId}/{name}` serves it to visitors of a
 * public form without a second file route. Its name carries a content hash, so
 * nobody guesses one from a form id.
 */
final class FormBanner
{
    /** @var list<string> Kept in step with `BANNER_STYLES` in banner-art.ts. */
    public const STYLES = ['gradient', 'waves', 'dots', 'stripes', 'circles', 'mesh'];

    public const KIND_PATTERN = 'pattern';
    public const KIND_IMAGE = 'image';

    /** A seed is any whole number in this range; the browser derives the layout from it. */
    public const SEED_MAX = 2_147_483_647;

    /** Uploaded banners: bigger than a logo, still small enough for a phone on a slow line. */
    public const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    /** The stored name of an uploaded banner, as {@see self::imageName()} makes it. */
    private const IMAGE_NAME_PATTERN = '/^form-banner-\d+-[0-9a-f]{16}\.(png|jpg|webp)$/';

    private function __construct()
    {
    }

    /**
     * What a client sent as `banner` in a PATCH, ready to store, given the banner
     * stored now.
     *
     * - `null` removes the banner.
     * - `{kind: "pattern", style, seed}` chooses a drawn one.
     * - `{kind: "image"}` keeps the uploaded image already there. An image is set by
     *   uploading it (`POST /api/v1/forms/{id}/banner`), never by naming a file, so
     *   a client can't point a form at somebody else's asset.
     *
     * @param array<string, mixed>|null $current The stored banner, from {@see self::fromStorage()}.
     * @return array<string, mixed>|null
     * @throws FormRejectedException On anything else.
     */
    public static function fromInput(mixed $value, ?array $current): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new FormRejectedException('banner must be an object or null');
        }

        $kind = $value['kind'] ?? null;
        if ($kind === self::KIND_IMAGE) {
            if (($current['kind'] ?? null) !== self::KIND_IMAGE) {
                throw new FormRejectedException('banner: upload an image with POST /api/v1/forms/{id}/banner');
            }

            return $current;
        }
        if ($kind !== self::KIND_PATTERN) {
            throw new FormRejectedException('banner.kind must be "pattern" or "image"');
        }

        $style = $value['style'] ?? null;
        if (!is_string($style) || !in_array($style, self::STYLES, true)) {
            throw new FormRejectedException('banner.style must be one of: ' . implode(', ', self::STYLES));
        }
        $seed = $value['seed'] ?? null;
        if (!is_int($seed) || $seed < 0 || $seed > self::SEED_MAX) {
            throw new FormRejectedException('banner.seed must be a whole number from 0 to ' . self::SEED_MAX);
        }

        return ['kind' => self::KIND_PATTERN, 'style' => $style, 'seed' => $seed];
    }

    /**
     * The stored column, or null when it holds nothing this version can draw.
     *
     * @return array<string, mixed>|null
     */
    public static function fromStorage(mixed $stored): ?array
    {
        $decoded = is_string($stored) ? json_decode($stored, true) : (is_array($stored) ? $stored : null);
        if (!is_array($decoded)) {
            return null;
        }

        if (($decoded['kind'] ?? null) === self::KIND_IMAGE) {
            $name = $decoded['name'] ?? null;

            return is_string($name) && preg_match(self::IMAGE_NAME_PATTERN, $name) === 1
                ? ['kind' => self::KIND_IMAGE, 'name' => $name]
                : null;
        }

        try {
            return self::fromInput($decoded, null);
        } catch (FormRejectedException) {
            return null;
        }
    }

    /**
     * What a client reads: a pattern as stored, an image as the URL that serves it.
     *
     * @param array<string, mixed>|null $banner From {@see self::fromStorage()}.
     * @return array<string, mixed>|null
     */
    public static function present(?array $banner, int $tenantId): ?array
    {
        if ($banner === null) {
            return null;
        }
        if ($banner['kind'] === self::KIND_IMAGE) {
            return [
                'kind' => self::KIND_IMAGE,
                'image_url' => '/api/v1/branding/asset/' . $tenantId . '/' . rawurlencode((string) $banner['name']),
            ];
        }

        return $banner;
    }

    /** The stored name for an uploaded banner: the form and a hash of its bytes. */
    public static function imageName(int $formId, string $bytes, string $ext): string
    {
        return sprintf('form-banner-%d-%s.%s', $formId, substr(hash('sha256', $bytes), 0, 16), $ext);
    }

    /**
     * The image type from its first bytes, never from the file name: PNG, JPEG or
     * WebP. SVG is refused, because a banner is shown on public pages and an SVG
     * is a document that can carry script.
     */
    public static function imageExtension(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($bytes, 'RIFF') && strlen($bytes) >= 12 && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }

        return null;
    }
}
