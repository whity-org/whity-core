<?php

declare(strict_types=1);

namespace Whity\Core\Entitlement;

/**
 * Single source of truth for the platform's per-tenant ENTITLEMENTS (WC-ent).
 *
 * An entitlement is an operator-granted capability or limit for one tenant —
 * "may this tenant use feature X?" (a bool feature flag) or "how much of Y may
 * it use?" (an int quota/limit). Entitlements are how the platform owner sells
 * tiers: a tenant on the free plan is not entitled to a custom storage backend
 * or its own SSO IdP; a paid tenant is. They GATE what a tenant may configure —
 * they are NOT settings the tenant edits.
 *
 * This mirrors {@see \Whity\Core\Settings\SettingsRegistry}: the catalogue lives
 * in code (no migration to add a key), values persist as TEXT, and this registry
 * — not the DB — owns the typed contract. The precedence at runtime is simply
 * `tenant_entitlements[key] ?? EntitlementRegistry::default(key)` (there is no
 * global override layer — the DEFAULT is the baseline/free-tier grant). The
 * SYSTEM tenant (id 0) is implicitly unlimited and has no stored overrides.
 *
 * Two value kinds:
 *   - `bool`  feature flag — granted / not granted.
 *   - `int`   quota / limit — a non-negative cap, or the sentinel -1 = UNLIMITED.
 *
 * Adding an entitlement: declare a `const`, add it to {@see DEFAULTS} and
 * {@see TYPES} with a {@see DESCRIPTIONS} entry. Enforcement lives in the
 * consuming feature (e.g. the storage layer reads STORAGE_CUSTOM_BACKEND); this
 * registry only defines the contract.
 */
final class EntitlementRegistry
{
    /** Non-negative int limits use this sentinel to mean "no cap". */
    public const UNLIMITED = -1;

    // ── Storage ──────────────────────────────────────────────────────────────
    /** May the tenant configure a non-default (own) storage backend? */
    public const STORAGE_CUSTOM_BACKEND = 'storage.custom_backend';
    /** Max total stored bytes for the tenant (-1 = unlimited). */
    public const STORAGE_QUOTA_BYTES = 'storage.quota_bytes';

    // ── Identity / SSO ───────────────────────────────────────────────────────
    /** May the tenant configure its own bring-your-own SSO/OIDC provider? */
    public const SSO_TENANT_IDP = 'sso.tenant_idp';

    // ── Membership ───────────────────────────────────────────────────────────
    /** Max active members the tenant may hold (-1 = unlimited). */
    public const MEMBERS_MAX = 'members.max';

    // ── Rate limiting ──────────────────────────────────────────────────────────
    /**
     * The tenant's per-minute request budget (the per-tenant rate limit). A plan
     * raises/lowers it to make throughput scale with the tier. -1 (the default)
     * means "no plan-specific cap — use the platform baseline (RATE_LIMIT_TENANT_*)".
     */
    public const RATELIMIT_RPM = 'ratelimit.rpm';

    // -- Licensing ------------------------------------------------------------
    /** Max devices the tenant may hold in service (-1 = unlimited). */
    public const DEVICES_MAX = 'devices.max';

    // -- Documents ------------------------------------------------------------
    /**
     * Rendered documents per day / per calendar month.
     *
     * TWO KEYS, NOT ONE WITH TWO WINDOWS. A tier sells "5 a day and 50 a month"
     * as two separate promises: the daily figure stops one afternoon eating the
     * month, the monthly figure is what the price is actually for. Marketing
     * prices them independently, and both are enforced by the same meter.
     */
    public const DOCUMENTS_RENDER_PER_DAY = 'documents.render.per_day';
    public const DOCUMENTS_RENDER_PER_MONTH = 'documents.render.per_month';

    /**
     * Form printouts may be customised (design 3.4, FP.2): every option of a
     * theme's Form printouts tab. Without it a workspace prints the standard
     * layout with its logo, colour and marks. Read when a printout is made, so
     * a trial ending changes only the printouts made after it.
     */
    public const DOCUMENTS_FORM_PRINTOUTS = 'documents.form_printouts';

