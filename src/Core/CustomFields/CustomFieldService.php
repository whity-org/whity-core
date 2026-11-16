<?php

declare(strict_types=1);

namespace Whity\Core\CustomFields;

use PDO;
use Whity\Core\Contacts\ContactRejectedException;
use Whity\Core\Db\DbBool;
use Whity\Core\Form\FieldType;

/**
 * The workspace's own fields on its records (WHIT-954, design 3.3 A9).
 *
 * A field has a kind from the form builder; this release offers short and
 * long text, choose one, choose several, amount (a number), date, yes or no,
 * and a person. A new field is usable at once: the record's API returns it
 * under `fields`, lists filter on it (`field.<key>=value`), documents merge it
 * (`{{contact.<key>}}`), and import can fill it.
 *
 * Errors are {@see ContactRejectedException}s: the same "a caller can fix
 * this" carrier, with the words and the status.
 */
final class CustomFieldService
{
    /** The kinds a field can have, in the order the screen shows them. */
    public const KINDS = [
        FieldType::TEXT,
        FieldType::TEXTAREA,
        FieldType::SELECT,
        FieldType::MULTISELECT,
        FieldType::NUMBER,
        FieldType::DATE,
        FieldType::CHECKBOX,
        FieldType::PROFILE_REF,
    ];

    public const MAX_FIELDS = 50;
    public const MAX_CHOICES = 100;
    private const MAX_TEXT = 500;
    private const MAX_LONG_TEXT = 4000;

    public function __construct(
        private readonly PDO $db,
        private readonly CustomFieldRepository $fields,
        private readonly CustomFieldRecordTypes $types,
    ) {
    }

    public function types(): CustomFieldRecordTypes
    {
        return $this->types;
    }

    public function type(string $key): CustomFieldRecordType
    {
        return $this->types->get($key) ?? throw new ContactRejectedException('Fields can\'t be added to "' . $key . '"', 404);
    }

    /** @return list<array<string, mixed>> */
    public function definitions(int $tenantId, string $recordType, bool $withArchived = false): array
    {
        $this->type($recordType);

        return array_map(fn (array $row): array => $this->toApi($tenantId, $row), $this->fields->definitions($tenantId, $recordType, $withArchived));
    }

