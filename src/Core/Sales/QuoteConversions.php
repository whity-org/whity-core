<?php

declare(strict_types=1);

namespace Whity\Core\Sales;

use Whity\Sdk\Contribute\WorkspaceQuoteConversion;

/**
 * The targets an accepted quote can turn into (WHIT-1321, design section 34
 * §3.3, register QT.6). Core's inventory registers `sales_order` and core's
 * accounting `invoice` when they ship; plugins add theirs through
 * {@see \Whity\Sdk\Contribute\WorkspaceQuoteConversions}. The first to claim a
 * key keeps it.
 */
final class QuoteConversions
{
    /** @var array<string, array{target: WorkspaceQuoteConversion, source: string}> */
    private array $targets = [];

    /** @return bool false when the key is taken or malformed; the target is then dropped */
    public function register(WorkspaceQuoteConversion $target, string $source = 'core'): bool
    {
        $key = $target->key();
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)?$/', $key) !== 1 || strlen($key) > 60) {
            error_log('[QuoteConversions] ' . $source . ' offered a target with a malformed key; dropped');

            return false;
        }
        if (isset($this->targets[$key])) {
            error_log('[QuoteConversions] ' . $source . ' offered "' . $key . '", already taken by ' . $this->targets[$key]['source'] . '; dropped');

            return false;
        }
        $this->targets[$key] = ['target' => $target, 'source' => $source];

        return true;
    }

    public function get(string $key): ?WorkspaceQuoteConversion
    {
        return $this->targets[$key]['target'] ?? null;
    }

    /** @return array<string, WorkspaceQuoteConversion> by key, in the order they were registered */
    public function all(): array
    {
        return array_map(static fn (array $t): WorkspaceQuoteConversion => $t['target'], $this->targets);
    }
}
