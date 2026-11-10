<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Container\HostWiredService;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Print\WhityDocumentPrinter;
use Whity\Core\Document\Slots\BackgroundIssuing;
use Whity\Core\Document\Slots\SlotBindings;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Sdk\Documents\TemplateField;
use Whity\Sdk\Documents\TemplateInfo;
use Whity\Sdk\Documents\Templates;

/**
 * The host side of the SDK's template reader (SDK 1.82, WHIT-996).
 *
 * It answers for the caller {@see SdkActor} names, exactly as issuing does:
 *  - a person sees the published templates the editor shows them
 *    ({@see DocumentAccessPolicy} with their permissions and units);
 *  - a plugin in the background sees only the templates bound to its own
 *    slots, and only in a workspace that allowed it to issue there.
 *
 * Each template is described from its published version, which is what an
 * issue prints, never from the draft.
 */
final class SdkTemplates implements Templates, HostWiredService
{
    public const MAX_LIST = 200;

    /**
     * @param (\Closure(string): (array{plugin: string, permissions: list<string>}|null))|null $jobPlugin
     */
    public function __construct(
        private readonly DocumentSourceRepository $sources,
        private readonly DocumentSourceVersionRepository $versions,
        private readonly DocumentAccessPolicy $policy,
        private readonly OuReachResolver $reach,
        private readonly RoleChecker $roleChecker,
        private readonly SettingsService $settings,
        private readonly ?\Closure $jobPlugin = null,
        private readonly ?TemplateSlotRegistry $slots = null,
    ) {
    }

    /**
     * @param (\Closure(string): (array{plugin: string, permissions: list<string>}|null))|null $jobPlugin
     */
    public static function build(
        PDO $pdo,
        RoleChecker $roleChecker,
        ResourceTypeRegistry $resourceTypes,
        SettingsService $settings,
        ?TemplateSlotRegistry $slots = null,
        ?\Closure $jobPlugin = null,
    ): self {
        return new self(
            new DocumentSourceRepository($pdo),
            new DocumentSourceVersionRepository($pdo),
            new DocumentAccessPolicy(),
            new OuReachResolver($pdo, new ResourceRoleAssignmentRepository($pdo, $resourceTypes)),
            $roleChecker,
            $settings,
            $jobPlugin,
            $slots,
        );
    }

    public function canRead(): bool
    {
        $actor = $this->actor();

        return $actor !== null && ($actor->plugin === null || $this->backgroundAllowed($actor));
    }

