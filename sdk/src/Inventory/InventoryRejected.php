<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

use Whity\Sdk\SdkException;

/**
 * Something a caller of an inventory host service can fix (SDK 1.127,
 * WHIT-1232): a missing name, an unknown place, a quantity that isn't one, a
 * person without the permission, a call made outside any workspace.
 *
 * Branch on `reason`, which is stable; the message is for people. `status`
 * is what the matching route answers (403, 404, 409, 422) and `field` names
 * the input at fault when there is one.
 */
final class InventoryRejected extends \RuntimeException implements SdkException
{
    /** The call runs in no workspace (a job or CLI with none set). */
    public const NO_WORKSPACE = 'no_workspace';
    /** The acting person is not in the workspace or lacks the permission, or the plugin was never loaded. */
    public const FORBIDDEN = 'forbidden';
    /** No such item, place or stock change in this workspace. */
    public const NOT_FOUND = 'not_found';
    /** An input that doesn't look right; see `field`. */
    public const INVALID = 'invalid';
    /** A duplicate SKU or barcode, or a change already undone. */
    public const CONFLICT = 'conflict';
    /** A plugin wrote stock through the container's `Stock`; use `$ctx->inventory()->stock()`. */
    public const USE_PLUGIN_CONTEXT = 'use_plugin_context';
    /** A `stock.moving` listener refused the post. */
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
