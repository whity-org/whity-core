<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateTenantHandleHistory — workspace slugs become @handles (design section
 * 36, HD.1, HD.4, HD.7; WHIT-1363).
 *
 * 1. `tenant_handle_history`: a workspace's earlier handles. Each is HELD
 *    until `held_until` (90 days after the change): links that use it keep
 *    working and only the same workspace may take it back. `source` says who
 *    changed it: a person (an owner; counts for the once-in-30-days rule),
 *    an operator, or this migration.
 *
 * 2. Every existing slug is moved to the handle rules, in id order:
 *      - a valid handle that isn't reserved is kept (almolakhas, curapass,
 *        mcp-check, aau-irb …), and so is a `ws-` + 12 hex one Whity made;
 *      - upper case becomes lower case when that is free;
 *      - an invalid or reserved one is tidied (trimmed, shortened) when the
 *        result is valid and free, otherwise it becomes `ws-` + 12 hex;
 *      - a missing one becomes `ws-` + 12 hex;
 *      - tenant 0 (the System workspace) keeps none.
 *    Every changed slug goes into the history, held 90 days, so old links
 *    keep working. `whity-cli handles:check` prints the same plan without
 *    writing anything, to run on a restored copy first.
 *
 * 3. A unique index on LOWER(slug): `Foo` and `foo` can no longer both exist.
 *
 * The rules are inlined here (not read from Whity\Core\Tenant) so this
 * migration does the same thing whenever it runs. The reserved words are the
 * ones of 22 Nov 2026; a later addition to the list doesn't move a handle
 * that was already given out.
 *
 * Table name spelled out literally for TenantOwnedTablesTest and
 * CoreTablesTest. Safe to run twice; reversible (the moved slugs are not
 * moved back).
 */
