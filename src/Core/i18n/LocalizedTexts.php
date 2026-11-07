<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

use PDO;

/**
 * Names of stored entities in more than one language (#821).
 *
 * An entity keeps its ORIGINAL name in its own column (`tenants.name`), with
 * the language of that name beside it (`tenants.name_language`). This class
 * reads and writes the other languages, one `localized_texts` row each.
 *
 * THE ORIGINAL IS NOT ASSUMED TO BE ENGLISH. For a Jordanian institution the
 * Arabic name is usually the legal one. So a missing language falls back to
 * the original, never to English: {@see resolve()}.
 *
 * In the API a name travels as one map of every language it has, original
 * included (`{"ar": "...", "en": "..."}`), plus the original's language. The
 * split between the column and this table is a storage detail.
 *
 * Stateless apart from the connection; worker-safe.
 */
final class LocalizedTexts
{
    public const ENTITY_TENANT = 'tenant';
    public const ENTITY_OU = 'ou';

    public const FIELD_NAME = 'name';

    /** Longest text in one language, as for `tenants.name` (VARCHAR(255)). */
    public const MAX_LENGTH = 255;

    /** Most languages one name may carry. */
    public const MAX_LANGUAGES = 16;

    private const LANGUAGE_PATTERN = '/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The other-language texts for several entities of one workspace.
     *
     * @param array<int> $entityIds
     * @return array<int, array<string, string>> entity id => language => text
     */
    public function forEntities(int $tenantId, string $entityType, array $entityIds, string $field = self::FIELD_NAME): array
    {
        $entityIds = array_values(array_unique(array_map('intval', $entityIds)));
        if ($entityIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($entityIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT entity_id, language_code, value FROM localized_texts
              WHERE tenant_id = ? AND entity_type = ? AND field = ? AND entity_id IN ({$placeholders})
              ORDER BY language_code"
        );
        $stmt->execute([$tenantId, $entityType, $field, ...$entityIds]);

        return self::group($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The other-language names of several workspaces. A workspace's own name is
     * stored under its own id as tenant_id, so each row is bound to the
     * workspace it names.
     *
     * @param array<int> $tenantIds
     * @return array<int, array<string, string>> tenant id => language => text
     */
    public function forTenants(array $tenantIds): array
    {
        $tenantIds = array_values(array_unique(array_map('intval', $tenantIds)));
        if ($tenantIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($tenantIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT entity_id, language_code, value FROM localized_texts
              WHERE tenant_id IN ({$placeholders}) AND entity_id = tenant_id
                AND entity_type = ? AND field = ?
              ORDER BY language_code"
        );
        $stmt->execute([...$tenantIds, self::ENTITY_TENANT, self::FIELD_NAME]);

        return self::group($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Replace an entity's other-language texts for one field with `$texts`.
     * Languages left out are removed. Callers pass the map WITHOUT the original
     * language, which lives in the entity's own column.
     *
     * @param array<string, string> $texts
     */
    public function replace(int $tenantId, string $entityType, int $entityId, array $texts, string $field = self::FIELD_NAME): void
    {
        $delete = $this->db->prepare(
            'DELETE FROM localized_texts
              WHERE tenant_id = ? AND entity_type = ? AND entity_id = ? AND field = ?'
        );
        $delete->execute([$tenantId, $entityType, $entityId, $field]);

        if ($texts === []) {
            return;
        }

        $insert = $this->db->prepare(
            'INSERT INTO localized_texts (tenant_id, entity_type, entity_id, field, language_code, value, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        foreach ($texts as $language => $text) {
            $insert->execute([$tenantId, $entityType, $entityId, $field, $language, $text]);
        }
    }

    /**
     * Remove every text of one entity, when the entity itself is deleted.
     */
    public function forget(int $tenantId, string $entityType, int $entityId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM localized_texts WHERE tenant_id = ? AND entity_type = ? AND entity_id = ?'
        );
        $stmt->execute([$tenantId, $entityType, $entityId]);
    }

    /**
     * Remove every text a workspace holds, when the workspace is deleted.
     */
    public function forgetTenant(int $tenantId): void
    {
        $stmt = $this->db->prepare('DELETE FROM localized_texts WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
    }

    /**
     * Normalize a caller's name map: language codes checked, text trimmed,
     * blank languages dropped (a form sends every language, filled or not).
     *
     * @return array<string, string>
     *
     * @throws LocalizedTextRejected On a wrong shape, a bad code or a long text.
     */
    public static function normalize(mixed $raw, string $field): array
    {
        if (!is_array($raw)) {
            throw new LocalizedTextRejected(
                "{$field} must be an object of language code to text, for example {\"ar\": \"...\", \"en\": \"...\"}."
            );
        }

        $out = [];
        foreach ($raw as $language => $text) {
            if (!is_string($language) || !self::isLanguageCode($language)) {
                throw new LocalizedTextRejected(
                    "{$field} has a key that is not a language code: " . (is_string($language) ? "'{$language}'" : gettype($language)) . '.'
                );
            }
            if ($text === null) {
                continue;
            }
            if (!is_string($text)) {
                throw new LocalizedTextRejected("{$field}.{$language} must be text.");
            }
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > self::MAX_LENGTH) {
                throw new LocalizedTextRejected("{$field}.{$language} must be " . self::MAX_LENGTH . ' characters or fewer.');
            }
            $out[$language] = $text;
        }

        if (count($out) > self::MAX_LANGUAGES) {
            throw new LocalizedTextRejected("{$field} carries more than " . self::MAX_LANGUAGES . ' languages.');
        }

        return $out;
    }

    public static function isLanguageCode(string $code): bool
    {
        return preg_match(self::LANGUAGE_PATTERN, $code) === 1;
    }

    /**
     * The language a name is most likely written in, for a name that arrives
     * without one: Arabic when it has Arabic letters, English otherwise.
     */
    public static function guessLanguage(string $text): string
    {
        return preg_match('/\p{Arabic}/u', $text) === 1 ? 'ar' : 'en';
    }

    /**
     * Every language of a name, original included.
     *
     * @param array<string, string> $others The other-language texts.
     * @return array<string, string>
     */
    public static function merge(string $original, string $originalLanguage, array $others): array
    {
        unset($others[$originalLanguage]);
        if ($original === '') {
            return $others;
        }

        return [$originalLanguage => $original] + $others;
    }

    /**
     * One name for a reader of `$language`: theirs when it exists, otherwise
     * the original, which is the legal name.
     *
     * @param array<string, string> $names
     */
    public static function resolve(array $names, string $language, string $originalLanguage): string
    {
        foreach ([$language, $originalLanguage] as $candidate) {
            if (isset($names[$candidate]) && $names[$candidate] !== '') {
                return $names[$candidate];
            }
        }
        foreach ($names as $text) {
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $rows
     * @return array<int, array<string, string>>
     */
    private static function group(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[(int) $row['entity_id']][(string) $row['language_code']] = (string) $row['value'];
        }

        return $out;
    }
}
