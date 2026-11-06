<?php

declare(strict_types=1);

namespace Whity\Core\Signup;

use PDO;

/**
 * A workspace's customer sign-up settings (WHIT-914, migration 222).
 *
 * One row per workspace in `workspace_signup_settings`; a workspace with no row
 * is Off. Every statement binds tenant_id.
 */
final class WorkspaceSignupSettings
{
    public const MODE_OFF = 'off';
    public const MODE_APPROVAL = 'approval';
    public const MODE_OPEN = 'open';

    public const MODES = [self::MODE_OFF, self::MODE_APPROVAL, self::MODE_OPEN];

    /** How many allowed domains a workspace may list. */
    public const MAX_DOMAINS = 50;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{mode: string, role_id: int|null, allowed_domains: list<string>, updated_at: string|null}
     */
    public function get(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT mode, role_id, allowed_domains, updated_at
               FROM workspace_signup_settings WHERE tenant_id = :tid'
        );
        $stmt->execute([':tid' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return ['mode' => self::MODE_OFF, 'role_id' => null, 'allowed_domains' => [], 'updated_at' => null];
        }

        $mode = in_array($row['mode'], self::MODES, true) ? (string) $row['mode'] : self::MODE_OFF;
        $domains = json_decode((string) $row['allowed_domains'], true);

        return [
            'mode' => $mode,
            'role_id' => $row['role_id'] !== null ? (int) $row['role_id'] : null,
            'allowed_domains' => is_array($domains) ? array_values(array_filter($domains, 'is_string')) : [],
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * @param list<string> $allowedDomains Already normalised by {@see self::normaliseDomains()}.
     */
    public function save(int $tenantId, string $mode, ?int $roleId, array $allowedDomains, ?int $actorId): void
    {
        $params = [
            ':tid' => $tenantId,
            ':mode' => $mode,
            ':role_id' => $roleId,
            ':domains' => (string) json_encode($allowedDomains),
            ':actor' => $actorId,
        ];

        $update = $this->db->prepare(
            'UPDATE workspace_signup_settings
                SET mode = :mode, role_id = :role_id, allowed_domains = :domains,
                    updated_by = :actor, updated_at = NOW()
              WHERE tenant_id = :tid'
        );
        $update->execute($params);
        if ($update->rowCount() > 0) {
            return;
        }

        $this->db->prepare(
            'INSERT INTO workspace_signup_settings (tenant_id, mode, role_id, allowed_domains, updated_by, updated_at)
             VALUES (:tid, :mode, :role_id, :domains, :actor, NOW())
             ON CONFLICT (tenant_id) DO UPDATE
                SET mode = EXCLUDED.mode, role_id = EXCLUDED.role_id,
                    allowed_domains = EXCLUDED.allowed_domains,
                    updated_by = EXCLUDED.updated_by, updated_at = EXCLUDED.updated_at'
        )->execute($params);
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
