<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use PDO;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Log\ScrubbedErrorLog;
use Whity\Sdk\Hooks\Events;

/**
 * Workspace @handles: checking, resolving and changing them (design section
 * 36, HD.1–HD.4, HD.9; WHIT-1363).
 *
 * The handle is `tenants.slug`. When it changes, the old one goes into
 * `tenant_handle_history` and is HELD for {@see self::HOLD_DAYS} days: links
 * that use it keep working ({@see self::resolve()}), and only the same
 * workspace may take it back. After that it is free. A handle Whity made
 * (`ws-…`) leaves no history: nobody shared it.
 *
 * A person (an owner) may change a handle at most once every
 * {@see self::CHANGE_EVERY_DAYS} days; choosing a handle for the first time
 * (from a `ws-…` one) is free of that rule. Operators may change any handle
 * at any time and are not bound by Whity's built-in reserved words, only by
 * the shape rules (HD.9).
 *
 * `tenants` is the installation's registry, so looking a handle up across
 * every workspace is what this class is for; it returns tenant ids only.
 */
final class Handles
{
    public const HOLD_DAYS = 90;
    public const CHANGE_EVERY_DAYS = 30;

    public const SOURCE_PERSON = 'person';
    public const SOURCE_OPERATOR = 'operator';
    public const SOURCE_MIGRATION = 'migration';

    public const AUDIT_CHANGED = 'tenant.handle_changed';

    /** @var \Closure(): string */
    private \Closure $operatorList;

    /**
     * @param (\Closure(): string)|null $operatorList the `handles.reserved` setting
     */
    public function __construct(
        private readonly PDO $db,
        ?\Closure $operatorList = null,
        private readonly ?AuditLogger $audit = null,
        private readonly ?HookManager $hooks = null,
    ) {
        $this->operatorList = $operatorList ?? static fn (): string => '';
    }

    /**
     * Why `$typed` can't become `$tenantId`'s handle, or null when it can
     * (its own current handle is fine). `$tenantId` null means "a new
     * workspace".
     */
    public function problem(string $typed, ?int $tenantId = null, bool $operator = false): ?string
    {
        $handle = HandleRules::normalize($typed);
        $shape = HandleRules::choosableProblem($handle);
        if ($shape !== null) {
            return $shape;
        }
        if ($operator
            ? in_array($handle, $this->operatorList(), true)
            : ReservedHandles::isReserved($handle, $this->operatorList())) {
            // An operator may give out Whity's reserved words, not their own list's.
            return HandleRules::RESERVED;
        }
        $owner = $this->currentOwner($handle);
        if ($owner !== null && $owner !== $tenantId) {
            return HandleRules::TAKEN;
        }
        $holder = $this->heldBy($handle);
        if ($holder !== null && $holder !== $tenantId) {
            return HandleRules::HELD;
        }

        return null;
    }

    /**
     * The workspace a handle names: its current one, else one whose old handle
     * is still held. Null when nothing matches.
     */
    public function resolve(string $typed): ?int
    {
        $handle = HandleRules::normalize($typed);
        if ($handle === '') {
            return null;
        }

        return $this->currentOwner($handle) ?? $this->heldBy($handle);
    }

