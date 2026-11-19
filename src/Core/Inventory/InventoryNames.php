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
        $original = $bare !== null ? ($names[$bare] ?? '') : ($names['en'] ?? $names['ar'] ?? (string) reset($names));
        if (trim($original) === '') {
            throw InventoryRejectedException::field($field, $field . ' must not be empty');
        }

        return ['name' => $original, 'names' => LocalizedText::encode($names)];
    }

    /** @return array<string, string> */
    public static function decode(mixed $stored, string $original): array
    {
        $names = LocalizedText::decode($stored);

        return $names !== [] ? $names : [LocalizedText::guessLanguage($original) => $original];
    }
}