    /** @return list<array{key: string, label: string, label_ar: string, count: int}> */
    public function recordTypes(int $tenantId): array
    {
        $counts = $this->fields->counts($tenantId);

        return array_map(
            static fn (CustomFieldRecordType $t): array => $t->toApi() + ['count' => $counts[$t->key] ?? 0],
            $this->types->all(),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $tenantId, array $input, ?int $actor): array
    {
        $recordType = is_string($input['record_type'] ?? null) ? $input['record_type'] : '';
        $type = $this->type($recordType);
        $kind = $input['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw new ContactRejectedException('kind must be one of ' . implode(', ', self::KINDS), 422, ['field' => 'kind']);
        }
        $label = self::label($input['label'] ?? null);
        if (count($this->fields->definitions($tenantId, $recordType, true)) >= self::MAX_FIELDS) {
            throw new ContactRejectedException('A record can have at most ' . self::MAX_FIELDS . ' fields of your own', 422);
        }
        $key = is_string($input['key'] ?? null) && $input['key'] !== '' ? $input['key'] : self::keyFrom($label['en'] !== '' ? $label['en'] : $label['ar']);
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
            throw new ContactRejectedException('key is lower-case letters, digits and _, starting with a letter', 422, ['field' => 'key']);
        }
        if (in_array($key, $type->reservedKeys, true)) {
            throw new ContactRejectedException('"' . $key . '" is already one of the record\'s own fields; choose another name', 409, ['field' => 'key']);
        }
        $base = $key;
        for ($n = 2; $this->fields->keyTaken($tenantId, $recordType, $key); $n++) {
            if (is_string($input['key'] ?? null) && $input['key'] !== '') {
                throw new ContactRejectedException('A field with the key "' . $key . '" is already there', 409, ['field' => 'key']);
            }
            $key = mb_substr($base, 0, 60) . '_' . $n;
        }
        $options = self::choices($kind, $input['options'] ?? []);

        $id = $this->fields->insert($tenantId, [
            'record_type' => $recordType,
            'field_key' => $key,
            'label' => json_encode($label, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'kind' => $kind,
            'options_json' => json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'show_in_list' => self::flag($input, 'show_in_list', false),
            'is_required' => self::flag($input, 'required', false),
            'edit_permission' => self::permission($input['edit_permission'] ?? null),
        ], $actor);

        return $this->show($tenantId, $id);
    }

    /**
     * Label, choices, list, required and who can change it may change; the key
     * and kind never do, so documents and filters that use them keep working.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $tenantId, int $id, array $input): array
    {
        $row = $this->fields->find($tenantId, $id) ?? throw ContactRejectedException::notFound('Field');
        foreach (['key', 'kind', 'record_type'] as $fixed) {
            if (array_key_exists($fixed, $input) && $input[$fixed] !== $row[$fixed === 'key' ? 'field_key' : $fixed]) {
                throw new ContactRejectedException('A field\'s ' . str_replace('_', ' ', $fixed) . ' can\'t change; add a new field instead', 422, ['field' => $fixed]);
            }
        }
        $changes = [];
        if (array_key_exists('label', $input)) {
            $changes['label'] = json_encode(self::label($input['label']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('options', $input)) {
            $changes['options_json'] = json_encode(self::choices((string) $row['kind'], $input['options']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        foreach (['show_in_list' => 'show_in_list', 'required' => 'is_required'] as $flag => $column) {
            if (array_key_exists($flag, $input)) {
                $changes[$column] = self::flag($input, $flag, false);
            }
        }
        if (array_key_exists('edit_permission', $input)) {
            $changes['edit_permission'] = self::permission($input['edit_permission']);
        }
        if (array_key_exists('sort_order', $input)) {
            if (!is_int($input['sort_order']) || $input['sort_order'] < 0) {
                throw new ContactRejectedException('sort_order must be a whole number from 0', 422, ['field' => 'sort_order']);
            }
            $changes['sort_order'] = $input['sort_order'];
        }
        $this->fields->update($tenantId, $id, $changes);

        return $this->show($tenantId, $id);
    }

    /** @return array<string, mixed> */
    public function archive(int $tenantId, int $id, bool $archived): array
    {
        $this->fields->find($tenantId, $id) ?? throw ContactRejectedException::notFound('Field');
        $this->fields->setArchived($tenantId, $id, $archived);

        return $this->show($tenantId, $id);
    }

    /** @return array<string, mixed> */
    public function show(int $tenantId, int $id): array
    {
        $row = $this->fields->find($tenantId, $id) ?? throw ContactRejectedException::notFound('Field');

        return $this->toApi($tenantId, $row);
    }

    /**
     * Values on these records: id => key => value. Only live fields.
     *
     * @param list<int> $recordIds
     * @return array<int, array<string, mixed>>
     */
    public function values(int $tenantId, string $recordType, array $recordIds): array
    {
        return $this->fields->values($tenantId, $recordType, $recordIds);
    }

    /**
     * Writes values on a record. A null value clears a field. On a new record,
     * every required field must have a value.
     *
     * @param mixed $input field key => value
     * @param callable(string): bool $hasPermission the caller's permissions, for fields with their own
     */
    public function write(int $tenantId, string $recordType, int $recordId, mixed $input, callable $hasPermission, bool $isNew): void
    {
        if ($input === null) {
            $input = [];
        }
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new ContactRejectedException('fields must map field keys to values', 422, ['field' => 'fields']);
        }
        $definitions = [];
        foreach ($this->fields->definitions($tenantId, $recordType) as $row) {
            $definitions[(string) $row['field_key']] = $row;
        }
        foreach ($input as $key => $raw) {
            $def = $definitions[(string) $key] ?? null;
            if ($def === null) {
                throw new ContactRejectedException('There is no field "' . $key . '"', 422, ['field' => 'fields.' . $key]);
            }
            if ($def['edit_permission'] !== null && !$hasPermission((string) $def['edit_permission'])) {
                throw new ContactRejectedException('You can\'t change ' . self::labelText($def) . '', 403, ['field' => 'fields.' . $key, 'required' => $def['edit_permission']]);
            }
            if ($raw === null || $raw === '' || $raw === []) {
                if (DbBool::of($def['is_required'])) {
                    throw new ContactRejectedException(self::labelText($def) . ' is required', 422, ['field' => 'fields.' . $key]);
                }
                $this->fields->clearValue($tenantId, (int) $def['id'], $recordId);
                continue;
            }
            $checked = $this->check($tenantId, $def, $raw);
            if (isset($checked['error'])) {
                throw new ContactRejectedException($checked['error'], 422, ['field' => 'fields.' . $key]);
            }
            $this->fields->setValue($tenantId, (int) $def['id'], $recordType, $recordId, $checked['value'], $checked['text']);
        }
        if ($isNew) {
            foreach ($definitions as $key => $def) {
                if (DbBool::of($def['is_required']) && !array_key_exists($key, $input)) {
                    throw new ContactRejectedException(self::labelText($def) . ' is required', 422, ['field' => 'fields.' . $key]);
                }
            }
        }
    }