    // -- Platform surfaces ----------------------------------------------------
    /**
     * May the tenant reach the MCP / AI endpoints?
     *
     * Enforced by {@see \Whity\Core\Feature\FeatureService}, which joins it to
     * the operator's instance-wide `mcp.enabled` switch: a tenant needs BOTH.
     *
     * NOTE ON WHAT IS NOT HERE. A `plugins.store` entitlement was drafted
     * beside this one and removed before it shipped, because it could not be
     * enforced: installing from the store is an OPERATOR action against the
     * whole instance, with no tenant in scope at all. A per-tenant flag would
     * have meant tenant A's purchase installing a plugin every other tenant
     * then runs — so it was a line on a pricing screen that gated nothing,
     * which is the one thing this whole area exists to prevent. Selling
     * plugin access per tenant needs per-tenant plugin enablement first.
     */
    public const MCP_ACCESS = 'mcp.access';

    // -- Customers (WHIT-932) ------------------------------------------------
    /**
     * Distinct customers a workspace serves in one calendar month.
     *
     * Counted by {@see \Whity\Core\Usage\CustomerUsage}, which says what a
     * customer is. Never refused: passing it is recorded and shown to the
     * workspace's admins, because a business must not have to turn a customer
     * away in the middle of serving them.
     */
    public const CUSTOMERS_PER_MONTH = 'customers.per_month';

    // -- Brand (WHIT-932) ----------------------------------------------------
    /** The workspace may be reached on its own web address (`tenants.branding_host`). */
    public const BRAND_CUSTOM_DOMAIN = 'brand.custom_domain';
    /** The workspace's public pages leave out "Built on Whity". */
    public const BRAND_REMOVE_BADGE = 'brand.remove_badge';
    /**
     * The workspace may send email from its own address. Its own sign-in is
     * {@see SSO_TENANT_IDP}, which already existed; a tier that sells both
     * sets both.
     */
    public const BRAND_OWN_EMAIL_SENDER = 'brand.own_email_sender';
    /** The workspace may publish its own app in the app stores. */
    public const BRAND_OWN_APP = 'brand.own_app';

    // -- Agents (WHIT-932) ---------------------------------------------------
    /**
     * Agent tool calls included each calendar month.
     *
     * An ALLOWANCE, not a cap: work past it is counted as usage and goes ahead.
     * {@see \Whity\Core\Entitlement\MeterService::record()} never refuses, whatever
     * `meters.enforcement` says. Agents never take a staff seat.
     */
    public const AGENTS_RUNS_PER_MONTH = 'agents.runs.per_month';

    // -- Messages (ADR 0016, WHIT-938) ---------------------------------------

    /**
     * Messages the workspace sends to customers each month, on every channel,
     * plugin channels included. Notes and inbound messages do not count. Only
     * warns: a reply or a booking reminder is never held back by a number.
     */
    public const MESSAGES_OUTBOUND_PER_MONTH = 'messages.outbound.per_month';

    /**
     * Limits that only ever warn: passing one is recorded and shown, never
     * refused, whatever an enforcement setting says. The business model's
     * promise ("passing a limit warns first; nothing is locked") applies to
     * every limit added from WHIT-932 on.
     *
     * @var list<string>
     */
    public const WARN_ONLY = [
        self::CUSTOMERS_PER_MONTH,
        self::BRAND_CUSTOM_DOMAIN,
        self::BRAND_OWN_EMAIL_SENDER,
        self::BRAND_OWN_APP,
        self::AGENTS_RUNS_PER_MONTH,
        self::MESSAGES_OUTBOUND_PER_MONTH,
    ];

