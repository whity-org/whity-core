<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RouteQuorum;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Notification\NotificationDispatcher;
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
 *
 * NOBODY TO APPROVE FAILS CLOSED (WHIT-1301). When no one but the person who
 * wrote it holds `inventory:settings`, {@see issue()} answers null and the
 * operation keeps waiting; {@see nobodyCanApprove()} tells the settings
 * holders and the owners how to unblock it.
 */
final class OperationRoutes implements OperationApprovals
{
    /** The notice that an operation waits with nobody to approve it. */
    public const TYPE_NOBODY_CAN_APPROVE = 'inventory.approval.nobody';

    /**
     * @param \Closure(int): array<string, mixed> $settings the workspace's effective settings
     */
    public function __construct(
        private readonly InventoryDb $db,
        private readonly DocumentRouter $router,
        private readonly RouteTemplateRepository $templates,
        private readonly RoleChecker $roles,
        private readonly \Closure $settings,
        private readonly ?NotificationDispatcher $notifications = null,
    ) {
    }

    public function nobodyCanApprove(int $tenantId, ?int $actor, array $operation): void
    {
        if ($this->notifications === null) {
            return;
        }
        $people = $this->deciders($tenantId);
        foreach ($this->db->all(
            'SELECT profile_id FROM workspace_owners WHERE tenant_id = :tenant_id ORDER BY profile_id LIMIT 50',
            [':tenant_id' => $tenantId]
        ) as $row) {
            $people[] = (int) $row['profile_id'];
        }
        $what = ((string) $operation['kind'] === 'count' ? 'Stock count ' : 'Stock adjustment ') . (string) $operation['number'];
        foreach (array_values(array_unique($people)) as $profileId) {
            try {
                $this->notifications->dispatch($tenantId, $profileId, self::TYPE_NOBODY_CAN_APPROVE, [
                    'subject' => 'Nobody can approve ' . $what . ' yet',
                    'body' => $what . ' is waiting for approval, and nobody can approve it yet. '
                        . 'Give someone the inventory settings permission, or choose an approval route in the inventory settings. '
                        . 'Then send it again.',
                    'data' => [
                        'operation_id' => (int) $operation['id'],
                        'kind' => (string) $operation['kind'],
                        'number' => (string) $operation['number'],
                        'acting_profile_id' => $actor,
                    ],
                ]);
            } catch (\Throwable $e) {
                // One person who could not be told must not stop the rest.
                error_log('[OperationRoutes] nobody-can-approve notice to profile ' . $profileId . ' failed: ' . $e->getMessage());
            }
        }
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