    public function list(?string $kind = null, ?string $recordType = null): array
    {
        $actor = $this->actor();
        if ($actor === null) {
            return [];
        }
        $bindings = $this->bindings($actor->tenantId);

        if ($actor->plugin !== null) {
            if (!$this->backgroundAllowed($actor)) {
                return [];
            }
            $rows = [];
            foreach (array_unique($this->ownBindings($actor->plugin, $bindings)) as $id) {
                $row = $this->sources->findById($id, $actor->tenantId);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        } else {
            $rows = array_values(array_filter(
                $this->sources->listForTenant($actor->tenantId, $kind),
                fn (array $row): bool => $this->personCanSee($actor, $row),
            ));
        }

        $out = [];
        foreach ($rows as $row) {
            if (($row['published_version'] ?? null) === null
                || ($kind !== null && $row['kind'] !== $kind)
                || ($recordType !== null && ($row['record_type'] ?? null) !== $recordType)) {
                continue;
            }
            $info = $this->describe($actor->tenantId, $row, $bindings);
            if ($info !== null) {
                $out[] = $info;
            }
            if (count($out) >= self::MAX_LIST) {
                break;
            }
        }

        return $out;
    }

    public function find(int $id): ?TemplateInfo
    {
        $actor = $this->actor();
        if ($actor === null || $id <= 0) {
            return null;
        }
        $bindings = $this->bindings($actor->tenantId);
        if ($actor->plugin !== null) {
            if (!$this->backgroundAllowed($actor) || !in_array($id, $this->ownBindings($actor->plugin, $bindings), true)) {
                return null;
            }
            $row = $this->sources->findById($id, $actor->tenantId);
        } else {
            $row = $this->sources->findById($id, $actor->tenantId);
            if ($row !== null && !$this->personCanSee($actor, $row)) {
                $row = null;
            }
        }
        if ($row === null || ($row['published_version'] ?? null) === null) {
            return null;
        }

        return $this->describe($actor->tenantId, $row, $bindings);
    }

    public function forSlot(string $slotKey): ?TemplateInfo
    {
        $actor = $this->actor();
        if ($actor === null) {
            return null;
        }
        $id = $this->bindings($actor->tenantId)[$slotKey] ?? null;

        return $id === null ? null : $this->find($id);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $bindings
     */
    private function describe(int $tenantId, array $row, array $bindings): ?TemplateInfo
    {
        $version = $this->versions->find((int) $row['id'], $tenantId, (int) $row['published_version']);
        if ($version === null) {
            return null;
        }

        return new TemplateInfo(
            (int) $row['id'],
            $version['title'],
            (string) $row['kind'],
            (string) $row['lang'],
            is_string($row['record_type'] ?? null) ? $row['record_type'] : null,
            $version['version'],
            array_keys(array_filter($bindings, static fn (int $bound): bool => $bound === (int) $row['id'])),
            self::fields($version['blocks'], $version['own_fields']),
        );
    }

    /**
     * Every merge field the version prints, each once, in the order it first
     * appears; the template's own fields it declares but doesn't print yet
     * come last, since an issue may still pass them.
     *
     * @param list<mixed> $blocks
     * @param list<mixed> $ownFields
     * @return list<TemplateField>
     */
    public static function fields(array $blocks, array $ownFields): array
    {
        $own = [];
        foreach ($ownFields as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                $own['template.' . $field['key']] = $field;
            }
        }

        $fields = [];
        foreach (WhityDocumentPrinter::fieldRefs($blocks) as $ref) {
            $path = (string) $ref['path'];
            $required = ($ref['required'] ?? false) === true;
            if (isset($fields[$path])) {
                $fields[$path]['required'] = $fields[$path]['required'] || $required;
                continue;
            }
            $label = is_string($ref['label'] ?? null) && $ref['label'] !== '' ? $ref['label']
                : (is_string($own[$path]['label'] ?? null) ? $own[$path]['label'] : $path);
            $fields[$path] = ['label' => $label, 'required' => $required];
        }
        foreach ($own as $path => $field) {
            if (!isset($fields[$path])) {
                $fields[$path] = ['label' => is_string($field['label'] ?? null) ? $field['label'] : $path, 'required' => false];
            }
        }

        $out = [];
        foreach ($fields as $path => $field) {
            $sample = $own[$path]['sample'] ?? null;
            $out[] = new TemplateField(
                (string) $path,
                $field['label'],
                TemplateField::sourceOf((string) $path),
                $field['required'],
                is_string($sample) ? $sample : null,
            );
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function personCanSee(ActingAs $actor, array $row): bool
    {
        return $this->policy->canView(
            $row,
            $actor->profileId,
            ScopedPermissionSet::forProfile($this->roleChecker, $actor->profileId, $actor->tenantId),
            $this->reach->reachFor($actor->tenantId, $actor->profileId),
        );
    }

    private function backgroundAllowed(ActingAs $actor): bool
    {
        $effective = $this->settings->effective($actor->tenantId);

        return in_array($actor->plugin, BackgroundIssuing::parse((string) ($effective[SettingsRegistry::DOCUMENTS_BACKGROUND_ISSUING] ?? '')), true);
    }

    /**
     * @param array<string, int> $bindings
     * @return list<int> the templates bound to this plugin's own slots
     */
    private function ownBindings(string $plugin, array $bindings): array
    {
        $ids = [];
        foreach ($bindings as $key => $id) {
            if (str_starts_with($key, $plugin . '.')) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return array<string, int> */
    private function bindings(int $tenantId): array
    {
        return SlotBindings::parse((string) ($this->settings->effective($tenantId)[SettingsRegistry::DOCUMENTS_SLOT_BINDINGS] ?? ''));
    }

    private function actor(): ?ActingAs
    {
        return (new SdkActor($this->roleChecker, $this->jobPlugin, $this->slots))->current();
    }
}
