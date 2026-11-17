<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use InvalidArgumentException;

/**
 * The short values a step asks the person acting on it for (WHIT-1016): a
 * committee meeting number, a file number, a date from a paper register.
 *
 * A step declares its inputs as a list of
 * `{key, label, label_ar?, required?, max_length?}`. The person who approves
 * at that step fills them in, the values are kept on the route
 * ({@see RouteStepValueRepository}), and a template prints them through
 * `route.value.<key>` ({@see \Whity\Core\Document\Fields\RouteValueFieldProvider}).
 *
 * WHEN THEY ARE ASKED FOR. Only an act that moves the step forward carries
 * values: an approval on a decision step, or acknowledging or forwarding on
 * any other step people act on. A rejection, a send-back, a return or a note
 * carries none, so the value on the route is always one a person entered
 * while letting the item through. Each person who approves enters them:
 * on a step reaching several people the latest entry is the route's value.
 *
 * WHERE THEY MAY BE DECLARED. Not on a delivery step (nobody acts there), a
 * final document step (nobody acts there either), or a signing step (a
 * signature is made through signing, which has no place for them).
 *
 * Stateless — worker-safe. Every refusal is an {@see InvalidArgumentException}
 * whose message the author reads; callers wrap it in their own exception.
 */
final class RouteStepInputs
{
    /** At most this many inputs on one step: they are for a few short values, not a second form. */
    public const MAX_INPUTS = 5;

    /** A value's longest allowed length, and the default when a step sets none. */
    public const MAX_LENGTH = 200;

    public const DEFAULT_LENGTH = 100;

    public const LABEL_MAX = 80;

    private const KEY = '/^[a-z][a-z0-9_]{0,39}$/';

    /** A line of text: a meeting number, a reference. */
    public const TYPE_TEXT = 'text';

    /**
     * A person in the workspace, stored as their user id (merge map A3). The
     * office names a form's supervisor this way at pre-check, and a later step's
     * recusal reads it ({@see RouteStepExclusions}).
     */
    public const TYPE_PERSON = 'person';

    /**
     * One of a list the step defines: a recommendation, or a decision such as
     * Approved, Conditionally approved, Revisions required, Not approved.
     * Stored as the option's value; printed as its label.
     */
    public const TYPE_CHOICE = 'choice';

    public const TYPES = [self::TYPE_TEXT, self::TYPE_PERSON, self::TYPE_CHOICE];

    /** A choice offers 2 to this many options. */
    public const MAX_OPTIONS = 12;

    private const OPTION_VALUE = '/^[a-z][a-z0-9_]{0,39}$/';

    /**
     * Static helper only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Validate a step's declared inputs from a request body.
     *
     * @return list<array{key: string, label: string, label_ar: ?string, required: bool, max_length: int, type: string, options?: list<array{value: string, label: string, label_ar: ?string}>}>
     *
     * @throws InvalidArgumentException With a sentence naming what is wrong.
     */
    public static function parse(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || ($raw !== [] && !array_is_list($raw))) {
            throw new InvalidArgumentException("'inputs' must be a list of the values this step asks for.");
        }
        if (count($raw) > self::MAX_INPUTS) {
            throw new InvalidArgumentException('A step can ask for at most ' . self::MAX_INPUTS . ' values.');
        }

        $out = [];
        $seen = [];
        foreach ($raw as $index => $input) {
            $nth = $index + 1;
            if (!is_array($input) || ($input !== [] && array_is_list($input))) {
                throw new InvalidArgumentException("Value {$nth}: each value this step asks for must be an object.");
            }
            $key = $input['key'] ?? null;
            if (!is_string($key) || preg_match(self::KEY, $key) !== 1) {
                throw new InvalidArgumentException(
                    "Value {$nth}: 'key' must start with a small letter and use only small letters, digits and _, "
                    . 'up to 40 characters. Templates print it as route.value.<key>.'
                );
            }
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Two values on this step use the key '{$key}'.");
            }
            $seen[$key] = true;

