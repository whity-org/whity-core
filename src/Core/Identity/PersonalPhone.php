<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;

/**
 * A person's own phone number (WHIT-1181): optional, stored as E.164 on their
 * profile (migration 304), and theirs across every workspace.
 *
 * WHO SETS IT
 * The person, from their own profile ({@see setByPerson()}), or a Whity ID
 * sign-in whose token carries a verified `phone_number`
 * ({@see fillFromSignIn()}). A sign-in fills an empty phone and refreshes one
 * an earlier sign-in set. It never replaces one the person set, and a phone
 * the person cleared stays cleared.
 *
 * Profiles is a sanctioned GLOBAL identity table (ADR 0005 §1): every query
 * here is keyed by the caller's own profile id.
 */
final class PersonalPhone
{
    public const SOURCE_PERSON = 'person';
    public const SOURCE_IDENTITY_PROVIDER = 'identity_provider';

    private const LOCAL_DIGITS = [
        "\u{0660}" => '0', "\u{0661}" => '1', "\u{0662}" => '2', "\u{0663}" => '3', "\u{0664}" => '4',
        "\u{0665}" => '5', "\u{0666}" => '6', "\u{0667}" => '7', "\u{0668}" => '8', "\u{0669}" => '9',
        "\u{06F0}" => '0', "\u{06F1}" => '1', "\u{06F2}" => '2', "\u{06F3}" => '3', "\u{06F4}" => '4',
        "\u{06F5}" => '5', "\u{06F6}" => '6', "\u{06F7}" => '7', "\u{06F8}" => '8', "\u{06F9}" => '9',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * E.164 from what a person or a provider wrote, or null when it is not a
     * phone number. Spaces, dashes, dots and brackets are dropped; Arabic-Indic
     * and Persian digits read as Western; a leading 00 is the international
     * prefix. A number without its country code is refused: a profile has no
     * country to assume.
     */
    public static function normalize(string $raw): ?string
    {
        $value = strtr(trim($raw), self::LOCAL_DIGITS);
        $value = (string) preg_replace('/[\s\-().\x{00A0}]/u', '', $value);
        if (str_starts_with($value, '00')) {
            $value = '+' . substr($value, 2);
        }

        return preg_match('/^\+[1-9][0-9]{6,14}$/', $value) === 1 ? $value : null;
    }

    /** The person's phone in E.164, or null. */
    public function of(int $profileId): ?string
    {
        // Profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT phone FROM profiles WHERE id = ?');
        $stmt->execute([$profileId]);
        $phone = $stmt->fetchColumn();

        return is_string($phone) && $phone !== '' ? $phone : null;
    }

    /**
     * Set or clear the person's own phone; `$e164` is already normalized.
     * Clearing keeps the source, so a later sign-in does not fill it again.
     */
    public function setByPerson(int $profileId, ?string $e164): void
    {
        // Profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $this->db->prepare('UPDATE profiles SET phone = ?, phone_source = ? WHERE id = ?');
        $stmt->execute([$e164, self::SOURCE_PERSON, $profileId]);
    }

    /**
     * Fill the phone from a sign-in's verified `phone_number` claim. Changes
     * nothing when the claim is missing, unverified or unreadable, or when the
     * person set (or cleared) their phone themselves.
     *
     * @param array<string, mixed> $claims the validated ID-token claims
     * @return bool whether the phone changed
     */
    public function fillFromSignIn(int $profileId, array $claims): bool
    {
        $raw = $claims['phone_number'] ?? null;
        $verified = $claims['phone_number_verified'] ?? null;
        if (!is_string($raw) || !($verified === true || $verified === 'true')) {
            return false;
        }
        $e164 = self::normalize($raw);
        if ($e164 === null) {
            return false;
        }

        // Profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $this->db->prepare(
            'UPDATE profiles SET phone = ?, phone_source = ?
              WHERE id = ? AND (phone_source IS NULL OR phone_source = ?) AND (phone IS NULL OR phone <> ?)'
        );
        $stmt->execute([$e164, self::SOURCE_IDENTITY_PROVIDER, $profileId, self::SOURCE_IDENTITY_PROVIDER, $e164]);

        return $stmt->rowCount() > 0;
    }
}
