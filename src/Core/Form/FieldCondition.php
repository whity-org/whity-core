<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * "Show this question only if ..." — the rule on `form_fields.visible_when`
 * (migration 192), and the one reading of it the server trusts.
 *
 * THE SHAPE IS THE BLOCK VOCABULARY'S
 * ------------------------------------
 * `{field, equals}` or `{field, in}`, the same two forms
 * {@see \Whity\Sdk\Frontend\Blocks\BlockContract}'s `visibleWhen` takes for a
 * sibling field, so an author and a client learn one rule. The block facet itself
 * could not do this job: it runs only in the browser, and it shows a block whose
 * field has no value yet. Here an unanswered question does not satisfy its
 * dependant, because an "if yes" question must stay away until somebody says yes.
 *
 * WHY THE SERVER EVALUATES IT
 * ---------------------------
 * A question hidden in the browser and still required on the server is a form
 * nobody can submit, and it looks like a broken form rather than a rule. So a
 * hidden question is not required and whatever was sent for it is not stored: an
 * answer to a question the person was not asked is not something they declared.
 *
 * It is read from the RAW answers, in dependency order rather than form order, so
 * moving a question never changes what is asked. A question whose controller is
 * hidden is hidden too; its old answer does not count.
 *
 * WHEN A RULE CANNOT BE EVALUATED, THE QUESTION IS ASKED
 * ------------------------------------------------------
 * {@see assertAuthorable()} refuses a rule naming a missing field, and a cycle, at
 * the moment an author writes one. If one still reaches this class the question is
 * shown: asking somebody one question too many is visible and they can answer it,
 * while quietly not asking is a gap in the record nobody finds until it matters.
 *
 * `web/components/forms/form-conditions.ts` is the browser's copy of this logic.
 * `tests/Unit/Core/Form/field-condition-vectors.json` runs against both, so the
 * two cannot disagree without a test failing.
 *
 * Stateless — worker-safe.
 */
final class FieldCondition
{
    /** Bounds an `in` list: a rule is one question's condition, not a data set. */
    public const IN_MAX = 100;

