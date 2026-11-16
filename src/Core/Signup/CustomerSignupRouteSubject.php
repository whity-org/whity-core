<?php

declare(strict_types=1);

namespace Whity\Core\Signup;

use PDO;
use Whity\Core\Document\Routing\RouteSubjectApprovedInterface;
use Whity\Core\Document\Routing\RouteSubjectProviderInterface;
use Whity\Core\Document\Routing\RouteSubjectRejectedInterface;
use Whity\Core\Document\Routing\RoutingRejectedException;

/**
 * The `customer_signup` route subject (merge map A4): a customer's request to
 * join a workspace, decided on a route like any other business decision.
 *
 * By default the route is one step for everyone who can manage sign-ups; a
 * workspace can choose a route template of its own instead
 * ({@see CustomerSignupRoutes}). Because it is a route step, it shows in
 * Waiting for me, reads "I'm away", and records who decided.
 *
 * When the route finishes approved the person joins with the starting role;
 * when it finishes not approved they are turned away. A request that can't be
 * approved (the starting role is no longer customer-safe, or the person has a
 * suspended membership or an open invitation) refuses the act, so nothing is
 * recorded as approved that didn't happen.
 *
 * There is nothing to send back to: the person asking isn't a member yet.
 */
final class CustomerSignupRouteSubject implements RouteSubjectProviderInterface, RouteSubjectApprovedInterface, RouteSubjectRejectedInterface
{
    public const TYPE = 'customer_signup';

    public function __construct(
        private readonly PDO $db,
        private readonly CustomerSignupService $signups,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function title(int $tenantId, int $subjectId): ?string
    {
        $row = $this->row($tenantId, $subjectId);

        return $row === null ? null : 'Join request: ' . (string) $row['display_name'];
    }

    public function documentId(int $tenantId, int $subjectId): ?int
    {
        return null;
    }

    public function author(int $tenantId, int $subjectId): ?int
    {
        return null;
    }

    public function snapshot(int $tenantId, int $subjectId): ?array
    {
        $row = $this->row($tenantId, $subjectId);

        return $row === null ? null : [
            'name' => (string) $row['display_name'],
            'email' => (string) $row['email'],
            'asked_at' => (string) $row['created_at'],
        ];
    }

    public function editableWhenSentBack(): bool
    {
        return false;
    }

    /**
     * Gone, or replaced by a newer request from the same person. A decided
     * request is not withdrawn: a later answer on its route is told who
     * decided it ({@see \Whity\Core\Document\Routing\DocumentRouter}).
     */
    public function isWithdrawn(int $tenantId, int $subjectId): bool
    {
        $row = $this->row($tenantId, $subjectId);

        return $row === null || (string) $row['status'] === CustomerSignupService::STATUS_SUPERSEDED;
    }

    public function approved(int $tenantId, int $subjectId, int $routeId): void
    {
        $result = $this->signups->approve($subjectId, $tenantId, $this->decidedBy($tenantId, $routeId));
        match ($result['result']) {
            CustomerSignupService::DECIDE_DONE => null,
            CustomerSignupService::DECIDE_ROLE_UNSAFE => throw RoutingRejectedException::because(
                'The starting role in Sign-up settings is missing or has admin permissions. Choose a customer-safe role first.'
            ),
            CustomerSignupService::DECIDE_CONFLICT => throw RoutingRejectedException::because(
                'This person already has a suspended membership or an open invitation here. Resolve that in People first.'
            ),
            default => throw RoutingRejectedException::because('This join request has already been decided.'),
        };
    }

    public function rejected(int $tenantId, int $subjectId, int $routeId): void
    {
        $this->signups->reject($subjectId, $tenantId, $this->decidedBy($tenantId, $routeId));
    }

    /** Who gave the route's last verdict: recorded on the request as who decided. */
    private function decidedBy(int $tenantId, int $routeId): int
    {
        $stmt = $this->db->prepare(
            'SELECT actor_profile_id FROM document_route_events
              WHERE tenant_id = :tenant_id AND route_id = :route_id AND verdict IS NOT NULL
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':route_id' => $routeId]);
        $actor = $stmt->fetchColumn();

        return $actor === false || $actor === null ? 0 : (int) $actor;
    }

    /** @return array<string, mixed>|null */
    private function row(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, email, display_name, status, created_at FROM customer_signups WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
