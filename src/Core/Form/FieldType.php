<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * The kinds of field a form may carry (migrations 127 and 197).
 *
 * A CLOSED vocabulary, and deliberately a small one. Every entry here is
 * something a renderer has to draw, a validator has to check and a submission
 * has to store, so each new kind is three implementations rather than one
 * constant — and a kind that exists in the whitelist but is drawn by nobody is
 * an author picking a field that silently renders as text.
 *
 * The list is mirrored by a CHECK constraint on `form_fields.field_type`. That
 * duplication is real and is checked rather than trusted:
 * {@see \Tests\Unit\Core\Form\FieldTypeTest} reads migration 197 and fails the
 * moment the two disagree, the same way
 * {@see \Tests\Unit\Core\Document\RouteTemplate\RouteTemplateVocabularyTest}
 * polices the routing vocabulary across two migrations.
 *
 * WHY `profile_ref` AND `ou_ref` ARE FIELD KINDS RATHER THAN A `select` WITH
 * OPTIONS
 * -------------------------------------------------------------------------
 * Because their choices are not authored, they are RESOLVED — from the tenant's
 * live people and units, at the moment somebody opens the form. An author who
 * pasted today's roster into a `select` would be shipping a list that is wrong
 * by the end of the month, still renders, and still reports success. That is the
 * same argument {@see \Whity\Core\Document\Routing\RouteQuorum} and migration
 * 120 make about naming a rule instead of a roster, applied to a picker.
 *
 * Stateless — worker-safe.
 */
final class FieldType
{
    public const TEXT = 'text';
    public const TEXTAREA = 'textarea';
    public const NUMBER = 'number';
    public const DATE = 'date';
    public const SELECT = 'select';
    public const MULTISELECT = 'multiselect';
    public const CHECKBOX = 'checkbox';
    public const FILE = 'file';
    public const PROFILE_REF = 'profile_ref';
    public const OU_REF = 'ou_ref';
    /** A table the person adds rows to. Its columns are in `validation.columns`. */
    public const REPEATING = 'repeating';
    /** A typed full name, stored with the time the server received it. */
    public const SIGNATURE = 'signature';

    /**
     * What a repeating question's column may be: the kinds a table cell can draw
     * and check on its own. No files or unit pickers in a cell, and no table
     * inside a table.
     *
     * A PERSON is allowed (WHIT-1162): one profile id is a single value a row can
     * hold, and a list of people (students, co-researchers) is exactly what a
     * table is for. A person cell is checked like a top-level `profile_ref`
     * answer, in the caller's tenant, and a step's `exclude_named_in` naming the
     * table leaves everyone in its person cells out.
     *
     * @var list<string>
     */
    public const COLUMN_KINDS = [self::TEXT, self::TEXTAREA, self::NUMBER, self::DATE, self::SELECT, self::CHECKBOX, self::PROFILE_REF];

    /**
     * The kinds whose choices the AUTHOR writes down, and which therefore
     * require a non-empty `options` list.
     *
     * `profile_ref` and `ou_ref` are absent on purpose — see the class docblock.
     * Their choices come from the tenant's live data, so an author supplying
     * options for one is describing a picker the renderer will not build.
     *
     * @var list<string>
     */
    private const OPTION_BEARING = [self::SELECT, self::MULTISELECT];

    /**
     * The kinds that accept MORE THAN ONE value, and whose submitted answer is
     * therefore a list rather than a scalar.
     *
     * @var list<string>
     */
    private const MULTI_VALUED = [self::MULTISELECT, self::REPEATING];

    /**
     * Static vocabulary only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Every kind, in the order a builder's picker should offer them: the
     * everyday kinds first, the reference pickers last.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::TEXT,
            self::TEXTAREA,
            self::NUMBER,
            self::DATE,
            self::SELECT,
            self::MULTISELECT,
            self::CHECKBOX,
            self::FILE,
            self::PROFILE_REF,
            self::OU_REF,
            self::REPEATING,
            self::SIGNATURE,
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    /**
     * Whether this kind needs the author to supply a non-empty `options` list.
     */
    public static function requiresOptions(string $type): bool
    {
        return in_array($type, self::OPTION_BEARING, true);
    }

    /**
     * Whether a submitted answer for this kind is a LIST of values.
     *
     * Asked by the validator before it decides whether an array is an answer or
     * a malformed one. Getting this from a single declaration rather than from
     * an `if ($type === 'multiselect')` at each site is what keeps the answer the
     * same in the validator, the presenter and any future exporter.
     */
    public static function isMultiValued(string $type): bool
    {
        return in_array($type, self::MULTI_VALUED, true);
    }

    /**
     * Whether this kind's answer is a reference to a row the platform owns —
     * a profile id or an organizational-unit id — rather than a literal a person
     * typed.
     *
     * The distinction matters on submit: a reference is validated by looking the
     * row up IN THE CALLER'S TENANT, so a submitted id naming a profile in some
     * other tenant is rejected as absent rather than stored as a cross-tenant
     * pointer. A literal has nothing to look up.
     */
    public static function isReference(string $type): bool
    {
        return $type === self::PROFILE_REF || $type === self::OU_REF;
    }

    /**
     * A repeating question's columns, as `{key, type}` pairs, whichever shape its
     * `validation` arrives in (an array, or the `\stdClass` an empty rule set is
     * read as). Any other kind of question has none.
     *
     * @param array<string, mixed> $field
     * @return list<array{key: string, type: string}>
     */
    public static function columnsOf(array $field): array
    {
        if (($field['field_type'] ?? null) !== self::REPEATING) {
            return [];
        }
        $rules = $field['validation'] ?? null;
        if ($rules instanceof \stdClass) {
            $rules = get_object_vars($rules);
        }
        $columns = is_array($rules) && is_array($rules['columns'] ?? null) ? $rules['columns'] : [];

        $out = [];
        foreach ($columns as $column) {
            if (is_array($column) && is_string($column['key'] ?? null) && $column['key'] !== '') {
                $out[] = ['key' => $column['key'], 'type' => is_string($column['type'] ?? null) ? $column['type'] : self::TEXT];
            }
        }

        return $out;
    }

    /**
     * Whether a question's answer holds a reference anywhere: it is a reference
     * itself, or it is a table with a reference column.
     *
     * @param array<string, mixed> $field
     */
    public static function holdsReference(array $field): bool
    {
        if (self::isReference((string) ($field['field_type'] ?? ''))) {
            return true;
        }
        foreach (self::columnsOf($field) as $column) {
            if (self::isReference($column['type'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The profile ids a question's answer names: a `profile_ref` answer, or the
     * person cells of a table's rows (WHIT-1162). Each once, in the order given.
     *
     * @param array<string, mixed> $field
     * @return list<int>
     */
    public static function peopleIn(array $field, mixed $answer): array
    {
        $ids = [];
        $add = static function (mixed $value) use (&$ids): void {
            if (is_int($value) && $value > 0) {
                $ids[$value] = $value;
            } elseif (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                $ids[(int) $value] = (int) $value;
            }
        };

        $type = (string) ($field['field_type'] ?? '');
        if ($type === self::PROFILE_REF) {
            foreach (is_array($answer) ? $answer : [$answer] as $value) {
                $add($value);
            }
        } elseif ($type === self::REPEATING && is_array($answer)) {
            $people = array_column(array_filter(self::columnsOf($field), static fn (array $c): bool => $c['type'] === self::PROFILE_REF), 'key');
            foreach ($answer as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($people as $key) {
                    $add($row[$key] ?? null);
                }
            }
        }

        return array_values($ids);
    }
}
