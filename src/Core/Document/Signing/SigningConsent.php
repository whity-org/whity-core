<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

/**
 * The consent line a signer ticks (WHIT-922, #1726).
 *
 * Each signature records the VERSION of the wording the signer agreed to. The
 * wording itself is shown in the signer's language by the web app (keys
 * `signing.consent.*` in the documents domain); this is the English text of
 * each version, kept so the audit page can show what a version said.
 *
 * A change to the wording is a new version, never an edit of an old one.
 */
final class SigningConsent
{
    public const CURRENT = '2026-11-1';

    /** The consent line a stamp holder ticks before applying a company stamp (#1729). */
    public const STAMP_CURRENT = '2026-11-stamp-1';

    /** @var array<string, string> */
    private const WORDING = [
        '2026-11-1' => 'I have read this document and I sign it electronically, in the capacity shown. '
            . 'I agree that my electronic signature, with this audit trail, records that I signed this version.',
        '2026-11-stamp-1' => 'I hold this company stamp and I am authorised to apply it to this document. '
            . 'I agree that this audit trail records that I applied it to this version. '
            . 'This is an authorised person applying the image of the stamp, not a certified electronic seal.',
    ];

    public static function isCurrent(string $version, bool $stamp = false): bool
    {
        return $version === ($stamp ? self::STAMP_CURRENT : self::CURRENT);
    }

    public static function wording(string $version): ?string
    {
        return self::WORDING[$version] ?? null;
    }
}