final class CreateTenantHandleHistory
{
    private const RESERVED = [
        'accept-invitation', 'account', 'account-recovery', 'admin', 'api', 'app-icon', 'billing',
        'boards', 'book', 'booking', 'calendar', 'chat', 'contacts', 'dashboard', 'forgot-password',
        'forms', 'inventory', 'join', 'licensing', 'login', 'logout', 'mcp', 'messages', 'more',
        'onboarding', 'ownership-offers', 'push-icon', 'quote', 'register', 'reset-password', 'sales',
        'settings', 'setup', 'status', 'tasks', 'verify', 'verify-email', 'web-build', 'welcome',
        'workspaces', 'well-known', 'openapi', 'manifest', 'robots', 'sitemap', 'favicon',
        'system', 'administrator', 'root', 'operator', 'operators', 'staff', 'support', 'help',
        'security', 'abuse', 'payments', 'docs', 'blog', 'www', 'mail', 'email', 'smtp', 'imap',
        'app', 'apps', 'cdn', 'static', 'assets', 'media', 'files', 'auth', 'sso', 'oauth', 'accounts',
        'dev', 'test', 'staging', 'api-docs', 'webhooks', 'webhook', 'store', 'pay', 'render', 'builder',
        'studio', 'cloud', 'platform', 'official', 'legal', 'privacy', 'terms', 'billing-team', 'noreply',
        'no-reply', 'postmaster', 'hostmaster', 'webmaster', 'info', 'contact', 'home', 'new', 'null',
        'undefined', 'tenant', 'tenants', 'workspace', 'organisation', 'organization',
        'everyone', 'here', 'all', 'channel', 'you', 'team', 'bot', 'bots', 'agent', 'agents',
    ];

    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS tenant_handle_history (
                id                    SERIAL       PRIMARY KEY,
                tenant_id             INTEGER      NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                handle                VARCHAR(255) NOT NULL,
                changed_at            TIMESTAMP    NOT NULL DEFAULT NOW(),
                changed_by_profile_id INTEGER               REFERENCES profiles(id) ON DELETE SET NULL,
                held_until            TIMESTAMP    NOT NULL,
                source                VARCHAR(16)  NOT NULL DEFAULT 'person'
                                      CHECK (source IN ('person', 'operator', 'migration'))
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tenant_handle_history_handle ON tenant_handle_history (handle, held_until)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tenant_handle_history_tenant ON tenant_handle_history (tenant_id, changed_at)');

        self::moveSlugs($db);

        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS tenants_slug_lower_unique ON tenants (LOWER(slug))');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS tenants_slug_lower_unique');
        $db->exec('DROP TABLE IF EXISTS tenant_handle_history');
    }

    /**
     * What each workspace's slug becomes: [tenant id => [old, new]] for the
     * ones that change. Shared with `whity-cli handles:check`.
     *
     * @param list<array{id: int|string, slug: ?string}> $tenants in id order
     * @return array<int, array{0: ?string, 1: string}>
     */
    public static function plan(array $tenants): array
    {
        $taken = [];
        foreach ($tenants as $row) {
            $slug = strtolower(trim((string) ($row['slug'] ?? '')));
            if ((int) $row['id'] !== 0 && $slug !== '' && self::keeps($slug)) {
                $taken[$slug] = $taken[$slug] ?? (int) $row['id'];
            }
        }

        $changes = [];
        foreach ($tenants as $row) {
            $id = (int) $row['id'];
            if ($id === 0) {
                continue;
            }
            $old = $row['slug'] ?? null;
            $lower = strtolower(trim((string) $old));
            if ($lower !== '' && self::keeps($lower) && ($taken[$lower] ?? null) === $id) {
                if ($lower !== $old) {
                    $changes[$id] = [$old, $lower];
                }
                continue;
            }
            $candidate = self::tidy($lower);
            if ($candidate === '' || !self::keeps($candidate) || isset($taken[$candidate])) {
                do {
                    $candidate = 'ws-' . bin2hex(random_bytes(6));
                } while (isset($taken[$candidate]));
            }
            $taken[$candidate] = $id;
            $changes[$id] = [$old === '' ? null : $old, $candidate];
        }

        return $changes;
    }

    private static function moveSlugs(Database $db): void
    {
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare('SELECT id, slug FROM tenants ORDER BY id');
        $stmt->execute();
        $tenants = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $tenants[] = ['id' => (int) $row['id'], 'slug' => is_string($row['slug']) ? $row['slug'] : null];
        }
        $changes = self::plan($tenants);
        if ($changes === []) {
            return;
        }
        $now = new \DateTimeImmutable('now');
        $update = $pdo->prepare('UPDATE tenants SET slug = :s WHERE id = :id');
        $history = $pdo->prepare(
            "INSERT INTO tenant_handle_history (tenant_id, handle, changed_at, held_until, source)
             VALUES (:t, :h, :at, :until, 'migration')"
        );
        // Clear the way first, so a swap of case between two rows never trips
        // the old case-sensitive unique constraint.
        foreach (array_keys($changes) as $id) {
            $update->execute([':s' => '__handle_move_' . $id, ':id' => $id]);
        }
        foreach ($changes as $id => [$old, $new]) {
            $update->execute([':s' => $new, ':id' => $id]);
            $oldLower = strtolower(trim((string) $old));
            if ($oldLower !== '' && $oldLower !== $new) {
                $history->execute([
                    ':t'     => $id,
                    ':h'     => $oldLower,
                    ':at'    => $now->format('Y-m-d H:i:s'),
                    ':until' => $now->modify('+90 days')->format('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    /** A slug that stays as it is: Whity's own `ws-` one, or a valid handle that isn't reserved. */
    private static function keeps(string $slug): bool
    {
        if (preg_match('/^ws-[0-9a-f]{12}$/', $slug) === 1) {
            return true;
        }
        if (strlen($slug) < 3 || strlen($slug) > 30) {
            return false;
        }
        if (preg_match('/^[a-z][a-z0-9-]*[a-z0-9]$/', $slug) !== 1 || str_contains($slug, '--')) {
            return false;
        }
        if (str_starts_with($slug, 'ws-') || str_contains($slug, 'whity')) {
            return false;
        }

        return !in_array($slug, self::RESERVED, true);
    }

    private static function tidy(string $raw): string
    {
        $handle = preg_replace('/[^a-z0-9-]+/', '-', $raw) ?? '';
        $handle = preg_replace('/-+/', '-', $handle) ?? '';
        if (str_starts_with($handle, 'ws-')) {
            $handle = substr($handle, 3);
        }
        $handle = ltrim($handle, '-0123456789');
        $handle = rtrim(substr($handle, 0, 30), '-');

        return strlen($handle) >= 3 ? $handle : '';
    }
}
