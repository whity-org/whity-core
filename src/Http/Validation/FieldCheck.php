<?php

declare(strict_types=1);

namespace Whity\Http\Validation;

use Whity\Core\Response;

/**
 * What {@see FieldValidator::check()} found: the cleaned values, and the
 * problems in the order the fields were declared.
 */
final class FieldCheck
{
    /**
     * @param array<string, string> $values
     * @param array<string, array{code: string, en: string, ar: string}> $problems
     */
    public function __construct(
        private readonly array $values,
        private readonly array $problems,
    ) {
    }

    public function ok(): bool
    {
        return $this->problems === [];
    }

    /** The cleaned value (trimmed, emails lower-cased), '' when absent. */
    public function value(string $field): string
    {
        return $this->values[$field] ?? '';
    }

    /** The first field with a problem, in the order declared, or null. */
    public function first(): ?string
    {
        $first = array_key_first($this->problems);

        return $first === null ? null : (string) $first;
    }

    public function has(string $field): bool
    {
        return isset($this->problems[$field]);
    }

    /** The problem code for one field, or null. */
    public function code(string $field): ?string
    {
        return $this->problems[$field]['code'] ?? null;
    }

    /** @return array<string, array{code: string, en: string, ar: string}> */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The shared 422: the handler's own sentence and detail keys first, then
     * every field's problem in English and Arabic, with its code.
     *
     * @param array<string, mixed> $details
     */
    public function refusal(string $message, array $details = []): Response
    {
        return FieldValidator::refusal($message, $this->problems, $details);
    }
}