    /**
     * Writes values that were already checked (by {@see check()}), as an import does.
     *
     * @param array<string, mixed> $values key => value, null to clear
     */
    public function writeChecked(int $tenantId, string $recordType, int $recordId, array $values): void
    {
        $definitions = [];
        foreach ($this->fields->definitions($tenantId, $recordType) as $row) {
            $definitions[(string) $row['field_key']] = $row;
        }
        foreach ($values as $key => $value) {
            $def = $definitions[$key] ?? null;
            if ($def === null) {
                continue;
            }
            if ($value === null) {
                $this->fields->clearValue($tenantId, (int) $def['id'], $recordId);
                continue;
            }
            $this->fields->setValue($tenantId, (int) $def['id'], $recordType, $recordId, $value, self::text((string) $def['kind'], $value));
        }
    }

    public function clearRecord(int $tenantId, string $recordType, int $recordId): void
    {
        $this->fields->clearRecord($tenantId, $recordType, $recordId);
    }

    /**
     * List filters from a query string: `field[<key>]=value` pairs (passed
     * here as key => value) become SQL predicates over the record table alias.
     *
     * @param array<string, string> $query
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    public function filters(int $tenantId, string $recordType, string $alias, array $query): array
    {
        $byKey = [];
        foreach ($this->fields->definitions($tenantId, $recordType) as $row) {
            $byKey[(string) $row['field_key']] = $row;
        }
        $out = [];
        $n = 0;
        foreach ($query as $key => $value) {
            if ($value === '') {
                continue;
            }
            $def = $byKey[$key] ?? throw new ContactRejectedException('There is no field "' . $key . '" to filter on', 422, ['field' => 'field[' . $key . ']']);
            $kind = (string) $def['kind'];
            $match = $kind === FieldType::CHECKBOX ? (in_array(strtolower($value), ['true', '1', 'yes'], true) ? 'Yes' : 'No') : $value;
            $out[] = CustomFieldRepository::filterPredicate($alias, (int) $def['id'], $match, $kind === FieldType::MULTISELECT, 'cf' . $n++);
        }

        return $out;
    }

    /**
     * Checks one value for a field. Text comes as typed from a spreadsheet
     * too, so numbers, dates and yes-or-no read the usual ways of writing them.
     *
     * @param array<string, mixed> $def
     * @return array{value: mixed, text: string}|array{error: string}
     */
    public function check(int $tenantId, array $def, mixed $raw): array
    {
        $kind = (string) $def['kind'];
        $name = self::labelText($def);
        /** @var list<string> $choices */
        $choices = json_decode((string) $def['options_json'], true) ?: [];
        switch ($kind) {
            case FieldType::TEXT:
            case FieldType::TEXTAREA:
                if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
                    return ['error' => $name . ' must be text'];
                }
                $text = trim((string) $raw);
                $max = $kind === FieldType::TEXT ? self::MAX_TEXT : self::MAX_LONG_TEXT;
                if (mb_strlen($text) > $max) {
                    return ['error' => $name . ' is longer than ' . $max . ' characters'];
                }

                return ['value' => $text, 'text' => $text];
            case FieldType::NUMBER:
                $text = is_string($raw) ? str_replace([',', ' '], '', trim($raw)) : $raw;
                if (!is_numeric($text)) {
                    return ['error' => $name . ' must be a number'];
                }
                $number = (string) $text;

                return ['value' => $number, 'text' => $number];
            case FieldType::DATE:
                $date = is_string($raw) ? self::date(trim($raw)) : null;
                if ($date === null) {
                    return ['error' => $name . ' must be a date, such as 2026-11-12'];
                }

                return ['value' => $date, 'text' => $date];
            case FieldType::CHECKBOX:
                $bool = self::yesNo($raw);
                if ($bool === null) {
                    return ['error' => $name . ' must be yes or no'];
                }

                return ['value' => $bool, 'text' => $bool ? 'Yes' : 'No'];
            case FieldType::SELECT:
                $pick = is_string($raw) ? self::choice(trim($raw), $choices) : null;
                if ($pick === null) {
                    return ['error' => $name . ' must be one of: ' . implode(', ', $choices)];
                }

                return ['value' => $pick, 'text' => $pick];
            case FieldType::MULTISELECT:
                $list = is_string($raw) ? (preg_split('/[,;|،]/u', $raw) ?: []) : $raw;
                if (!is_array($list)) {
                    return ['error' => $name . ' must be a list of choices'];
                }
                $picked = [];
                foreach ($list as $item) {
                    if (!is_string($item) || trim($item) === '') {
                        continue;
                    }
                    $pick = self::choice(trim($item), $choices);
                    if ($pick === null) {
                        return ['error' => '"' . trim($item) . '" is not one of the choices for ' . $name];
                    }
                    $picked[$pick] = true;
                }
                $picked = array_values(array_filter($choices, static fn (string $c): bool => isset($picked[$c])));

                return ['value' => $picked, 'text' => "\n" . implode("\n", $picked) . "\n"];
            case FieldType::PROFILE_REF:
                $profileId = is_int($raw) ? $raw : (is_string($raw) && ctype_digit($raw) ? (int) $raw : null);
                if ($profileId === null || !$this->isMember($tenantId, $profileId)) {
                    return ['error' => $name . ' must be someone in this workspace'];
                }

                return ['value' => $profileId, 'text' => (string) $profileId];
        }