    /**
     * Which core limits are METERED, and on what calendar window.
     *
     * Sparse on purpose: everything absent here is a flag or a standing cap,
     * which is the overwhelming majority. A key listed here is consumed and
     * resets; a key not listed is simply held.
     *
     * @var array<string, string>
     */
    private const METER_PERIODS = [
        self::DOCUMENTS_RENDER_PER_DAY   => EntitlementDefinition::PERIOD_DAY,
        self::DOCUMENTS_RENDER_PER_MONTH => EntitlementDefinition::PERIOD_MONTH,
        self::CUSTOMERS_PER_MONTH        => EntitlementDefinition::PERIOD_MONTH,
        self::AGENTS_RUNS_PER_MONTH      => EntitlementDefinition::PERIOD_MONTH,
        self::MESSAGES_OUTBOUND_PER_MONTH => EntitlementDefinition::PERIOD_MONTH,
    ];

    /**
     * The default (baseline / free-tier) grant for every known entitlement,
     * as its TEXT representation. This is what a tenant gets when the operator
     * has set no override. Order defines catalogue order.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        self::STORAGE_CUSTOM_BACKEND => 'false',
        self::STORAGE_QUOTA_BYTES    => '-1',
        self::SSO_TENANT_IDP         => 'false',
        self::MEMBERS_MAX            => '-1',
        self::RATELIMIT_RPM          => '-1',
        self::DEVICES_MAX            => '-1',
        // UNLIMITED by default, like every other limit here. The baseline is
        // what a deployment that sells nothing grants, and a self-hosted
        // install must not find itself rationed by a number nobody chose. A
        // tier that means something sets these DOWN, which is the direction an
        // operator does on purpose.
        self::DOCUMENTS_RENDER_PER_DAY   => '-1',
        self::DOCUMENTS_RENDER_PER_MONTH => '-1',
        // Neutral like the brand flags below: an install that sells nothing
        // customises its printouts. A hosted free tier sets it to false.
        self::DOCUMENTS_FORM_PRINTOUTS => 'true',
        self::MCP_ACCESS             => 'true',
        // Neutral defaults: what an install that sells nothing does today. It
        // counts no customers against anything, may set its own web address
        // and mail sender, may ship its own app, and shows "Built on Whity" on
        // its public pages. A hosted tier sets these on purpose.
        self::CUSTOMERS_PER_MONTH    => '-1',
        self::BRAND_CUSTOM_DOMAIN    => 'true',
        self::BRAND_REMOVE_BADGE     => 'false',
        self::BRAND_OWN_EMAIL_SENDER => 'true',
        self::BRAND_OWN_APP          => 'true',
        self::AGENTS_RUNS_PER_MONTH  => '-1',
        self::MESSAGES_OUTBOUND_PER_MONTH => '-1',
    ];

    /**
     * The value kind of each entitlement: 'bool' (feature flag) or 'int'
     * (quota/limit). Must have exactly the same keys as {@see DEFAULTS}.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        self::STORAGE_CUSTOM_BACKEND => 'bool',
        self::STORAGE_QUOTA_BYTES    => 'int',
        self::SSO_TENANT_IDP         => 'bool',
        self::MEMBERS_MAX            => 'int',
        self::RATELIMIT_RPM          => 'int',
        self::DEVICES_MAX            => 'int',
        self::DOCUMENTS_RENDER_PER_DAY   => 'int',
        self::DOCUMENTS_RENDER_PER_MONTH => 'int',
        self::DOCUMENTS_FORM_PRINTOUTS => 'bool',
        self::MCP_ACCESS             => 'bool',
        self::CUSTOMERS_PER_MONTH    => 'int',
        self::BRAND_CUSTOM_DOMAIN    => 'bool',
        self::BRAND_REMOVE_BADGE     => 'bool',
        self::BRAND_OWN_EMAIL_SENDER => 'bool',
        self::BRAND_OWN_APP          => 'bool',
        self::AGENTS_RUNS_PER_MONTH  => 'int',
        self::MESSAGES_OUTBOUND_PER_MONTH => 'int',
    ];

    /**
     * Human-readable description per entitlement (for the operator admin UI).
     *
     * @var array<string, string>
     */
    private const DESCRIPTIONS = [
        self::STORAGE_CUSTOM_BACKEND => 'Allow the tenant to configure its own storage backend (e.g. S3, Google Drive) instead of the platform default.',
        self::STORAGE_QUOTA_BYTES    => 'Maximum total bytes the tenant may store (-1 for unlimited).',
        self::SSO_TENANT_IDP         => 'Allow the tenant to configure its own bring-your-own SSO/OIDC identity provider.',
        self::MEMBERS_MAX            => 'Maximum number of active members the tenant may have (-1 for unlimited).',
        self::RATELIMIT_RPM          => 'Per-minute API request budget for the tenant (-1 uses the platform baseline).',
        self::DEVICES_MAX            => 'Maximum devices this workspace may have in service at once (-1 for unlimited).',
        self::DOCUMENTS_RENDER_PER_DAY   => 'Documents this workspace may render each day. Resets at midnight (-1 for unlimited).',
        self::DOCUMENTS_RENDER_PER_MONTH => 'Documents this workspace may render each calendar month. Resets on the 1st (-1 for unlimited).',
        self::DOCUMENTS_FORM_PRINTOUTS => 'Allow this workspace to customise how form responses print. Without it they print in the standard layout with its logo, colour and marks.',
        self::MCP_ACCESS             => 'Allow this workspace to use the MCP and AI endpoints.',
        self::CUSTOMERS_PER_MONTH    => 'Customers this workspace may serve each calendar month. Passing it warns and never turns a customer away (-1 for unlimited).',
        self::BRAND_CUSTOM_DOMAIN    => 'Allow this workspace to be reached on its own web address.',
        self::BRAND_REMOVE_BADGE     => 'Leave "Built on Whity" off this workspace\'s public pages.',
        self::BRAND_OWN_EMAIL_SENDER => 'Allow this workspace to send email from its own address. Its own sign-in is "sso.tenant_idp".',
        self::BRAND_OWN_APP          => 'Allow this workspace to publish its own app in the app stores.',
        self::AGENTS_RUNS_PER_MONTH  => 'Agent tool calls included each calendar month. Work past it is counted as usage and goes ahead (-1 for unlimited).',
        self::MESSAGES_OUTBOUND_PER_MONTH => 'Messages this workspace sends to customers each calendar month, on every channel. Sending past it is counted as usage and goes ahead (-1 for unlimited).',
    ];

