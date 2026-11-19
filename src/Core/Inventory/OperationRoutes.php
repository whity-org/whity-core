<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RouteQuorum;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Settings\SettingsRegistry;

/**
 * Issues the route an adjustment or a count's differences travels when it is
 * worth more than the workspace's value (WHIT-1233, design 31 §4.2, register
 * IV.14, IV.15, IV.19), and the route a purchase order travels when the
 * workspace chose one (WHIT-1234, IV.12).
 *
 * The workspace picks a route template per kind in the inventory settings
 * (`inventory.adjustment_route_template_id`, `inventory.count_route_template_id`),
 * built in Flows with its own routing rules (the branch manager, a role, a
 * person). With none chosen, one step goes to everyone with
 * `inventory:settings` other than the person who wrote it, and the first
 * answer decides. Approving is never a permission of its own (IV.19).
 */
final class OperationRoutes implements OperationApprovals
{
    /**
     * @param \Closure(int): array<string, mixed> $settings the workspace's effective settings
     */
    public function __construct(
        private readonly InventoryDb $db,
        private readonly DocumentRouter $router,
        private readonly RouteTemplateRepository $templates,
        private readonly RoleChecker $roles,
        private readonly \Closure $settings,
    ) {
    }

    public function issue(int $tenantId, ?int $actor, array $operation): ?int
    {
        $kind = (string) $operation['kind'];
        $key = match ($kind) {
            'count' => SettingsRegistry::INVENTORY_COUNT_ROUTE_TEMPLATE_ID,
            'purchase' => SettingsRegistry::INVENTORY_PURCHASE_ROUTE_TEMPLATE_ID,
            default => SettingsRegistry::INVENTORY_ADJUSTMENT_ROUTE_TEMPLATE_ID,
        };
        $templateId = (int) (($this->settings)($tenantId)[$key] ?? 0);
        $template = $templateId > 0 ? $this->templates->findById($templateId, $tenantId) : null;
        $title = match ($kind) {
            'count' => 'Stock count ',
            'purchase' => 'Purchase order ',
            default => 'Stock adjustment ',
        } . (string) $operation['number'];
        if ($template === null && $kind === 'purchase') {
            // A purchase order travels only the route the workspace chose; with
            // none, the person sending it confirms it directly (IV.12, IV.19).
            return null;
        }

        if ($template !== null) {
            $steps = RouteTemplateInstantiation::toRouteSteps(
                $this->templates->stepsFor((int) $template['id'], $tenantId),
                $this->templates->edgesFor((int) $template['id'], $tenantId),
            );
        } else {
            $deciders = array_values(array_filter($this->deciders($tenantId), static fn (int $p): bool => $p !== $actor));
            if ($deciders === []) {
                return null;
            }
            $steps = [[
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => $deciders],
                'label' => $kind === 'count' ? 'Approve the count differences' : 'Approve the stock adjustment',
                'decision' => true,
                'decision_quorum' => RouteQuorum::FIRST,
            ]];
        }

        $issued = $this->router->issueOnSubject(
            $tenantId,
            $actor,
            $kind === 'purchase' ? OrderRouteSubject::PURCHASE_ORDER : OperationRouteSubject::typeFor($kind),
            (int) $operation['id'],
            null,
            $title,
            $steps,
            $template !== null ? (int) $template['id'] : null,
            $template !== null ? (string) $template['name'] : null,
        );

        return (int) $issued['route']['id'];
    }

    /** @return list<int> the workspace's staff who run its inventory */
    private function deciders(int $tenantId): array
    {
        $out = [];
        foreach ($this->db->all(
            "SELECT DISTINCT profile_id FROM memberships
              WHERE tenant_id = :tenant_id AND status = 'active' AND kind = 'member'
              ORDER BY profile_id LIMIT 500",
            [':tenant_id' => $tenantId]
        ) as $row) {
            if ($this->roles->hasPermissionForProfile((int) $row['profile_id'], CorePermissions::INVENTORY_SETTINGS, $tenantId)) {
                $out[] = (int) $row['profile_id'];
            }
        }

        return $out;
    }
}
