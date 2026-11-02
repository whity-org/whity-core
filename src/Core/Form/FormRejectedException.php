<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * A form, field or submission was refused for a reason the CALLER can act on.
 *
 * TWO MESSAGES, ONE EXCEPTION — AND WHY THE SPLIT IS A FIELD RATHER THAN A
 * HABIT
 * -----------------------------------------------------------------------
 * `$clientMessage` is written FOR the person on the other end of the request and
 * is safe to return verbatim. `getMessage()` is written for whoever is reading
 * the logs and may name a column, a row id, or a driver error.
 *
 * The two are separate FIELDS rather than a convention about how carefully to
 * word `getMessage()` for exactly the reason WC-186 records and
 * {@see \Whity\Core\TimeWindow\WindowRejectedException} restates: a convention is
 * kept by whoever remembers it, and the one time somebody forgets, an internal
 * detail is returned to an unauthenticated caller in a 422 body. A field cannot
 * be forgotten — a handler that returns `->getMessage()` is visibly doing
 * something different from every other catch site in the file.
 *
 * Every catch site in this subsystem returns `->clientMessage`, never
 * `->getMessage()`.
 *
 * `$reason`, `$fieldKey` AND `$params` ARE THE TRANSLATABLE HALF. `$clientMessage`
 * is English, so a page in Arabic cannot show it. A refusal about one answer also
 * says which question and why, in a stable code, and the page words it in the
 * reader's language and takes them to that question. {@see details()} is what goes
 * on the wire; the sentence stays for callers that have no words of their own.
 */
final class FormRejectedException extends \RuntimeException
{
    /**
     * @param string $clientMessage Text written for the caller, safe to return.
     * @param string $logMessage    Text written for the operator; defaults to the
     *                              client message when there is nothing extra to
     *                              say, so the common case stays a one-argument
     *                              call.
     * @param string|null $reason   A stable code, e.g. `required`.
     * @param string|null $fieldKey The question this is about, when there is one.
     * @param array<string, int|float|string> $params Numbers the wording needs.
     */
    public function __construct(
        public readonly string $clientMessage,
        string $logMessage = '',
        ?\Throwable $previous = null,
        public readonly ?string $reason = null,
        public readonly ?string $fieldKey = null,
        public readonly array $params = [],
    ) {
        parent::__construct($logMessage !== '' ? $logMessage : $clientMessage, 0, $previous);
    }

    /**
     * The `details` of a 422 response. Empty when there is no code, so a refusal
     * that has none looks as it always did.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        if ($this->reason === null) {
            return [];
        }

        return array_filter([
            'reason' => $this->reason,
            'field_key' => $this->fieldKey,
            'params' => $this->params === [] ? null : $this->params,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
