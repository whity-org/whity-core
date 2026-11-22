<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

/**
 * The rules for a workspace's @handle (design section 36, HD.1–HD.3).
 *
 * A workspace's `tenants.slug` is its public @handle, unique on the
 * installation. A handle is `a–z`, `0–9` and `-`, 3 to 30 characters, starts
 * with a letter, ends with a letter or digit and has no `--`. That keeps every
 * handle a valid DNS label (handles are also subdomains) and free of `@` and
 * `.`, so `@handle@installation` can address a workspace on another
 * installation later (HD.8).
 *
 * Handles Whity makes itself are `ws-` plus 12 hex digits. Nobody may choose a
 * handle that starts with `ws-`.
 *
 * Pure functions only: nothing here reads the database. {@see Handles} adds
 * availability, history and changes.
 */
final class HandleRules
{
    public const MIN = 3;
    public const MAX = 30;
    public const GENERATED_PREFIX = 'ws-';

    /** Why a handle can't be used: the codes the API and the web app share. */
    public const TOO_SHORT = 'too_short';
    public const TOO_LONG = 'too_long';
    public const CHARACTERS = 'characters';
    public const START = 'start';
    public const END = 'end';
    public const DOUBLE_DASH = 'double_dash';
    public const GENERATED = 'generated';
    public const RESERVED = 'reserved';
    public const TAKEN = 'taken';
    public const HELD = 'held';
    public const TOO_SOON = 'too_soon';

    /**
     * Arabic letters to Latin, for SUGGESTING a handle from an Arabic name
     * (HD.3). Only a suggestion the person edits; never applied silently.
     *
     * @var array<string, string>
     */
    private const ARABIC = [
        'ا' => 'a', 'أ' => 'a', 'إ' => 'i', 'آ' => 'a', 'ء' => '', 'ؤ' => 'o', 'ئ' => 'e',
        'ب' => 'b', 'ت' => 't', 'ث' => 'th', 'ج' => 'j', 'ح' => 'h', 'خ' => 'kh',
        'د' => 'd', 'ذ' => 'dh', 'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh',
        'ص' => 's', 'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh',
        'ف' => 'f', 'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
        'ه' => 'h', 'ة' => 'a', 'و' => 'w', 'ي' => 'y', 'ى' => 'a',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    private function __construct()
    {
    }

    /** What a person typed, as a handle: trimmed, without a leading `@`, lower case. */
    public static function normalize(string $typed): string
    {
        $handle = strtolower(trim($typed));

        return ltrim($handle, '@');
    }

    /**
     * Why `$handle` (already normalised) breaks the shape rules, or null when
     * its shape is fine. Reserved words are checked separately
     * ({@see ReservedHandles}), and so is the `ws-` prefix, which only Whity
     * may use ({@see self::isGenerated()}).
     */
    public static function shapeProblem(string $handle): ?string
    {
        $length = strlen($handle);
        if ($length < self::MIN) {
            return self::TOO_SHORT;
        }
        if ($length > self::MAX) {
            return self::TOO_LONG;
        }
        if (preg_match('/^[a-z0-9-]+$/', $handle) !== 1) {
            return self::CHARACTERS;
        }
        if (preg_match('/^[a-z]/', $handle) !== 1) {
            return self::START;
        }
        if (str_ends_with($handle, '-')) {
            return self::END;
        }
        if (str_contains($handle, '--')) {
            return self::DOUBLE_DASH;
        }

        return null;
    }

    /** A handle Whity made (`ws-` and 12 hex digits). */
    public static function isGenerated(string $handle): bool
    {
        return preg_match('/^ws-[0-9a-f]{12}$/', $handle) === 1;
    }

    /** Whether a person may choose `$handle` for its shape: the rules plus no `ws-`. */
    public static function choosableProblem(string $handle): ?string
    {
        $shape = self::shapeProblem($handle);
        if ($shape !== null) {
            return $shape;
        }
        if (str_starts_with($handle, self::GENERATED_PREFIX)) {
            return self::GENERATED;
        }

        return null;
    }

    /** A new handle Whity makes itself. */
    public static function generate(): string
    {
        return self::GENERATED_PREFIX . bin2hex(random_bytes(6));
    }

    /**
     * A suggested handle from a workspace's name, or '' when nothing usable
     * comes out. Latin letters and digits are kept, Arabic letters are
     * written in Latin ones, everything else becomes a dash; then it is cut
     * to the rules. Only a suggestion: the person always sees and edits it.
     */
    public static function suggest(string $name): string
    {
        $out = '';
        $chars = preg_split('//u', mb_strtolower(trim($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $char) {
            if (preg_match('/^[a-z0-9]$/', $char) === 1) {
                $out .= $char;
            } elseif (isset(self::ARABIC[$char])) {
                $out .= self::ARABIC[$char];
            } elseif (preg_match('/^\p{Mn}$/u', $char) === 1) {
                // Arabic short vowels and shadda: skip.
                continue;
            } else {
                $out .= '-';
            }
        }

        return self::tidy($out);
    }

    /**
     * Cut any string to a handle's shape: dashes collapsed and trimmed, a
     * leading digit or dash dropped, at most {@see self::MAX} characters.
     * '' when what's left is too short.
     */
    public static function tidy(string $raw): string
    {
        $handle = preg_replace('/[^a-z0-9-]+/', '-', strtolower($raw)) ?? '';
        $handle = preg_replace('/-+/', '-', $handle) ?? '';
        if (str_starts_with($handle, self::GENERATED_PREFIX)) {
            $handle = substr($handle, strlen(self::GENERATED_PREFIX));
        }
        $handle = ltrim($handle, '-0123456789');
        $handle = rtrim(substr($handle, 0, self::MAX), '-');

        return strlen($handle) >= self::MIN ? $handle : '';
    }
}