        return ['error' => $name . ' can\'t be filled in here'];
    }

    /** The plain text of a value, as a document shows it. */
    public static function text(string $kind, mixed $value): string
    {
        return match (true) {
            $kind === FieldType::MULTISELECT && is_array($value) => "\n" . implode("\n", array_map('strval', $value)) . "\n",
            $kind === FieldType::CHECKBOX => $value === true ? 'Yes' : 'No',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /** @param array<string, mixed> $row */
    public static function labelText(array $row, string $language = 'en'): string
    {
        $label = json_decode((string) $row['label'], true);
        if (!is_array($label)) {
            return (string) $row['field_key'];
        }
        $text = (string) ($label[$language] ?? '');

        return $text !== '' ? $text : (string) ($label['en'] ?? $label['ar'] ?? $row['field_key']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toApi(int $tenantId, array $row): array
    {
        $label = json_decode((string) $row['label'], true);

        return [
            'id' => (int) $row['id'],
            'record_type' => (string) $row['record_type'],
            'key' => (string) $row['field_key'],
            'label' => ['en' => (string) ($label['en'] ?? ''), 'ar' => (string) ($label['ar'] ?? '')],
            'kind' => (string) $row['kind'],
            'options' => json_decode((string) $row['options_json'], true) ?: [],
            'show_in_list' => DbBool::of($row['show_in_list']),
            'required' => DbBool::of($row['is_required']),
            'edit_permission' => $row['edit_permission'] === null ? null : (string) $row['edit_permission'],
            'sort_order' => (int) $row['sort_order'],
            'created_by_profile_id' => $row['created_by_profile_id'] === null ? null : (int) $row['created_by_profile_id'],
            'archived' => $row['archived_at'] !== null,
            'merge_field' => '{{' . self::mergePrefix((string) $row['record_type']) . '.' . $row['field_key'] . '}}',
            'used_by' => $this->fields->usage($tenantId, (int) $row['id']),
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** `contact` merges as `contact.<key>`; a plugin type `front-office.item` as `item.<key>`. */
    public static function mergePrefix(string $recordType): string
    {
        $dot = strrpos($recordType, '.');

        return $dot === false ? $recordType : substr($recordType, $dot + 1);
    }

    /** @return array{en: string, ar: string} */
    private static function label(mixed $label): array
    {
        if (is_string($label)) {
            $label = ['en' => $label];
        }
        if (!is_array($label)) {
            throw new ContactRejectedException('A field needs a name', 422, ['field' => 'label']);
        }
        $en = trim((string) ($label['en'] ?? ''));
        $ar = trim((string) ($label['ar'] ?? ''));
        if ($en === '' && $ar === '') {
            throw new ContactRejectedException('A field needs a name', 422, ['field' => 'label']);
        }
        if (mb_strlen($en) > 100 || mb_strlen($ar) > 100) {
            throw new ContactRejectedException('A field\'s name is at most 100 characters', 422, ['field' => 'label']);
        }

        return ['en' => $en, 'ar' => $ar];
    }

    /** @return list<string> */
    private static function choices(string $kind, mixed $options): array
    {
        if (!in_array($kind, [FieldType::SELECT, FieldType::MULTISELECT], true)) {
            return [];
        }
        if (is_string($options)) {
            $options = preg_split('/\r\n|\r|\n/', $options) ?: [];
        }
        if (!is_array($options)) {
            throw new ContactRejectedException('options must be a list of choices', 422, ['field' => 'options']);
        }
        $out = [];
        foreach ($options as $option) {
            if (!is_string($option) || trim($option) === '') {
                continue;
            }
            $option = mb_substr(trim($option), 0, 100);
            $out[mb_strtolower($option)] = $option;
        }
        if ($out === []) {
            throw new ContactRejectedException('Add at least one choice', 422, ['field' => 'options']);
        }
        if (count($out) > self::MAX_CHOICES) {
            throw new ContactRejectedException('A field can have at most ' . self::MAX_CHOICES . ' choices', 422, ['field' => 'options']);
        }

        return array_values($out);
    }

    /** @param array<string, mixed> $input */
    private static function flag(array $input, string $name, bool $default): bool
    {
        if (!array_key_exists($name, $input)) {
            return $default;
        }
        if (!is_bool($input[$name])) {
            throw new ContactRejectedException($name . ' must be true or false', 422, ['field' => $name]);
        }

        return $input[$name];
    }

    private static function permission(mixed $permission): ?string
    {
        if ($permission === null || $permission === '') {
            return null;
        }
        if (!is_string($permission) || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.-]+$/', $permission) !== 1) {
            throw new ContactRejectedException('edit_permission must be a permission such as contacts:write', 422, ['field' => 'edit_permission']);
        }

        return $permission;
    }

    public static function keyFrom(string $label): string
    {
        $key = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $label), '_'));
        if ($key === '' || !ctype_alpha($key[0])) {
            $key = 'field' . ($key === '' ? '' : '_' . $key);
        }

        return substr($key, 0, 60);
    }

    private static function date(string $raw): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'j/n/Y', 'd.m.Y', 'Y/m/d'] as $format) {
            $d = \DateTimeImmutable::createFromFormat('!' . $format, $raw);
            if ($d !== false && $d->format($format) === $raw) {
                return $d->format('Y-m-d');
            }
        }
        if (is_numeric($raw) && (float) $raw > 20000 && (float) $raw < 80000) {
            // An Excel date serial (days since 1899-12-30).
            return (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int) $raw . ' days')->format('Y-m-d');
        }

        return null;
    }

    private static function yesNo(mixed $raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        $w = is_scalar($raw) ? mb_strtolower(trim((string) $raw)) : '';

        return match ($w) {
            'yes', 'y', 'true', '1', 'نعم' => true,
            'no', 'n', 'false', '0', 'لا' => false,
            default => null,
        };
    }

    /** @param list<string> $choices */
    private static function choice(string $raw, array $choices): ?string
    {
        foreach ($choices as $c) {
            if (mb_strtolower($c) === mb_strtolower($raw)) {
                return $c;
            }
        }

        return null;
    }

    private function isMember(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM memberships WHERE tenant_id = :tenant_id AND profile_id = :profile_id');
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return $stmt->fetchColumn() !== false;
    }
}
