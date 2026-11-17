<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Db\DbBool;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\MembershipRepository;
use Whity\Sdk\Hooks\Events;

/**
 * Handing a workspace over, and making someone a co-owner (WHIT-1170, WHIT-1171).
 *
 * Both are OFFERS the other person accepts (owner, 2026-11-16): an owner offers,
 * the person offered accepts or declines within {@see self::OFFER_DAYS} days,
 * and both confirm it's them (GatedApproval, checked by the HTTP layer before
 * calling in here). Until it is accepted nothing changes.
 *
 *  - transfer: on accept the person becomes an owner and the giver stops being
 *    one. The giver keeps their membership (an admin) unless they chose to
 *    leave when they offered, in which case their memberships here go.
 *  - co_owner: on accept the person becomes an owner beside the others.
 *
 * Who may be offered: an active STAFF member of the workspace (not a customer),
 * not the giver, not already an owner. One open offer per person per workspace.
 * Everything is re-checked under a row lock when the offer is accepted: the
 * giver must still be an owner, the person still an active staff member.
 *
 * Every read and write names its tenant, except the person's own offers
 * ({@see self::openForPerson()}, {@see self::find()}), which the caller checks
 * against the person.
 */
final class WorkspaceOwnership
{
    public const KIND_TRANSFER = 'transfer';
    public const KIND_CO_OWNER = 'co_owner';

    public const STATUS_OPEN = 'open';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    /** The giver stopped being an owner before it was accepted. */
    public const STATUS_VOID = 'void';

    public const OFFER_DAYS = 7;

    public const AUDIT_OFFERED = 'workspace.ownership_offered';
    public const AUDIT_CANCELLED = 'workspace.ownership_offer_cancelled';
    public const AUDIT_DECLINED = 'workspace.ownership_offer_declined';
    public const AUDIT_TRANSFERRED = 'workspace.ownership_transferred';
    public const AUDIT_OWNER_ADDED = 'workspace.owner_added';
    public const AUDIT_OWNER_REMOVED = 'workspace.owner_removed';
    public const AUDIT_LEFT = 'workspace.owner_left';

    private readonly WorkspaceOwners $owners;

    public function __construct(
        private readonly PDO $db,
        private readonly ?AuditLogger $audit = null,
        private readonly ?HookManager $hooks = null,
        private readonly ?OwnershipNotifier $notifier = null,
    ) {
        $this->owners = new WorkspaceOwners($db);
    }

    public function owners(): WorkspaceOwners
    {
        return $this->owners;
    }

    /**
     * Offer ownership (transfer) or co-ownership to a member.
     *
     * @return array<string, mixed> the offer
     *
     * @throws OwnershipException
     */
    public function offer(int $tenantId, int $fromId, int $toId, string $kind, bool $leaveAfter = false): array
    {
        if (!in_array($kind, [self::KIND_TRANSFER, self::KIND_CO_OWNER], true)) {
            throw new OwnershipException(OwnershipException::INVALID);
        }
        $this->checkOffer($tenantId, $fromId, $toId);

        $expires = (new \DateTimeImmutable('now'))->modify('+' . self::OFFER_DAYS . ' days')->format('Y-m-d H:i:s');
        $this->db->prepare(
            "INSERT INTO workspace_ownership_offers
                (tenant_id, kind, from_profile_id, to_profile_id, leave_after, status, expires_at, created_at)
             VALUES (:t, :kind, :from, :to, :leave, 'open', :expires, NOW())"
        )->execute([
            ':t'       => $tenantId,
            ':kind'    => $kind,
            ':from'    => $fromId,
            ':to'      => $toId,
            ':leave'   => $kind === self::KIND_TRANSFER && $leaveAfter ? 1 : 0,
            ':expires' => $expires,
        ]);
        $offer = $this->find((int) $this->db->lastInsertId());
        if ($offer === null) {
            throw new OwnershipException(OwnershipException::NOT_FOUND);
        }

        $this->record(self::AUDIT_OFFERED, $tenantId, $fromId, $toId, ['offer_id' => $offer['id'], 'kind' => $kind]);
        $this->notifier?->offered($offer);

        return $offer;
    }

