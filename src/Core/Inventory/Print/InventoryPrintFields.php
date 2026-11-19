<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Print;

use PDO;
use Whity\Core\Document\Fields\DocumentFieldProvider;
use Whity\Core\Document\Fields\FieldLabels;
use Whity\Core\i18n\LocalizedText;
use Whity\Core\Money\Currency;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Sdk\Inventory\Quantity;

/**
 * What the inventory record types share when a document prints them (IV.20):
 * reading rows, names in each language, quantities and amounts.
 *
 * Quantities print number then unit ("25 kg", IV.21) and amounts as CU.2
 * writes them ("$312.00", "JOD 312.000"), Western digits in both languages.
 * A bilingual name prints in English as `…item` and in Arabic as `…item_ar`,
 * each falling back to the other when the item has one name only.
 */
abstract class InventoryPrintFields implements DocumentFieldProvider
{
    protected FieldLabels $labels;

    public function __construct(
        protected readonly PDO $db,
        protected readonly ?SettingsService $settings = null,
        ?FieldLabels $labels = null,
    ) {
        $this->labels = $labels ?? new FieldLabels();
    }

    /**
     * Every inventory record type a document can print, for the three places that register providers.
     *
     * @return list<DocumentFieldProvider>
     */
    public static function all(PDO $db, ?SettingsService $settings = null, ?FieldLabels $labels = null): array
    {
        return [
            new PurchaseOrderFieldProvider($db, $settings, $labels),
            new ReceiptFieldProvider($db, $settings, $labels),
            new VariantLabelFieldProvider($db, $settings, $labels),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function one(string $sql, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    protected function rows(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * A name in English and in Arabic, each falling back to the other.
     *
     * @return array{0: string, 1: string}
     */
    protected static function names(mixed $stored, string $original): array
    {
        $names = LocalizedText::decode($stored);
        $en = trim((string) ($names['en'] ?? ''));
        $ar = trim((string) ($names['ar'] ?? ''));

        $en = $en !== '' ? $en : $original;

        return [$en, $ar !== '' ? $ar : $en];
    }

    /** "25 kg": a quantity in steps of its unit's decimals, then the unit. */
    protected static function quantity(int $steps, int $decimals, string $unit): string
    {
        return (new Quantity($steps, $decimals))->toDecimal() . ($unit !== '' ? ' ' . $unit : '');
    }

    protected static function money(?int $minor, string $currency): ?string
    {
        if ($minor === null || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return null;
        }

        return Currency::display($minor, $currency);
    }

    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** Month names as they are written in Jordan, as the guideline's starters write them. */
    private const MONTHS_AR = ['كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران', 'تموز', 'آب', 'أيلول', 'تشرين الأول', 'تشرين الثاني', 'كانون الأول'];

    /**
     * "27 November 2026" or "27 تشرين الثاني 2026": in the language of the
     * template that prints it (`…_ar` paths), not of the person printing, with
     * the day and year in Western digits.
     */
    protected static function date(mixed $value, string $lang = 'en'): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            $date = new \DateTimeImmutable(substr($value, 0, 19));
        } catch (\Exception) {
            return null;
        }
        $month = (int) $date->format('n') - 1;

        return $date->format('j') . ' ' . ($lang === 'ar' ? self::MONTHS_AR[$month] : self::MONTHS[$month]) . ' ' . $date->format('Y');
    }

    protected function personName(mixed $profileId): ?string
    {
        if (!is_numeric($profileId)) {
            return null;
        }
        $row = $this->one('SELECT display_name FROM profiles WHERE id = :id', [':id' => (int) $profileId]);
        $name = is_string($row['display_name'] ?? null) ? trim($row['display_name']) : '';

        return $name !== '' ? $name : null;
    }

    protected function workspaceCurrency(int $tenantId): string
    {
        $code = $this->settings?->effective($tenantId)[SettingsRegistry::BILLING_DEFAULT_CURRENCY] ?? null;
        $code = is_string($code) ? strtoupper(trim($code)) : '';

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : 'USD';
    }

    /** Empty text as no value, so a template's required field can refuse it (E.27). */
    protected static function text(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }

    /**
     * Picker items from [path suffix => [label key, English, sample]].
     *
     * @param array<string, array{0: string, 1: string, 2: string}> $fields
     * @return list<array{path: string, label: string, sample: string}>
     */
    protected function items(string $prefix, array $fields): array
    {
        $items = [];
        foreach ($fields as $path => [$key, $english, $sample]) {
            $items[] = ['path' => $prefix . '.' . $path, 'label' => $this->labels->get($key, $english), 'sample' => $sample];
        }

        return $items;
    }

    protected static function digits(string $id): ?int
    {
        return ctype_digit($id) && strlen($id) <= 18 ? (int) $id : null;
    }
}
