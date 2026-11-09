<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Settings\SettingsService;

/**
 * The issuing service outside a request (WHIT-742, DOCUMENT-AUTOMATION.md
 * 3.5): `queue:work` runs automations and plugin jobs that issue documents,
 * and CLI commands may too, so they need the same {@see TemplateIssuing} the REST route
 * uses, built from the same parts `public/index.php` wires: the render
 * service, per-tenant storage, the meters, the QR code, the letterhead and
 * the workspace's themes.
 *
 * Built only from the environment and the connection, as the CLI kernel has
 * no request. A template's visibility is checked exactly as the editor checks
 * it ({@see DocumentAccessPolicy} with the actor's units).
 */
final class WorkerDocumentIssuing
{
    private function __construct()
    {
    }

    /**
     * Pass the process's {@see WorkerDocumentParts} when something else in it
     * renders too (the SDK's DocumentRenderer, #1151), so both share one
     * storage driver.
     *
     * @param array<string, mixed> $env
     */
    public static function build(
        PDO $pdo,
        SettingsService $settings,
        HookManager $hooks,
        RoleChecker $roleChecker,
        ResourceTypeRegistry $resourceTypes,
        DocumentFieldRegistry $fields,
        DocumentEmbedRegistry $embeds,
        TemplateSlotRegistry $slots,
        array $env,
        string $storageRoot,
        ?WorkerDocumentParts $parts = null,
    ): TemplateIssuing {
        $parts ??= new WorkerDocumentParts($pdo, $settings, $hooks, $fields, $embeds, $env, $storageRoot);
        $sources = new DocumentSourceRepository($pdo);
        $policy = new DocumentAccessPolicy();
        $reach = new OuReachResolver($pdo, new ResourceRoleAssignmentRepository($pdo, $resourceTypes));

        return new TemplateIssuing(
            // Built on the first issue: a command that never issues (migrate,
            // a backup) must not need the render service, storage or the key.
            static fn (): DocumentIssuing => $parts->issuing(),
            $fields,
            $settings,
            static function (int $id, int $tenantId, int $profileId) use ($sources, $policy, $reach, $roleChecker): ?array {
                $row = $sources->findById($id, $tenantId);
                if ($row === null || !$policy->canView(
                    $row,
                    $profileId,
                    ScopedPermissionSet::forProfile($roleChecker, $profileId, $tenantId),
                    $reach->reachFor($tenantId, $profileId),
                )) {
                    return null;
                }

                return $row;
            },
            $slots,
            // A plugin's job issuing with no person (WHIT-742 slice 3): its slot's
            // binding names the template, which is shared with the workspace.
            static fn (int $id, int $tenantId): ?array => $sources->findById($id, $tenantId),
        );
    }
}