    /**
     * The checks an offer must pass, also used to refuse before the identity
     * check is started.
     *
     * @throws OwnershipException
     */
    public function checkOffer(int $tenantId, int $fromId, int $toId): void
    {
        if ($fromId === $toId) {
            throw new OwnershipException(OwnershipException::SELF);
        }
        if (!$this->owners->isOwner($tenantId, $fromId)) {
            throw new OwnershipException(OwnershipException::NOT_OWNER);
        }
        if (!$this->isStaff($tenantId, $toId)) {
            throw new OwnershipException(OwnershipException::NOT_MEMBER);
        }
        if ($this->owners->isOwner($tenantId, $toId)) {
            throw new OwnershipException(OwnershipException::ALREADY_OWNER);
        }
        if ($this->openOfferTo($tenantId, $toId) !== null) {
            throw new OwnershipException(OwnershipException::PENDING);
        }
    }

    /** An owner withdraws an open offer. */
    public function cancel(int $tenantId, int $offerId, int $byId): bool
    {
        if (!$this->owners->isOwner($tenantId, $byId)) {
            throw new OwnershipException(OwnershipException::NOT_OWNER);
        }
        $offer = $this->findInTenant($tenantId, $offerId);
        if ($offer === null || $offer['status'] !== self::STATUS_OPEN) {
            return false;
        }
        $stmt = $this->db->prepare(
            "UPDATE workspace_ownership_offers SET status = 'cancelled', decided_at = NOW(), decided_by_profile_id = :by
              WHERE id = :id AND tenant_id = :t AND status = 'open'"
        );
        $stmt->execute([':id' => $offerId, ':t' => $tenantId, ':by' => $byId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->record(self::AUDIT_CANCELLED, $tenantId, $byId, (int) $offer['to_profile_id'], ['offer_id' => $offerId, 'kind' => $offer['kind']]);
        $this->notifier?->cancelled($offer, $this->displayName($byId));
        return true;
    }

    /** The person offered says no. */
    public function decline(int $offerId, int $byId): bool
    {
        $offer = $this->find($offerId);
        if ($offer === null || $offer['to_profile_id'] !== $byId) {
            throw new OwnershipException(OwnershipException::NOT_FOUND);
        }
        if ($offer['status'] !== self::STATUS_OPEN) {
            return false;
        }
        $stmt = $this->db->prepare(
            "UPDATE workspace_ownership_offers SET status = 'declined', decided_at = NOW(), decided_by_profile_id = :by
              WHERE id = :id AND tenant_id = :t AND status = 'open'"
        );
        $stmt->execute([':id' => $offerId, ':t' => $offer['tenant_id'], ':by' => $byId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->record(self::AUDIT_DECLINED, (int) $offer['tenant_id'], $byId, (int) $offer['from_profile_id'], ['offer_id' => $offerId, 'kind' => $offer['kind']]);
        $this->notifier?->declined($offer);
        return true;
    }

    /**
     * The person offered accepts. Everything is checked again under a lock.
     *
     * @return array<string, mixed> the accepted offer
     *
     * @throws OwnershipException
     */
    public function accept(int $offerId, int $byId): array
    {
        if ($this->db->inTransaction()) {
            throw new \LogicException('WorkspaceOwnership::accept() must own its transaction.');
        }
        $offer = $this->find($offerId);
        if ($offer === null || $offer['to_profile_id'] !== $byId) {
            throw new OwnershipException(OwnershipException::NOT_FOUND);
        }
        $tenantId = (int) $offer['tenant_id'];
        $fromId = (int) $offer['from_profile_id'];
        $left = false;

        $this->db->beginTransaction();
        try {
            $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE' : '';
            $stmt = $this->db->prepare(
                'SELECT status, expires_at FROM workspace_ownership_offers WHERE id = :id AND tenant_id = :t' . $forUpdate
            );
            $stmt->execute([':id' => $offerId, ':t' => $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || $row['status'] !== self::STATUS_OPEN) {
                throw new OwnershipException(OwnershipException::NOT_OPEN);
            }
            if (strtotime((string) $row['expires_at']) < time()) {
                $this->setStatus($tenantId, $offerId, self::STATUS_EXPIRED, null);
                $this->db->commit();
                throw new OwnershipException(OwnershipException::EXPIRED);
            }
            if (!$this->owners->isOwner($tenantId, $fromId)) {
                $this->setStatus($tenantId, $offerId, self::STATUS_VOID, null);
                $this->db->commit();
                throw new OwnershipException(OwnershipException::NOT_OPEN);
            }
            if (!$this->isStaff($tenantId, $byId)) {
                throw new OwnershipException(OwnershipException::NOT_MEMBER);
            }

            $this->owners->add($tenantId, $byId, $fromId);
            if ($offer['kind'] === self::KIND_TRANSFER) {
                $this->owners->remove($tenantId, $fromId);
                if ($offer['leave_after']) {
                    $memberships = new MembershipRepository($this->db, $this->hooks);
                    $ids = $this->db->prepare('SELECT id FROM memberships WHERE tenant_id = :t AND profile_id = :p');
                    $ids->execute([':t' => $tenantId, ':p' => $fromId]);
                    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $membershipId) {
                        $memberships->delete((int) $membershipId, $tenantId);
                    }
                    $left = true;
                }
            }
            $this->setStatus($tenantId, $offerId, self::STATUS_ACCEPTED, $byId);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        RoleChecker::clearCache();

        $isTransfer = $offer['kind'] === self::KIND_TRANSFER;
        $this->record(self::AUDIT_OWNER_ADDED, $tenantId, $byId, $byId, ['offer_id' => $offerId, 'added_by' => $fromId]);
        $this->announce(Events::WORKSPACE_OWNER_ADDED, [
            'tenant_id' => $tenantId, 'profile_id' => $byId, 'added_by' => $fromId, 'offer_id' => $offerId,
        ]);
        if ($isTransfer) {
            $this->record(self::AUDIT_TRANSFERRED, $tenantId, $byId, $fromId, ['offer_id' => $offerId, 'from_left' => $left]);
            $this->record(self::AUDIT_OWNER_REMOVED, $tenantId, $byId, $fromId, ['offer_id' => $offerId, 'via' => 'transfer']);
            if ($left) {
                $this->record(self::AUDIT_LEFT, $tenantId, $fromId, $fromId, ['offer_id' => $offerId]);
            }
            $this->announce(Events::WORKSPACE_OWNER_REMOVED, [
                'tenant_id' => $tenantId, 'profile_id' => $fromId, 'removed_by' => $byId, 'via' => 'transfer',
            ]);
            $this->announce(Events::WORKSPACE_OWNERSHIP_TRANSFERRED, [
                'tenant_id' => $tenantId, 'from_profile_id' => $fromId, 'to_profile_id' => $byId,
                'offer_id' => $offerId, 'from_left' => $left,
            ]);
        }

        $accepted = $this->find($offerId) ?? $offer;
        $this->notifier?->accepted($accepted, $left);

        return $accepted;
    }

    /**
     * The workspace's open offers, newest first. Offers past their date are
     * marked expired on the way.
     *
     * @return list<array<string, mixed>>
     */
    public function openForTenant(int $tenantId): array
    {
        $this->expireDue($tenantId);
        $stmt = $this->db->prepare(
            self::SELECT . " WHERE o.tenant_id = :t AND o.status = 'open' ORDER BY o.created_at DESC, o.id DESC"
        );
        $stmt->execute([':t' => $tenantId]);
        return array_values(array_map([self::class, 'shape'], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Open offers made TO this person, in any workspace they belong to.
     *
     * @return list<array<string, mixed>>
     */
    public function openForPerson(int $profileId): array
    {
        // The person's OWN offers across their workspaces, like their memberships at sign-in.
        $stmt = $this->db->prepare(
            self::SELECT . " WHERE o.to_profile_id = :p AND o.status = 'open' AND o.expires_at > NOW()
               ORDER BY o.created_at DESC, o.id DESC"
        );
        $stmt->execute([':p' => $profileId]);
        return array_values(array_map([self::class, 'shape'], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * One offer by id, whatever its workspace. The caller checks it is the
     * person's own (to or from) before showing or acting on it.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $offerId): ?array
    {
        // Looked up by id for the person it names; every caller checks to/from against the caller.
        $stmt = $this->db->prepare(self::SELECT . ' WHERE o.id = :id');
        $stmt->execute([':id' => $offerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::shape($row);
    }

    /** @return array<string, mixed>|null */
    public function findInTenant(int $tenantId, int $offerId): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE o.id = :id AND o.tenant_id = :t');
        $stmt->execute([':id' => $offerId, ':t' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::shape($row);
    }

    /**
     * Who an owner may offer ownership to now: active staff members who aren't
     * owners and have no offer waiting, by name.
     *
     * @return list<array{profile_id: int, name: string, email: string}>
     */
    public function candidates(int $tenantId): array
    {
        $this->expireDue($tenantId);
        $stmt = $this->db->prepare(
            "SELECT DISTINCT m.profile_id, p.display_name, pe.email
               FROM memberships m
               JOIN profiles p ON p.id = m.profile_id
               LEFT JOIN profile_emails pe ON pe.profile_id = m.profile_id AND pe.is_primary = TRUE
              WHERE m.tenant_id = :t AND m.status = 'active' AND m.kind = 'member'
                AND NOT EXISTS (SELECT 1 FROM workspace_owners o WHERE o.tenant_id = m.tenant_id AND o.profile_id = m.profile_id)
                AND NOT EXISTS (SELECT 1 FROM workspace_ownership_offers f
                                 WHERE f.tenant_id = m.tenant_id AND f.to_profile_id = m.profile_id AND f.status = 'open')
              ORDER BY p.display_name ASC"
        );
        $stmt->execute([':t' => $tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'profile_id' => (int) $row['profile_id'],
                'name'       => (string) ($row['display_name'] ?? ''),
                'email'      => (string) ($row['email'] ?? ''),
            ];
        }
        return $out;
    }

    /** An active staff (not customer) membership in this workspace. */
    public function isStaff(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM memberships WHERE tenant_id = :t AND profile_id = :p AND status = 'active' AND kind = 'member' LIMIT 1"
        );
        $stmt->execute([':t' => $tenantId, ':p' => $profileId]);
        return $stmt->fetchColumn() !== false;
    }

    // @tenant-guard-ignore: the shared column list; every use appends its own predicate (tenant_id, or the person's own to_profile_id / id)
    private const SELECT = 'SELECT o.id, o.tenant_id, o.kind, o.from_profile_id, o.to_profile_id, o.leave_after, o.status,
                o.expires_at, o.created_at, o.decided_at, t.name AS tenant_name,
                pf.display_name AS from_name, pt.display_name AS to_name
           FROM workspace_ownership_offers o
           JOIN tenants t ON t.id = o.tenant_id
           LEFT JOIN profiles pf ON pf.id = o.from_profile_id
           LEFT JOIN profiles pt ON pt.id = o.to_profile_id';

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $status = (string) $row['status'];
        if ($status === self::STATUS_OPEN && strtotime((string) $row['expires_at']) < time()) {
            $status = self::STATUS_EXPIRED;
        }
        return [
            'id'              => (int) $row['id'],
            'tenant_id'       => (int) $row['tenant_id'],
            'tenant_name'     => (string) ($row['tenant_name'] ?? ''),
            'kind'            => (string) $row['kind'],
            'from_profile_id' => (int) $row['from_profile_id'],
            'from_name'       => (string) ($row['from_name'] ?? ''),
            'to_profile_id'   => (int) $row['to_profile_id'],
            'to_name'         => (string) ($row['to_name'] ?? ''),
            'leave_after'     => DbBool::of($row['leave_after']),
            'status'          => $status,
            'expires_at'      => (string) $row['expires_at'],
            'created_at'      => (string) $row['created_at'],
            'decided_at'      => $row['decided_at'] !== null ? (string) $row['decided_at'] : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function openOfferTo(int $tenantId, int $toId): ?array
    {
        $this->expireDue($tenantId);
        $stmt = $this->db->prepare(
            self::SELECT . " WHERE o.tenant_id = :t AND o.to_profile_id = :p AND o.status = 'open' LIMIT 1"
        );
        $stmt->execute([':t' => $tenantId, ':p' => $toId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::shape($row);
    }

    private function expireDue(int $tenantId): void
    {
        $this->db->prepare(
            "UPDATE workspace_ownership_offers SET status = 'expired', decided_at = NOW()
              WHERE tenant_id = :t AND status = 'open' AND expires_at < NOW()"
        )->execute([':t' => $tenantId]);
    }

    private function setStatus(int $tenantId, int $offerId, string $status, ?int $by): void
    {
        $this->db->prepare(
            'UPDATE workspace_ownership_offers SET status = :s, decided_at = NOW(), decided_by_profile_id = :by
              WHERE id = :id AND tenant_id = :t'
        )->execute([':s' => $status, ':by' => $by, ':id' => $offerId, ':t' => $tenantId]);
    }

    public function displayName(int $profileId): string
    {
        // Profiles is a sanctioned GLOBAL table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT display_name FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);
        $name = $stmt->fetchColumn();
        return is_string($name) ? $name : '';
    }

    /** @param array<string, mixed> $metadata */
    private function record(string $action, int $tenantId, int $actorId, int $targetProfileId, array $metadata): void
    {
        $this->audit?->record($action, [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $actorId,
            'target_type'   => 'profile',
            'target_id'     => $targetProfileId,
            'metadata'      => $metadata,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function announce(string $event, array $payload): void
    {
        if ($this->hooks === null) {
            return;
        }
        try {
            $this->hooks->dispatch($event, $payload);
        } catch (\Throwable $e) {
            error_log('[workspace-ownership] ' . $event . ' listeners failed: ' . $e->getMessage());
        }
    }
}