            $label = self::text($input['label'] ?? null);
            if ($label === null) {
                throw new InvalidArgumentException("Value {$nth}: 'label' is required. It is what the person filling it in reads.");
            }
            $labelAr = self::text($input['label_ar'] ?? null);
            if (($input['label_ar'] ?? null) !== null && $labelAr === null && !is_string($input['label_ar'])) {
                throw new InvalidArgumentException("Value {$nth}: 'label_ar' must be text.");
            }

            $required = $input['required'] ?? true;
            if (!is_bool($required)) {
                throw new InvalidArgumentException("Value {$nth}: 'required' must be true or false.");
            }
            $maxLength = $input['max_length'] ?? self::DEFAULT_LENGTH;
            if (!is_int($maxLength) || $maxLength < 1 || $maxLength > self::MAX_LENGTH) {
                throw new InvalidArgumentException(
                    "Value {$nth}: 'max_length' must be a whole number from 1 to " . self::MAX_LENGTH . '.'
                );
            }
            $type = $input['type'] ?? self::TYPE_TEXT;
            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException("Value {$nth}: 'type' is text, person or choice.");
            }
            $options = $type === self::TYPE_CHOICE ? self::options($input['options'] ?? null, $nth) : null;

            $out[] = [
                'key' => $key,
                'label' => $label,
                'label_ar' => $labelAr,
                'required' => $required,
                'max_length' => $maxLength,
                'type' => $type,
            ] + ($options !== null ? ['options' => $options] : []);
        }

        return $out;
    }

    /**
     * A choice's options: 2 to {@see MAX_OPTIONS}, each a stable value and a
     * label in English and, optionally, Arabic.
     *
     * @return list<array{value: string, label: string, label_ar: ?string}>
     *
     * @throws InvalidArgumentException
     */
    private static function options(mixed $raw, int $nth): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) < 2 || count($raw) > self::MAX_OPTIONS) {
            throw new InvalidArgumentException("Value {$nth}: a choice needs 2 to " . self::MAX_OPTIONS . ' options.');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $option) {
            $value = is_array($option) ? ($option['value'] ?? null) : null;
            $label = is_array($option) ? self::text($option['label'] ?? null) : null;
            if (!is_string($value) || preg_match(self::OPTION_VALUE, $value) !== 1 || $label === null) {
                throw new InvalidArgumentException(
                    "Value {$nth}: each option needs a 'value' (small letters, digits and _) and a 'label'."
                );
            }
            if (isset($seen[$value])) {
                throw new InvalidArgumentException("Value {$nth}: two options use the value '{$value}'.");
            }
            $seen[$value] = true;
            $out[] = ['value' => $value, 'label' => $label, 'label_ar' => self::text($option['label_ar'] ?? null)];
        }

        return $out;
    }

    /**
     * What a stored value reads as: a choice's option label (Arabic when asked
     * and present), anything else as it was entered.
     *
     * @param array<string, mixed> $input One step input, as {@see decode()} returns it.
     */
    public static function display(array $input, string $value, string $lang = 'en'): string
    {
        if (($input['type'] ?? self::TYPE_TEXT) !== self::TYPE_CHOICE) {
            return $value;
        }
        foreach (is_array($input['options'] ?? null) ? $input['options'] : [] as $option) {
            if (($option['value'] ?? null) === $value) {
                return $lang === 'ar' && is_string($option['label_ar'] ?? null) && $option['label_ar'] !== '' ? $option['label_ar'] : (string) $option['label'];
            }
        }

        return $value;
    }

    /**
     * Refuse inputs on a step nobody acts on in a way that could carry them.
     *
     * @param list<array<string, mixed>> $inputs
     *
     * @throws InvalidArgumentException
     */
    public static function assertAllowed(array $inputs, string $satisfiedBy, ?string $kind): void
    {
        if ($inputs === []) {
            return;
        }
        if ($satisfiedBy === RouteSatisfaction::DELIVERY) {
            throw new InvalidArgumentException(
                'A stage that only tells people cannot ask them for values: nobody acts on it.'
            );
        }
        if ($kind === RouteStepKind::SIGN || $kind === RouteStepKind::FINALISE) {
            throw new InvalidArgumentException(
                'Signing and final document stages cannot ask for values. Ask for them on the stage before.'
            );
        }
    }

    /**
     * The stored form: JSON, or null for a step that asks for nothing.
     *
     * @param list<array<string, mixed>> $inputs
     */
    public static function encode(array $inputs): ?string
    {
        return $inputs === [] ? null : (string) json_encode($inputs, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Read a stored column. A value that will not decode reads as no inputs,
     * the safe direction: the step then asks for nothing rather than for
     * something nobody can see.
     *
     * @return list<array{key: string, label: string, label_ar: ?string, required: bool, max_length: int, type: string, options?: list<array{value: string, label: string, label_ar: ?string}>}>
     */
    public static function decode(mixed $stored): array
    {
        if (is_array($stored)) {
            $decoded = $stored;
        } elseif (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
        } else {
            return [];
        }
        try {
            return is_array($decoded) ? self::parse(array_values($decoded)) : [];
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /**
     * Check the values sent with an act against what the step asks for.
     *
     * @param list<array{key: string, label: string, label_ar: ?string, required: bool, max_length: int, type: string, options?: list<array{value: string, label: string, label_ar: ?string}>}> $inputs
     * @param array<string, string> $values Already shaped by {@see parseValues()}.
     * @return array<string, string> The values to keep, blanks dropped.
     *
     * @throws InvalidArgumentException
     */
    public static function accept(array $inputs, array $values): array
    {
        $byKey = [];
        foreach ($inputs as $input) {
            $byKey[$input['key']] = $input;
        }
        foreach (array_keys($values) as $key) {
            if (!isset($byKey[$key])) {
                throw new InvalidArgumentException("This step does not ask for '{$key}'.");
            }
        }

        $out = [];
        foreach ($inputs as $input) {
            $value = trim($values[$input['key']] ?? '');
            if ($value === '') {
                if ($input['required']) {
                    throw new InvalidArgumentException("Fill in “{$input['label']}” before you approve.");
                }
                continue;
            }
            if ($input['type'] === self::TYPE_CHOICE) {
                if (!in_array($value, array_column($input['options'] ?? [], 'value'), true)) {
                    throw new InvalidArgumentException("Choose one of the options for “{$input['label']}”.");
                }
            } elseif ($input['type'] === self::TYPE_PERSON) {
                // A person is their user id. Whether they belong to this
                // workspace is the router's check, which can read it.
                if (preg_match('/^[1-9][0-9]{0,18}$/', $value) !== 1) {
                    throw new InvalidArgumentException("Choose a person for “{$input['label']}”.");
                }
            } elseif (mb_strlen($value) > $input['max_length']) {
                throw new InvalidArgumentException(
                    "“{$input['label']}” must be {$input['max_length']} characters or fewer."
                );
            }
            $out[$input['key']] = $value;
        }

        return $out;
    }

    /**
     * Shape the `values` object of an act's request body.
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException
     */
    public static function parseValues(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new InvalidArgumentException("'values' must be an object of the values this step asks for.");
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException("'values.{$key}' must be text.");
            }
            // Control characters break every letter and register a value is
            // copied into; one line of plain text, as a reference format is.
            if (preg_match('/[\p{Cc}]/u', $value) !== 0) {
                throw new InvalidArgumentException("'values.{$key}' must be one line of plain text.");
            }
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > self::LABEL_MAX || preg_match('/[\p{Cc}]/u', $trimmed) !== 0) {
            throw new InvalidArgumentException('A label must be one line of ' . self::LABEL_MAX . ' characters or fewer.');
        }

        return $trimmed;
    }
}
