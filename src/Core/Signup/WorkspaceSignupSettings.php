<?php

declare(strict_types=1);

namespace Whity\Core\Signup;

use PDO;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * A workspace's customer sign-up settings (WHIT-915).
 *
 * Kept as the `signup.*` keys of the settings registry in `tenant_settings`
 * (WHIT-1035; until then the `workspace_signup_settings` table). A workspace
 * that never saved them is Off. Every statement binds tenant_id.
 */
final class WorkspaceSignupSettings
{
    public const MODE_OFF = 'off';
    public const MODE_APPROVAL = 'approval';
    public const MODE_OPEN = 'open';

    public const MODES = [self::MODE_OFF, self::MODE_APPROVAL, self::MODE_OPEN];

    /** How many allowed domains a workspace may list. */
    public const MAX_DOMAINS = 50;

    /** The registry keys this class reads and writes. */
    private const KEYS = [
        SettingsRegistry::SIGNUP_MODE,
        SettingsRegistry::SIGNUP_ROLE_ID,
        SettingsRegistry::SIGNUP_ALLOWED_DOMAINS,
        SettingsRegistry::SIGNUP_CUSTOMER_INVITES,
    ];

    private readonly TenantSettingsRepository $store;

    public function __construct(private readonly PDO $db)
    {
        $this->store = new TenantSettingsRepository($db);
    }

    /**
     * `customer_invites` (#1872): whether this workspace's customers may invite
     * colleagues into their own role. Off unless an admin turned it on.
     *
     * Stored as the `signup.*` keys of the settings registry (WHIT-1035); a
     * workspace that never saved them reads the registry defaults: Off, no
     * role, every domain, no customer invites.
     *
     * @return array{mode: string, role_id: int|null, allowed_domains: list<string>, customer_invites: bool, updated_at: string|null}
     */
    public function get(int $tenantId): array
    {
        $stored = array_intersect_key($this->store->allForTenant($tenantId), array_flip(self::KEYS));
        $value = static fn (string $key): string => $stored[$key] ?? SettingsRegistry::defaultFor($key);

        $mode = $value(SettingsRegistry::SIGNUP_MODE);
        $roleId = (int) $value(SettingsRegistry::SIGNUP_ROLE_ID);
        $domains = json_decode($value(SettingsRegistry::SIGNUP_ALLOWED_DOMAINS), true);

        return [
            'mode' => in_array($mode, self::MODES, true) ? $mode : self::MODE_OFF,
            // A deleted role reads as "not chosen", as the old table's
            // ON DELETE SET NULL did, so sign-ups are refused until an admin
            // chooses again.
            'role_id' => $roleId > 0 && $this->roleExists($tenantId, $roleId) ? $roleId : null,
            'allowed_domains' => is_array($domains) ? array_values(array_filter($domains, 'is_string')) : [],
            'customer_invites' => $value(SettingsRegistry::SIGNUP_CUSTOMER_INVITES) === 'true',
            'updated_at' => $stored === [] ? null : $this->store->lastUpdatedAt($tenantId, self::KEYS),
        ];
    }

    /**
     * Saves all four values at once, so a reader never sees a new mode with an
     * old role.
     *
     * @param list<string> $allowedDomains Already normalised by {@see self::normaliseDomains()}.
     * @param int|null     $actorId        Recorded by the caller's audit entry; kept for callers.
     * @param bool|null    $customerInvites Null keeps what is saved (off for a new workspace).
     */
    public function save(int $tenantId, string $mode, ?int $roleId, array $allowedDomains, ?int $actorId, ?bool $customerInvites = null): void
    {
        $customerInvites ??= $this->get($tenantId)['customer_invites'];
        $values = [
            SettingsRegistry::SIGNUP_MODE => $mode,
            SettingsRegistry::SIGNUP_ROLE_ID => (string) ($roleId ?? 0),
            SettingsRegistry::SIGNUP_ALLOWED_DOMAINS => (string) json_encode($allowedDomains),
            SettingsRegistry::SIGNUP_CUSTOMER_INVITES => $customerInvites ? 'true' : 'false',
        ];
        foreach ($values as $key => $text) {
            $problem = SettingsRegistry::validate($key, $text);
            if ($problem !== null) {
                throw new \InvalidArgumentException($problem);
            }
        }

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            foreach ($values as $key => $text) {
                $this->store->set($tenantId, $key, $text);
            }
            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** The role still exists and this workspace can use it: its own, or one shared by every workspace. */
    private function roleExists(int $tenantId, int $roleId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM roles WHERE id = :id AND (tenant_id = :tenant_id OR tenant_id IS NULL)');
        $stmt->execute([':id' => $roleId, ':tenant_id' => $tenantId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Lowercase, strip a leading "@", drop duplicates. Null when any entry is
     * not a plain domain name, so the caller can refuse the whole save.
     *
     * The rule is the email-domain policy's: letters, digits and hyphens in
     * dot-separated labels, at least one dot, no IP addresses or wildcards.
     *
     * @param mixed $input
     * @return list<string>|null
     */
    public static function normaliseDomains(mixed $input): ?array
    {
        if ($input === null) {
            return [];
        }
        if (!is_array($input)) {
            return null;
        }

        $out = [];
        foreach ($input as $entry) {
            if (!is_string($entry)) {
                return null;
            }
            $domain = strtolower(trim($entry));
            $domain = ltrim($domain, '@');
            if ($domain === '') {
                continue;
            }
            if (!self::isDomain($domain)) {
                return null;
            }
            $out[$domain] = true;
        }

        if (count($out) > self::MAX_DOMAINS) {
            return null;
        }

        return array_keys($out);
    }

    /**
     * The first entry {@see self::normaliseDomains()} refuses as a domain, so
     * the admin can be told which line to fix. Null when every entry is a
     * domain and the refusal was the count or the shape instead.
     */
    public static function firstInvalidDomain(mixed $input): ?string
    {
        if (!is_array($input)) {
            return null;
        }

        foreach ($input as $entry) {
            if (!is_string($entry)) {
                return '';
            }
            $domain = ltrim(strtolower(trim($entry)), '@');
            if ($domain !== '' && !self::isDomain($domain)) {
                return trim($entry);
            }
        }

        return null;
    }

    /**
     * Whether an address may sign up under a domain list. An empty list allows
     * every address.
     *
     * @param list<string> $allowedDomains
     */
    public static function emailAllowed(string $email, array $allowedDomains): bool
    {
        if ($allowedDomains === []) {
            return true;
        }

        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }

        return in_array(strtolower(substr($email, $at + 1)), $allowedDomains, true);
    }

    private static function isDomain(string $domain): bool
    {
        if (strlen($domain) > 253 || !str_contains($domain, '.')) {
            return false;
        }

        return preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $domain) === 1
            && preg_match('/^[0-9.]+$/', $domain) !== 1;
    }
}
