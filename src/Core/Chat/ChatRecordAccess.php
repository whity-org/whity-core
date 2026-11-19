<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

use PDO;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Rbac\PermissionResolver;

/**
 * Whether a person may see a Whity record that Chat talks about (WHIT-1266,
 * #2231, #2232): the record a chat is linked to ("Discuss in chat"), and the
 * bookings and documents put inside messages as blocks.
 *
 * Each kind is asked the way its own API asks it, so Chat never shows or opens
 * more than the record's own screen would:
 *
 *   booking          `calendar:read`, and the booking is in the workspace
 *                    (GET /api/calendar/bookings/{id}).
 *   document         `documents:read` and {@see DocumentVisibilityPolicy}
 *                    (GET /api/documents/{id}).
 *   form_submission  the submission is in the workspace and the person holds
 *                    `forms:read`, sent it, or a route on it reached them
 *                    (GET /api/form-submissions/{id}, /api/me/…, the routes API).
 *   meeting          `convening:read`, and the meeting is in the workspace
 *                    (GET /api/meetings/{id}).
 *   board            `tasks:read` (GET /api/tasks/boards/{board}). Boards live
 *                    in Taskaat, so Whity cannot check the board itself.
 *
 * A record id of a kind core does not know (a plugin's, such as
 * "keyhub.project:4") answers null: core cannot tell. The SDK interface for
 * plugins to answer for their own kinds is a later task.
 */
final class ChatRecordAccess
{
    public const KINDS = ['booking', 'document', 'form_submission', 'meeting'];

    public function __construct(
        private readonly PDO $db,
        private readonly PermissionResolver $permissions,
        private readonly DocumentRepository $documents,
        private readonly DocumentVisibilityPolicy $visibility,
        private readonly RouteRecipientRepository $routeRecipients,
    ) {
    }

    /**
     * A chat link: true or false for kinds core knows, null for others.
     *
     * @param string $type `board`, `meeting` or `record`
     * @param string $id   the link's id: a board's or a meeting's own id, or `kind:id` for a record
     */
    public function canSeeLink(int $tenantId, int $profileId, string $type, string $id): ?bool
    {
        if ($type === 'board') {
            return $this->can($tenantId, $profileId, CorePermissions::TASKS_READ);
        }
        if ($type === 'meeting') {
            return $this->canSee($tenantId, $profileId, 'meeting', self::numericId($id));
        }
        if ($type !== 'record') {
            return null;
        }
        $at = strpos($id, ':');
        $kind = $at === false ? $id : substr($id, 0, $at);
        if (!in_array($kind, self::KINDS, true)) {
            return null;
        }

        return $this->canSee($tenantId, $profileId, $kind, self::numericId($at === false ? '' : substr($id, $at + 1)));
    }

    /** One record of a kind core knows. An id of 0 (a malformed one) is never seen. */
    public function canSee(int $tenantId, int $profileId, string $kind, int $id): bool
    {
        if ($id <= 0 || $profileId <= 0) {
            return false;
        }

        return match ($kind) {
            'booking' => $this->can($tenantId, $profileId, CorePermissions::CALENDAR_READ)
                && $this->exists('SELECT 1 FROM calendar_bookings WHERE tenant_id = :tid AND id = :id', $tenantId, $id),
            'meeting' => $this->can($tenantId, $profileId, CorePermissions::CONVENING_READ)
                && $this->exists('SELECT 1 FROM meetings WHERE tenant_id = :tid AND id = :id', $tenantId, $id),
            'document' => $this->canSeeDocument($tenantId, $profileId, $id),
            'form_submission' => $this->canSeeSubmission($tenantId, $profileId, $id),
            default => false,
        };
    }

    /**
     * Which documents a search may return: null when the person may not read
     * documents at all, otherwise the visibility restriction
     * ({@see DocumentVisibilityPolicy::restrictToCreator()}): `0` for the whole
     * workspace, or their profile id for "mine, routed to me or granted to me".
     */
    public function documentScope(int $tenantId, int $profileId): ?int
    {
        if ($profileId <= 0 || !$this->can($tenantId, $profileId, CorePermissions::DOCUMENTS_READ)) {
            return null;
        }

        return $this->visibility->restrictToCreator($profileId, $this->resolver($tenantId, $profileId)) ?? 0;
    }

    public function can(int $tenantId, int $profileId, string $permission): bool
    {
        return $profileId > 0 && $this->permissions->hasPermission($profileId, $tenantId, $permission);
    }

    private function canSeeDocument(int $tenantId, int $profileId, int $id): bool
    {
        if (!$this->can($tenantId, $profileId, CorePermissions::DOCUMENTS_READ)) {
            return false;
        }
        $document = $this->documents->findById($id, $tenantId);

        return $document !== null && $this->visibility->canView($document, $profileId, $this->resolver($tenantId, $profileId));
    }

    private function canSeeSubmission(int $tenantId, int $profileId, int $id): bool
    {
        $stmt = $this->db->prepare('SELECT submitted_by_profile_id FROM form_submissions WHERE tenant_id = :tid AND id = :id');
        $stmt->execute([':tid' => $tenantId, ':id' => $id]);
        $by = $stmt->fetchColumn();
        if ($by === false) {
            return false;
        }

        return ($by !== null && (int) $by === $profileId)
            || $this->can($tenantId, $profileId, CorePermissions::FORMS_READ)
            || $this->routeRecipients->hasAnyOnSubject($tenantId, 'form_submission', $id, $profileId);
    }

    /** @return callable(string): bool */
    private function resolver(int $tenantId, int $profileId): callable
    {
        return fn (string $permission): bool => $this->can($tenantId, $profileId, $permission);
    }

    private function exists(string $sql, int $tenantId, int $id): bool
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tid' => $tenantId, ':id' => $id]);

        return $stmt->fetchColumn() !== false;
    }

    private static function numericId(string $id): int
    {
        return preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1 ? (int) $id : 0;
    }
}
