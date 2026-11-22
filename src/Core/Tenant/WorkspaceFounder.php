<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use PDO;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plan\DefaultPlanAssigner;

/**
 * Founds a workspace for a person who becomes its owner (WHIT-994, WHIT-1169).
 *
 * The one place that writes what a person-created workspace is: a tenant made
 * through {@see TenantProvisioner}, an active primary membership with the base
 * `admin` role, the payment gate when registration requires payment, and the
 * owner row ({@see WorkspaceOwners}). Used by the first workspace after a Whity
 * ID sign-in ({@see FederatedWorkspaceProvisioner}) and by "Create workspace"
 * while signed in ({@see WorkspaceCreator}). Each caller owns the transaction,
 * the locking and its own gates; this class writes, then {@see self::afterCommit()}
 * assigns the default plan and announces it.
 *
 * Its NAME is what the person typed, cleaned of control and bidi characters and
 * numbered when taken. Its SLUG is opaque (`ws-` and 12 hex digits): a slug is a
 * host label under BRANDING_BASE_DOMAIN, and a name an outsider chose must never
 * claim `app.` or `admin.` or a customer's brand.
 */
final class WorkspaceFounder
{
    /** The base (global, NULL-tenant) role a workspace's owner is granted. */
    public const OWNER_ROLE = 'admin';

    /** Longest a request waits on somebody else holding the profile row. */
    private const LOCK_TIMEOUT = '5s';

    /** How many "Name 2", "Name 3"… to try before giving up on a free name. */
    private const NAME_ATTEMPTS = 50;

    public function __construct(
        private readonly PDO $db,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    public function ownerRoleId(): ?int
    {
        // Base roles are global (NULL tenant_id); looked up by unique name
        $stmt = $this->db->prepare('SELECT id FROM roles WHERE name = :name AND tenant_id IS NULL LIMIT 1');
        $stmt->execute([':name' => self::OWNER_ROLE]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Lock the person's profile row for the rest of the caller's transaction
     * (PostgreSQL, with a lock timeout so a stuck transaction cannot pin a
     * worker), so two requests racing for one person are decided one at a time.
     *
     * @return array{display_name: string, status: string}|null
     */
    public function lockProfile(int $profileId): ?array
    {
        $pgsql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($pgsql) {
            $this->db->exec("SET LOCAL lock_timeout = '" . self::LOCK_TIMEOUT . "'");
        }
        // Profiles is a sanctioned GLOBAL table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT display_name, status FROM profiles WHERE id = :id' . ($pgsql ? ' FOR UPDATE' : ''));
        $stmt->execute([':id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'display_name' => trim((string) ($row['display_name'] ?? '')),
            'status'       => (string) ($row['status'] ?? 'active'),
        ];
    }

    /**
     * Write the workspace. Must run inside the caller's open transaction.
     *
     * @return array{tenant_id: int, membership_id: int, role_id: int, name: string}
     */
    public function found(int $profileId, string $name, int $roleId, bool $paymentRequired): array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('WorkspaceFounder::found() must run inside the caller\'s transaction.');
        }

        $name = $this->freeName(self::cleanName($name));
        $slug = $this->freeSlug();

        $tenantId = TenantProvisioner::withCoreSteps($this->db, $this->hooks)->create($name, $slug);

        // is_primary: the person's only row in this new workspace.
        // Written as a boolean literal, not 1 — PostgreSQL rejects the integer.
        $this->db->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
             VALUES (:profile_id, :tenant_id, :role_id, NULL, true, 'active', NOW())"
        )->execute([
            ':profile_id' => $profileId,
            ':tenant_id'  => $tenantId,
            ':role_id'    => $roleId,
        ]);
        $membershipId = (int) $this->db->lastInsertId();

        // The payment gate, written exactly as RegisterApiHandler writes it: a
        // tenant with no plan row is "not billed, never block", so creating it
        // and closing it are one act or neither.
        if ($paymentRequired) {
            $this->db->prepare(
                'INSERT INTO tenant_plan (tenant_id, status, enforcement_mode, assigned_at)
                 VALUES (:tenant_id, :status, :mode, NOW())'
            )->execute([
                ':tenant_id' => $tenantId,
                ':status'    => 'expired',
                ':mode'      => 'block_all',
            ]);
        }

        (new WorkspaceOwners($this->db))->add($tenantId, $profileId, $profileId);

        return ['tenant_id' => $tenantId, 'membership_id' => $membershipId, 'role_id' => $roleId, 'name' => $name];
    }

    /**
     * After the caller's commit: the default plan (its own transaction; a closed
     * payment gate is a plan row already, so it stays closed) and the
     * announcements. Best effort: a listener failure must not undo a workspace
     * that now exists.
     *
     * @param array{tenant_id: int, membership_id: int, role_id: int, name: string} $founded
     */
    public function afterCommit(array $founded, int $profileId, string $via): void
    {
        DefaultPlanAssigner::forDatabase($this->db)->assign($founded['tenant_id']);

        if ($this->hooks === null) {
            return;
        }
        try {
            $this->hooks->dispatch('user.membership.added', [
                'profile_id'    => $profileId,
                'tenant_id'     => $founded['tenant_id'],
                'membership_id' => $founded['membership_id'],
                'role_id'       => $founded['role_id'],
                'role_name'     => self::OWNER_ROLE,
                'ou_id'         => null,
                'status'        => 'active',
                'is_primary'    => true,
                'via'           => $via,
            ]);
            $this->hooks->dispatchAsync('tenant.created.async', ['id' => $founded['tenant_id'], 'name' => $founded['name']]);
        } catch (\Throwable $e) {
            error_log('[workspace-founder] post-commit hooks failed for tenant ' . $founded['tenant_id'] . ': ' . $e->getMessage());
        }
    }

    /**
     * A display name fit to be a tenant name: control characters, bidi
     * overrides/isolates/marks and zero-width characters removed, so one
     * workspace cannot visually impersonate another in an operator's list.
     */
    public static function cleanName(string $displayName): string
    {
        // \p{Cc} control, \p{Cf} format (bidi controls, zero-width, BOM).
        $clean = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $displayName);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        if ($clean === '') {
            $clean = 'Workspace';
        }
        // Leave room for a " 50" suffix inside the VARCHAR(255) column.
        return mb_substr($clean, 0, 240);
    }

    /** $base, or "$base 2", "$base 3"… whichever no tenant holds yet. */
    private function freeName(string $base): string
    {
        // The tenants table is the tenant registry itself, not a tenant-scoped resource
        $stmt = $this->db->prepare('SELECT 1 FROM tenants WHERE name = :name LIMIT 1');
        for ($n = 1; $n <= self::NAME_ATTEMPTS; $n++) {
            $name = $n === 1 ? $base : $base . ' ' . $n;
            $stmt->execute([':name' => $name]);
            if ($stmt->fetchColumn() === false) {
                return $name;
            }
        }
        // Fifty workspaces of the same name: stop counting and make it unique.
        return $base . ' ' . bin2hex(random_bytes(3));
    }

    /** An opaque slug no tenant holds: `ws-` and twelve hex digits. */
    private function freeSlug(): string
    {
        // The tenants table is the tenant registry itself, not a tenant-scoped resource
        $stmt = $this->db->prepare('SELECT 1 FROM tenants WHERE slug = :slug LIMIT 1');
        do {
            $slug = 'ws-' . bin2hex(random_bytes(6));
            $stmt->execute([':slug' => $slug]);
        } while ($stmt->fetchColumn() !== false);
        return $slug;
    }
}