    /**
     * The known entitlement keys, in catalogue order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_merge(array_keys(self::DEFAULTS), array_keys(PluginEntitlements::all()));
    }

    public static function isKnown(string $key): bool
    {
        return self::isCoreKey($key) || PluginEntitlements::get($key) !== null;
    }

    /**
     * Whether this key is one of core's own, as opposed to a plugin's.
     *
     * The ownership rule reads from here: a plugin may not declare a key core
     * already owns, exactly as it may not own a core permission name.
     */
    public static function isCoreKey(string $key): bool
    {
        return array_key_exists($key, self::DEFAULTS);
    }

    /**
     * The full declaration for a key, core or plugin.
     *
     * @throws \InvalidArgumentException When the key is unknown.
     */
    public static function definition(string $key): EntitlementDefinition
    {
        $plugin = PluginEntitlements::get($key);
        if ($plugin !== null) {
            return $plugin;
        }

        self::assertKnown($key);

        return new EntitlementDefinition(
            $key,
            self::TYPES[$key],
            self::DEFAULTS[$key],
            self::DESCRIPTIONS[$key],
            self::METER_PERIODS[$key] ?? EntitlementDefinition::NO_PERIOD,
        );
    }

    /**
     * The window a metered limit resets on, or null when it is a flag or a
     * standing cap.
     *
     * @throws \InvalidArgumentException When the key is unknown.
     */
    public static function periodFor(string $key): ?string
    {
        return self::definition($key)->period;
    }

    /**
     * The TEXT default (baseline grant) for a key.
     *
     * @throws \InvalidArgumentException When the key is unknown.
     */
    public static function defaultFor(string $key): string
    {
        return self::definition($key)->default;
    }

