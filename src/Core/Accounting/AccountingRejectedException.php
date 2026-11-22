<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

/**
 * Something the caller can fix in an accounting request (WHIT-1318). The
 * handler answers `clientMessage` with `status`; `extra` goes into the error's
 * details (`field`, `code`). Never carries an internal error.
 *
 * Codes the SDK turns into reasons: `unbalanced`, `period_closed`, `vetoed`.
 */
final class AccountingRejectedException extends \RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly string $clientMessage,
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($clientMessage);
    }

    public static function notFound(string $what): self
    {
        return new self($what . ' not found', 404);
    }

    public static function field(string $field, string $message, int $status = 422): self
    {
        return new self($message, $status, ['field' => $field]);
    }

    public static function coded(string $code, string $message, int $status = 422, ?string $field = null): self
    {
        return new self($message, $status, $field === null ? ['code' => $code] : ['code' => $code, 'field' => $field]);
    }
}
