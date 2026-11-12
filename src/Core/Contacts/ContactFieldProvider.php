<?php

declare(strict_types=1);

namespace Whity\Core\Contacts;

use PDO;
use Whity\Core\CustomFields\CustomFieldRepository;
use Whity\Core\CustomFields\CustomFieldService;
use Whity\Core\Document\Fields\DocumentFieldProvider;
use Whity\Core\Document\Fields\FieldLabels;
use Whity\Core\Form\FieldType;
use Whity\Core\RBAC\CorePermissions;

/**
 * A contact as a record type for document templates (WHIT-954, design 3.3
 * A9): `{{contact.name}}`, `{{contact.tax_number}}`, and every field the
 * workspace added (`{{contact.preferred_branch}}`), which appears here the
 * moment it is made, with no code.
 *
 * Previewing with a real contact needs `contacts:read`.
 *
 * @i18n-keys documents
 *   fields.contact.record = Contact
 *   fields.contact.group = Contact
 *   fields.contact.yourFields = Your fields
 *   fields.contact.name = Name
 *   fields.contact.contactPerson = Contact person
 *   fields.contact.email = Email
 *   fields.contact.mobile = Mobile
 *   fields.contact.phone = Phone
 *   fields.contact.taxNumber = Tax number
 *   fields.contact.paymentTerms = How they pay
 *   fields.contact.address = Address
 */
final class ContactFieldProvider implements DocumentFieldProvider
{
    public const RECORD_TYPE = 'contact';

    /** Built-in paths, with their label keys, English labels and samples. */
    private const BUILT_IN = [
        'name' => ['fields.contact.name', 'Name', 'Example Café'],
        'contact_person' => ['fields.contact.contactPerson', 'Contact person', 'Ahmad Khalil'],
        'email' => ['fields.contact.email', 'Email', 'orders@example.com'],
        'mobile' => ['fields.contact.mobile', 'Mobile', '+962795550101'],
        'phone' => ['fields.contact.phone', 'Phone', '+96265550101'],
        'tax_number' => ['fields.contact.taxNumber', 'Tax number', '12345678'],
        'payment_terms' => ['fields.contact.paymentTerms', 'How they pay', 'CliQ, within 14 days'],
        'address' => ['fields.contact.address', 'Address', 'Abdoun, Amman'],
    ];

    private FieldLabels $labels;

    public function __construct(
        private readonly PDO $db,
        ?FieldLabels $labels = null,
    ) {
        $this->labels = $labels ?? new FieldLabels();
    }

    /**
     * The built-in keys a custom field may not take.
     *
     * @return list<string>
     */
    public static function reservedKeys(): array
    {
        return array_merge(array_keys(self::BUILT_IN), ['id', 'notes', 'tags', 'is_customer', 'is_supplier', 'profile_id', 'archived', 'created_at', 'updated_at', 'fields']);
    }

    public function recordType(): string
    {
        return self::RECORD_TYPE;
    }

    public function recordLabel(): string
    {
        return $this->labels->get('fields.contact.record', 'Contact');
    }

    public function groups(int $tenantId): array
    {
        $items = [];
        foreach (self::BUILT_IN as $key => [$labelKey, $label, $sample]) {
            $items[] = ['path' => 'contact.' . $key, 'label' => $this->labels->get($labelKey, $label), 'sample' => $sample];
        }
        $groups = [['group' => $this->labels->get('fields.contact.group', 'Contact'), 'items' => $items]];

        $own = [];
        foreach ((new CustomFieldRepository($this->db))->definitions($tenantId, self::RECORD_TYPE) as $def) {
            $item = ['path' => 'contact.' . $def['field_key'], 'label' => CustomFieldService::labelText($def)];
            $own[] = $item;
        }
        if ($own !== []) {
            $groups[] = ['group' => $this->labels->get('fields.contact.yourFields', 'Your fields'), 'items' => $own];
        }

        return $groups;
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): ?array
    {
        if ($recordId === null || !ctype_digit($recordId) || !$hasPermission(CorePermissions::CONTACTS_READ)) {
            return null;
        }
        $id = (int) $recordId;
        $row = (new ContactRepository($this->db))->find($tenantId, $id);
        if ($row === null) {
            return null;
        }
        $values = [];
        foreach (array_keys(self::BUILT_IN) as $key) {
            $value = (string) $row[$key];
            $values['contact.' . $key] = $value === '' ? null : $value;
        }
        $repo = new CustomFieldRepository($this->db);
        $own = $repo->values($tenantId, self::RECORD_TYPE, [$id])[$id] ?? [];
        foreach ($repo->definitions($tenantId, self::RECORD_TYPE) as $def) {
            $key = (string) $def['field_key'];
            $values['contact.' . $key] = array_key_exists($key, $own) ? self::display((string) $def['kind'], $own[$key]) : null;
        }

        return ['label' => (string) $row['name'], 'values' => $values];
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        if (!$hasPermission(CorePermissions::CONTACTS_READ)) {
            return [];
        }
        $rows = (new ContactRepository($this->db))->list($tenantId, ['q' => $query], max(1, min($limit, 50)), 0);

        return array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'label' => (string) $r['name']], $rows);
    }

    /** A value as a document prints it. */
    private static function display(string $kind, mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $kind === FieldType::MULTISELECT && is_array($value) => implode(', ', array_map('strval', $value)),
            $kind === FieldType::CHECKBOX => $value === true ? 'Yes' : 'No',
            is_scalar($value) => (string) $value,
            default => null,
        };
    }
}