    /**
     * The value kind: 'bool' or 'int'.
     *
     * @throws \InvalidArgumentException When the key is unknown.
     */
    public static function typeFor(string $key): string
    {
        return self::definition($key)->type;
    }

    /**
     * The operator-facing description for a key.
     *
     * @throws \InvalidArgumentException When the key is unknown.
     */
    public static function describe(string $key): string
    {
        return self::definition($key)->description;
    }

    /**
     * The full catalogue for the operator admin UI: every key mapped to its
     * type, baseline default, and description. Lets the client render an editor
     * (checkbox for bool, number for int) and show the free-tier baseline.
     *
     * @return array<string, array{type: string, default: string, description: string, period: string|null, owner: string|null}>
     */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $definition = self::definition($key);
            $out[$key] = [
                'type'        => $definition->type,
                'default'     => $definition->default,
                'description' => $definition->description,
                // A meter is priced and read differently from a standing cap —
                // "5 per day" against "500 of them" — so the editor is told
                // which it is rather than inferring it from the key's name.
                'period'      => $definition->period,
                // Null for core. The editor groups a plugin's limits under the
                // plugin, so nobody has to guess why "max students" is on the
                // same screen as storage.
                'owner'       => $definition->owner,
            ];
        }

        return $out;
    }

    /**
     * Validate a raw TEXT value for a key. Returns null when valid, or a
     * human-readable reason string otherwise. Never throws (mirrors
     * SettingsRegistry::validate) so the API layer can surface a 422.
     */
    public static function validate(string $key, string $value): ?string
    {
        if (!self::isKnown($key)) {
            return "Unknown entitlement key: {$key}";
        }

        return self::validateAgainst(self::definition($key), $value);
    }

    /**
     * Validate against a declaration rather than a registered key.
     *
     * Exists so a plugin's declaration can be checked BEFORE it is accepted —
     * its own default has to be valid for its own type, and at that moment the
     * key is not yet in the catalogue, so validate() would only report that it
     * is unknown.
     */
    public static function validateAgainst(EntitlementDefinition $definition, string $value): ?string
    {
        $key = $definition->key;

        return match ($definition->type) {
            'bool' => self::isBoolLiteral($value)
                ? null
                : "{$key} must be a boolean (true/false).",
            'int' => self::isIntLiteral($value)
                ? (self::normalizeInt($value) >= self::UNLIMITED
                    ? null
                    : "{$key} must be -1 (unlimited) or a non-negative integer.")
                : "{$key} must be an integer.",
            default => "Unknown entitlement type '{$definition->type}'.",
        };
    }

    /**
     * Canonicalise a validated value for storage: bool → 'true'/'false',
     * int → its decimal string. Assumes the value already passed validate().
     */
    public static function normalize(string $key, string $value): string
    {
        return match (self::definition($key)->type) {
            'bool' => self::isTruthy($value) ? 'true' : 'false',
            'int'  => (string) self::normalizeInt($value),
            default => $value,
        };
    }

    /**
     * Cast a stored (or default) TEXT value to its typed PHP value:
     * bool → bool, int → int.
     *
     * @throws \InvalidArgumentException When the key is unknown.
     */
    public static function cast(string $key, string $value): bool|int
    {
        return match (self::definition($key)->type) {
            'bool' => self::isTruthy($value),
            'int'  => self::normalizeInt($value),
            default => self::normalizeInt($value),
        };
    }

    private static function assertKnown(string $key): void
    {
        if (!self::isKnown($key)) {
            throw new \InvalidArgumentException("Unknown entitlement key: {$key}");
        }
    }

    private static function isBoolLiteral(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['true', 'false', '1', '0', 'yes', 'no'], true);
    }

    private static function isTruthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['true', '1', 'yes'], true);
    }

    private static function isIntLiteral(string $value): bool
    {
        return preg_match('/^-?\d+$/', trim($value)) === 1;
    }

    private static function normalizeInt(string $value): int
    {
        return (int) trim($value);
    }
}