    /** The workspace's handle now, or null (tenant 0 has none). */
    public function handleOf(int $tenantId): ?string
    {
        $stmt = $this->db->prepare('SELECT slug FROM tenants WHERE id = :id');
        $stmt->execute([':id' => $tenantId]);
        $slug = $stmt->fetchColumn();

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /** Whether `$typed` is an OLD handle that now redirects to another one. */
    public function isHeldAlias(string $typed): bool
    {
        $handle = HandleRules::normalize($typed);

        return $handle !== '' && $this->currentOwner($handle) === null && $this->heldBy($handle) !== null;
    }

    /**
     * When a person last changed this workspace's handle, or null. Operator
     * and migration changes don't count against the 30-day rule.
     */
    public function lastChangedByPerson(int $tenantId): ?\DateTimeImmutable
    {
        $stmt = $this->db->prepare(
            "SELECT MAX(changed_at) FROM tenant_handle_history WHERE tenant_id = :t AND source = 'person'"
        );
        $stmt->execute([':t' => $tenantId]);
        $at = $stmt->fetchColumn();

        return is_string($at) && $at !== '' ? new \DateTimeImmutable($at) : null;
    }

    /** The earliest a person may change this workspace's handle again, or null for now. */
    public function nextChangeAllowedAt(int $tenantId): ?\DateTimeImmutable
    {
        $last = $this->lastChangedByPerson($tenantId);
        if ($last === null) {
            return null;
        }
        $next = $last->modify('+' . self::CHANGE_EVERY_DAYS . ' days');

        return $next > new \DateTimeImmutable('now') ? $next : null;
    }

    /**
     * Change a workspace's handle. Returns the new handle.
     *
     * @throws HandleException
     */
    public function change(int $tenantId, string $typed, ?int $byProfileId, bool $operator = false): string
    {
        if ($tenantId === 0) {
            throw new HandleException(HandleRules::RESERVED);
        }
        $new = HandleRules::normalize($typed);
        $old = $this->handleOf($tenantId);
        if ($old === $new) {
            return $new;
        }
        $problem = $this->problem($new, $tenantId, $operator);
        if ($problem !== null) {
            throw new HandleException($problem);
        }
        $firstChoice = $old === null || HandleRules::isGenerated($old);
        if (!$operator && !$firstChoice && $this->nextChangeAllowedAt($tenantId) !== null) {
            throw new HandleException(HandleRules::TOO_SOON);
        }

        $now = new \DateTimeImmutable('now');
        $started = !$this->db->inTransaction();
        if ($started) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare('UPDATE tenants SET slug = :s WHERE id = :id')
                ->execute([':s' => $new, ':id' => $tenantId]);
            // Taking an old handle back ends its hold.
            $this->db->prepare('DELETE FROM tenant_handle_history WHERE tenant_id = :t AND handle = :h')
                ->execute([':t' => $tenantId, ':h' => $new]);
            if (!$firstChoice) {
                $this->db->prepare(
                    'INSERT INTO tenant_handle_history (tenant_id, handle, changed_at, changed_by_profile_id, held_until, source)
                     VALUES (:t, :h, :at, :by, :until, :src)'
                )->execute([
                    ':t'     => $tenantId,
                    ':h'     => $old,
                    ':at'    => $now->format('Y-m-d H:i:s'),
                    ':by'    => $byProfileId,
                    ':until' => $now->modify('+' . self::HOLD_DAYS . ' days')->format('Y-m-d H:i:s'),
                    ':src'   => $operator ? self::SOURCE_OPERATOR : self::SOURCE_PERSON,
                ]);
            }
            if ($started) {
                $this->db->commit();
            }
        } catch (\PDOException $e) {
            if ($started && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Someone took it between the check and the write (the unique index).
            if ($this->currentOwner($new) !== $tenantId) {
                throw new HandleException(HandleRules::TAKEN);
            }
            throw $e;
        }

        $this->audit?->record(self::AUDIT_CHANGED, [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $byProfileId,
            'target_type'   => 'tenant',
            'target_id'     => $tenantId,
            'metadata'      => ['from' => $old, 'to' => $new],
        ]);
        if ($this->hooks !== null) {
            try {
                $this->hooks->dispatch(Events::TENANT_HANDLE_CHANGED, [
                    'id'            => $tenantId,
                    'old_handle'    => $old,
                    'new_handle'    => $new,
                    'by_profile_id' => $byProfileId,
                ]);
            } catch (\Throwable $e) {
                ScrubbedErrorLog::failure('[handles] tenant.handle_changed listeners failed', $e);
            }
        }

        return $new;
    }

    /** @return list<string> */
    private function operatorList(): array
    {
        return ReservedHandles::parseOperatorList(($this->operatorList)());
    }

    private function currentOwner(string $handle): ?int
    {
        // The tenants registry: which workspace has this handle now (an id only).
        $stmt = $this->db->prepare('SELECT id FROM tenants WHERE LOWER(slug) = :h AND id <> 0 LIMIT 1');
        $stmt->execute([':h' => $handle]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function heldBy(string $handle): ?int
    {
        // @tenant-guard-ignore: resolving an OLD @handle to the workspace that still holds it (design section 36, HD.4). Like the tenants registry lookup it replaces, this is what RESOLVES the tenant on a public path where nothing else names one; it returns the tenant id only, and every later read binds that id. The handle is unique among unexpired rows by construction (Handles::problem refuses a handle another workspace holds).
        $stmt = $this->db->prepare(
            'SELECT tenant_id FROM tenant_handle_history
              WHERE handle = :h AND held_until > :now
              ORDER BY changed_at DESC LIMIT 1'
        );
        $stmt->execute([':h' => $handle, ':now' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s')]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
