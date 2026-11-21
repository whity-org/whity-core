<?php

declare(strict_types=1);

namespace Whity\Core\Usage;

use Whity\Core\Entitlement\EntitlementRegistry;

/**
 * The meters the usage ledger keeps (business model, section 33 §5, BM.17).
 *
 * Each meter has a ledger name, the entitlement its Free base and packs are
 * set on, and how it is kept: COUNTED meters are added to as things happen;
 * SNAPSHOT meters are a live total read once a day (and whenever the usage
 * screen is opened), with the month's highest kept beside the latest.
 *
 * The order here is the order the usage screen shows them in.
 */
final class UsageMeters
{
    public const SEATS = 'seats';
    public const CUSTOMERS = 'customers';
    public const ITEMS = 'inventory.items';
    public const INVOICES = 'invoices';
    public const STORAGE = 'storage.bytes';
    public const MESSAGES = 'messages.outbound';
    public const AGENTS = 'agents';
    public const API_CALLS = 'api.calls';

    public const KIND_COUNTED = 'counted';
    public const KIND_SNAPSHOT = 'snapshot';

    // Dimensions of the agents meter (BM.17 Q8). Runs and tool calls are always
    // counted; tokens only when Whity supplies the model.
    public const AGENT_RUNS = 'runs';
    public const AGENT_TOOL_CALLS = 'tool_calls';
    public const AGENT_TOKENS_IN = 'tokens_in';
    public const AGENT_TOKENS_OUT = 'tokens_out';

    // Dimensions of the API meter.
    public const CALLS_API = 'api';
    public const CALLS_MCP = 'mcp';

    /**
     * meter => [entitlement key, kind, which dimension the base is measured on
     * ('' = the sum of every dimension)].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const METERS = [
        self::SEATS     => [EntitlementRegistry::MEMBERS_MAX, self::KIND_SNAPSHOT, ''],
        self::CUSTOMERS => [EntitlementRegistry::CUSTOMERS_PER_MONTH, self::KIND_SNAPSHOT, ''],
        self::ITEMS     => [EntitlementRegistry::INVENTORY_ITEMS_MAX, self::KIND_SNAPSHOT, ''],
        self::INVOICES  => [EntitlementRegistry::INVOICES_PER_MONTH, self::KIND_SNAPSHOT, ''],
        self::STORAGE   => [EntitlementRegistry::STORAGE_QUOTA_BYTES, self::KIND_SNAPSHOT, ''],
        self::MESSAGES  => [EntitlementRegistry::MESSAGES_OUTBOUND_PER_MONTH, self::KIND_COUNTED, ''],
        // The allowance is on runs; tool calls and tokens are shown beside it.
        self::AGENTS    => [EntitlementRegistry::AGENTS_RUNS_PER_MONTH, self::KIND_COUNTED, self::AGENT_RUNS],
        self::API_CALLS => [EntitlementRegistry::API_CALLS_PER_MONTH, self::KIND_COUNTED, ''],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::METERS);
    }

    public static function isKnown(string $meter): bool
    {
        return isset(self::METERS[$meter]);
    }

    public static function entitlementFor(string $meter): string
    {
        return self::definition($meter)[0];
    }

    public static function kindOf(string $meter): string
    {
        return self::definition($meter)[1];
    }

    /** The dimension the base is measured on, '' for the whole meter. */
    public static function baseDimension(string $meter): string
    {
        return self::definition($meter)[2];
    }

    /**
     * A dimension is a short lower-case name: a channel key, `api`, `runs`.
     * Anything else is folded to '' rather than refused, because a counter
     * never stops the thing it counts.
     */
    public static function cleanDimension(string $dimension): string
    {
        $dimension = strtolower(trim($dimension));

        return preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $dimension) === 1 ? $dimension : '';
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function definition(string $meter): array
    {
        if (!isset(self::METERS[$meter])) {
            throw new \InvalidArgumentException("Unknown usage meter: {$meter}");
        }

        return self::METERS[$meter];
    }
}
