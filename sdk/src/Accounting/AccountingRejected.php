<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

use Whity\Sdk\SdkException;

/**
 * Something a caller of an accounting host service can fix (SDK 1.131,
 * WHIT-1318): an entry that doesn't balance, a date in a closed month, an
 * account that isn't in the chart, a person without the permission, a call
 * made outside any workspace.
 *
 * Branch on `reason`, which is stable; the message is for people. `status`
 * is what the matching route answers (403, 404, 409, 422) and `field` names
 * the input at fault when there is one.
 */
final class AccountingRejected extends \RuntimeException implements SdkException
{
    /** The call runs in no workspace (a job or CLI with none set). */
    public const NO_WORKSPACE = 'no_workspace';
    /** The acting person is not in the workspace or lacks the permission, or the plugin was never loaded. */
    public const FORBIDDEN = 'forbidden';
    /** No such account, entry or period in this workspace. */
    public const NOT_FOUND = 'not_found';
    /** An input that doesn't look right; see `field`. */
    public const INVALID = 'invalid';
    /** Debits and credits differ. */
    public const UNBALANCED = 'unbalanced';
    /** The date is in a closed or locked month, or on or before the lock date. */
    public const PERIOD_CLOSED = 'period_closed';
    /** A duplicate account code, or an entry already reversed. */
    public const CONFLICT = 'conflict';
    /** A plugin wrote through the container's service; use `$ctx->accounting()`. */
    public const USE_PLUGIN_CONTEXT = 'use_plugin_context';
    /** An `accounting.entry.posting` listener refused the entry. */
    public const VETOED = 'vetoed';

    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly ?string $field = null,
        public readonly string $reason = self::INVALID,
    ) {
        parent::__construct($message, $status);
    }
}
