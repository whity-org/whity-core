<?php

declare(strict_types=1);

namespace Whity\Core\Signup;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\RBAC\CorePermissions;

/**
 * The route a join request travels on (merge map A4).
 *
 * The workspace's chosen route template (`signup.route_template_id`, on the
 * Customer sign-up screen), or by default one approval step for everyone who
 * can manage sign-ups (`signup:manage`) when the request arrives, where the
 * first of them to answer decides, approve or turn away (`first`).
 */
final class CustomerSignupRoutes
{
    public function __construct(
        private readonly PDO $db,
        private readonly DocumentRouter $router,
        private readonly RouteRepository $routes,
        private readonly RouteTemplateRepository $templates,
        private readonly WorkspaceSignupSettings $settings,
        private readonly RoleChecker $roles,
    ) {
    }

    /** Issue the request's route, unless it has one already. Returns the route id. */
    public function issue(int $tenantId, int $signupId): ?int
    {
        $open = $this->openRoute($tenantId, $signupId);
        if ($open !== null) {
            return (int) $open['id'];
        }
        $stmt = $this->db->prepare(
            "SELECT profile_id, display_name FROM customer_signups WHERE id = :id AND tenant_id = :tenant_id AND status = 'pending'"
        );
        $stmt->execute([':id' => $signupId, ':tenant_id' => $tenantId]);
        $signup = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($signup)) {
            return null;
        }

        $templateId = $this->settings->get($tenantId)['route_template_id'];
        $template = $templateId !== null ? $this->templates->findById($templateId, $tenantId) : null;
        if ($template !== null) {
            $steps = RouteTemplateInstantiation::toRouteSteps(
                $this->templates->stepsFor((int) $template['id'], $tenantId),
                $this->templates->edgesFor((int) $template['id'], $tenantId),
            );
        } else {
            $deciders = $this->deciders($tenantId);
            if ($deciders === []) {
                return null;
            }
            $steps = [[
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => $deciders],
                'label' => 'Approve the join request',
                'decision' => true,
                // Owner, 2026-11-16: the first answer decides, either way.
                'decision_quorum' => \Whity\Core\Document\Routing\RouteQuorum::FIRST,
            ]];
        }

        $issued = $this->router->issueOnSubject(
            $tenantId,
            $signup['profile_id'] !== null ? (int) $signup['profile_id'] : null,
            CustomerSignupRouteSubject::TYPE,
            $signupId,
            null,
            'Join request: ' . (string) $signup['display_name'],
            $steps,
            $template !== null ? (int) $template['id'] : null,
            $template !== null ? (string) $template['name'] : null,
        );

        return (int) $issued['route']['id'];
    }

    /**
     * The request's route while something on it is still open.
     *
     * @return array<string, mixed>|null
     */
    public function openRoute(int $tenantId, int $signupId): ?array
    {
        foreach ($this->routes->listForSubject(CustomerSignupRouteSubject::TYPE, $signupId, $tenantId) as $route) {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM document_route_recipients WHERE tenant_id = :tenant_id AND route_id = :route_id AND closed_by_event_id IS NULL LIMIT 1'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':route_id' => (int) $route['id']]);
            if ($stmt->fetchColumn() !== false) {
                return $route;
            }
        }

        return null;
    }

    /** @return list<int> the workspace's staff who can manage sign-ups */
    private function deciders(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT profile_id FROM memberships
              WHERE tenant_id = :tenant_id AND status = 'active' AND kind = 'member'
              ORDER BY profile_id LIMIT 500"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $profileId) {
            if ($this->roles->hasPermissionForProfile((int) $profileId, CorePermissions::SIGNUP_MANAGE, $tenantId)) {
                $out[] = (int) $profileId;
            }
        }

        return $out;
    }
}
