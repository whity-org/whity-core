<?php

declare(strict_types=1);

namespace Whity\Sdk\Database;

use Whity\Sdk\SdkException;

/**
 * {@see WorkspaceTables} refused a call (SDK 1.151, #1693): a table the plugin
 * didn't declare, a column name that isn't plain, a `tenant_id` the plugin
 * tried to set or filter on itself, a write with nothing in it, or no
 * workspace to act in. `reason` says which.
 */
final class TableRefused extends \RuntimeException implements SdkException
{
    public const UNDECLARED_TABLE = 'undeclared_table';
    public const BAD_NAME = 'bad_name';
    public const TENANT_COLUMN = 'tenant_column';
    public const NO_WORKSPACE = 'no_workspace';
    public const EMPTY_WRITE = 'empty_write';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, string $message): self
    {
        return new self($message, $reason);
    }
}
