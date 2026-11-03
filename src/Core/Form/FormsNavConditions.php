<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;

/**
 * Whether the sidebar's Forms entries are worth showing to this person.
 *
 * Opening a form to members (migration 198) grants no permission, so a permission gate
 * can't decide this. Showing the entries to every member would change everybody's
 * sidebar on upgrade and point most of them at an empty page. So they appear only once
 * there is something behind them. They grant nothing: the routes decide for themselves.
 *
 * Stateless apart from the injected collaborators — worker-safe.
 */
final class FormsNavConditions
{
    public function __construct(private readonly PDO $db, private readonly RoleChecker $roles)
    {
    }

    /**
     * The catalogue for somebody who can't already open it from `forms-catalog`.
     */
    public function hasFormsOpenToThem(int $profileId, int $tenantId): bool
    {
        return !$this->roles->hasPermissionForProfile($profileId, CorePermissions::FORMS_READ, $tenantId)
            && $this->isActiveMember($profileId, $tenantId)
            && $this->anyFormOpen($tenantId);
    }

    /**
     * My submissions: for whoever may fill forms in, or has already sent one, because
     * losing the ability to send doesn't end somebody's interest in what they sent.
     */
    public function hasOwnSubmissionsToSee(int $profileId, int $tenantId): bool
    {
        if ($this->roles->hasPermissionForProfile($profileId, CorePermissions::FORMS_SUBMIT, $tenantId)) {
            return true;
        }
        if ($this->isActiveMember($profileId, $tenantId) && $this->anyFormOpen($tenantId)) {
            return true;
        }

        $stmt = $this->db->prepare(
            'SELECT 1 FROM form_submissions
              WHERE tenant_id = :tenant_id AND submitted_by_profile_id = :profile_id
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function anyFormOpen(int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM forms
              WHERE tenant_id = :tenant_id AND status = :status AND open_to_members = TRUE
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':status' => FormStatus::PUBLISHED]);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function isActiveMember(int $profileId, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM memberships
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND status = :status
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId, ':status' => 'active']);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
}