    /**
     * Static helper only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Normalize a rule from a request body. Null, or an absent rule, means always
     * shown.
     *
     * @return array{field: string, equals: string|bool|int|float}|array{field: string, in: list<string>}|null
     *
     * @throws FormRejectedException When the rule is not one of the two shapes.
     */
    public static function parse(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw)) {
            throw new FormRejectedException('visible_when must be an object or null');
        }

        $field = $raw['field'] ?? null;
        if (!is_string($field) || preg_match(FormFieldRepository::KEY_PATTERN, $field) !== 1) {
            throw new FormRejectedException('visible_when.field must name a field on this form');
        }

        $hasEquals = array_key_exists('equals', $raw);
        $hasIn = array_key_exists('in', $raw);
        if ($hasEquals === $hasIn) {
            throw new FormRejectedException('visible_when needs exactly one of equals or in');
        }

        if ($hasEquals) {
            $equals = $raw['equals'];
            if ((!is_string($equals) || $equals === '') && !is_bool($equals) && !is_int($equals) && !is_float($equals)) {
                throw new FormRejectedException('visible_when.equals must be text, a number, or true or false');
            }

            return ['field' => $field, 'equals' => $equals];
        }

        $in = $raw['in'];
        if (!is_array($in) || !array_is_list($in) || $in === [] || count($in) > self::IN_MAX) {
            throw new FormRejectedException('visible_when.in must be a list of 1 to ' . self::IN_MAX . ' values');
        }
        $values = [];
        foreach ($in as $one) {
            if (!is_string($one) || $one === '') {
                throw new FormRejectedException('visible_when.in must contain only text values');
            }
            $values[] = $one;
        }

        return ['field' => $field, 'in' => $values];
    }

    /**
     * Refuse a field set in which a rule could not be evaluated: a controller that
     * is missing, is the question itself, can hold no comparable answer, or sits
     * on a cycle. Asked at write time, where it is a sentence an author can act on.
     *
     * @param list<array<string, mixed>> $fields The field set as it would be saved.
     *
     * @throws FormRejectedException Naming the first offending field.
     */
    public static function assertAuthorable(array $fields): void
    {
        $byKey = self::indexByKey($fields);

        foreach ($byKey as $key => $field) {
            $audience = (string) ($field['audience'] ?? FormFieldRepository::AUDIENCE_APPLICANT);

            // Nothing can upload against a review yet, so this would never have an
            // answer, and if required would block every review.
            if ($audience === FormFieldRepository::AUDIENCE_STAFF && (string) ($field['field_type'] ?? '') === FieldType::FILE) {
                throw new FormRejectedException("Staff field '{$key}' cannot be a file question");
            }

            $rule = $field['visible_when'] ?? null;
            if (!is_array($rule)) {
                continue;
            }
            $controller = (string) ($rule['field'] ?? '');
            if ($controller === $key) {
                throw new FormRejectedException("Field '{$key}' cannot depend on its own answer");
            }
            if (!isset($byKey[$controller])) {
                throw new FormRejectedException("Field '{$key}' depends on '{$controller}', which is not on this form");
            }
            // The two are answered at different times by different people, so one
            // could never read the other's answer when it is decided.
            if ((string) ($byKey[$controller]['audience'] ?? FormFieldRepository::AUDIENCE_APPLICANT) !== $audience) {
                throw new FormRejectedException("Field '{$key}' depends on '{$controller}', which is answered by another audience");
            }
            $controllerType = (string) ($byKey[$controller]['field_type'] ?? '');
            if (in_array($controllerType, [FieldType::FILE, FieldType::REPEATING, FieldType::SIGNATURE], true)) {
                throw new FormRejectedException("Field '{$key}' cannot depend on a {$controllerType} question");
            }

            $seen = [$key => true];
            $next = $controller;
            while (isset($byKey[$next])) {
                if (isset($seen[$next])) {
                    throw new FormRejectedException("Field '{$key}' is part of a loop of conditions");
                }
                $seen[$next] = true;
                $nextRule = $byKey[$next]['visible_when'] ?? null;
                if (!is_array($nextRule)) {
                    break;
                }
                $next = (string) ($nextRule['field'] ?? '');
            }
        }
    }

    /**
     * The keys of fields whose rule names `$fieldKey`, so deleting that field can
     * be refused instead of leaving their rules pointing at nothing.
     *
     * @param list<array<string, mixed>> $fields
     * @return list<string>
     */
    public static function dependantsOf(array $fields, string $fieldKey): array
    {
        $out = [];
        foreach ($fields as $field) {
            $rule = $field['visible_when'] ?? null;
            if (is_array($rule) && ($rule['field'] ?? null) === $fieldKey) {
                $out[] = (string) ($field['field_key'] ?? '');
            }
        }

        return $out;
    }

    /**
     * Which questions are asked, given these answers.
     *
     * @param list<array<string, mixed>> $fields Normalized `form_fields` rows.
     * @param array<string, mixed>       $data   The RAW submitted answers.
     *
     * @return array<string, bool> Every field key, true when the question is asked.
     */
    public static function visibility(array $fields, array $data): array
    {
        $byKey = self::indexByKey($fields);
        $resolved = [];

        foreach (array_keys($byKey) as $key) {
            self::resolve((string) $key, $byKey, $data, $resolved, []);
        }

        return $resolved;
    }

    /**
     * @param array<string, array<string, mixed>> $byKey
     * @param array<string, mixed>                $data
     * @param array<string, bool>                 $resolved
     * @param array<string, true>                 $visiting
     */
    private static function resolve(string $key, array $byKey, array $data, array &$resolved, array $visiting): bool
    {
        if (isset($resolved[$key])) {
            return $resolved[$key];
        }

        $rule = $byKey[$key]['visible_when'] ?? null;
        if (!is_array($rule)) {
            return $resolved[$key] = true;
        }

        $controller = (string) ($rule['field'] ?? '');
        // See the class docblock: a rule that cannot be evaluated asks the question.
        if (!isset($byKey[$controller]) || isset($visiting[$key]) || isset($visiting[$controller])) {
            return $resolved[$key] = true;
        }

        $visiting[$key] = true;
        if (!self::resolve($controller, $byKey, $data, $resolved, $visiting)) {
            return $resolved[$key] = false;
        }

        $answer = self::operands((string) ($byKey[$controller]['field_type'] ?? ''), $data[$controller] ?? null);

        return $resolved[$key] = self::matches($rule, $answer);
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<string>         $answer
     */
    private static function matches(array $rule, array $answer): bool
    {
        if (array_key_exists('equals', $rule)) {
            return in_array(self::operand($rule['equals']), $answer, true);
        }

        $in = is_array($rule['in'] ?? null) ? $rule['in'] : [];
        foreach ($in as $wanted) {
            if (in_array(self::operand($wanted), $answer, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An answer as the strings a rule is compared with. A checkbox nobody touched
     * is `false`, because that is what an unticked box says; any other unanswered
     * question has no value, so no rule matches it.
     *
     * @return list<string>
     */
    private static function operands(string $type, mixed $raw): array
    {
        if ($type === FieldType::CHECKBOX) {
            return [$raw === true || $raw === 1 || $raw === 'true' || $raw === '1' ? 'true' : 'false'];
        }
        if (is_array($raw)) {
            $values = [];
            foreach ($raw as $one) {
                $value = self::operand($one);
                if ($value !== '') {
                    $values[] = $value;
                }
            }

            return $values;
        }
        $value = self::operand($raw);

        return $value === '' ? [] : [$value];
    }

    private static function operand(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private static function indexByKey(array $fields): array
    {
        $byKey = [];
        foreach ($fields as $field) {
            $key = (string) ($field['field_key'] ?? '');
            if ($key !== '') {
                $byKey[$key] = $field;
            }
        }

        return $byKey;
    }
}
