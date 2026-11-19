<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\i18n\LocalizedText;
use Whity\Core\i18n\LocalizedTextRejected;

/**
 * Bilingual names for items, units, places and categories (register IV.21),
 * through the one bilingual text (WHIT-1038).
 *
 * A name arrives as text (its language guessed) or as `{en, ar}`. It is kept
 * twice: `name`, the original text, which lists sort and search by, and
 * `names`, every language as LocalizedText JSON, which the API returns so a
 * client shows the reader's language with the one fallback rule.
 */
final class InventoryNames
{
    private function __construct()
    {
    }

    /**
     * @return array{name: string, names: string}
     * @throws InventoryRejectedException
     */
    public static function normalize(mixed $raw, string $field, int $maxLength): array
    {
        $bare = is_string($raw) ? LocalizedText::guessLanguage(trim($raw)) : null;
        try {
            $names = LocalizedText::normalize($raw, $field, $bare, null, $maxLength, true);
        } catch (LocalizedTextRejected $e) {
            throw InventoryRejectedException::field($field, $e->clientMessage);
        }
        // Numbers stay Western digits in both languages (IV.21): a name typed
        // with Arabic-Indic digits ("كيس ٢٥ كغ") is kept as "كيس 25 كغ".
        $names = array_map(static fn (string $name): string => self::westernDigits($name), $names);
        $original = $bare !== null ? ($names[$bare] ?? '') : ($names['en'] ?? $names['ar'] ?? (string) reset($names));
        if (trim($original) === '') {
            throw InventoryRejectedException::field($field, $field . ' must not be empty');
        }

        return ['name' => $original, 'names' => LocalizedText::encode($names)];
    }

    /** Arabic-Indic and Persian digits as Western ones. */
    public static function westernDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    /** @return array<string, string> */
    public static function decode(mixed $stored, string $original): array
    {
        $names = LocalizedText::decode($stored);

        return $names !== [] ? $names : [LocalizedText::guessLanguage($original) => $original];
    }
}
