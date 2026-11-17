<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Throwable;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Plugin\PluginOperatorGate;
use Whity\Core\Plugin\Workspace\CodePluginActivation;
use Whity\Core\PluginLoader;
use Whity\Core\PluginState;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Installed code plugins, switched on per workspace (WHIT-1122).
 *
 *   GET  /api/workspace/code-plugins                      plugins:read
 *   POST /api/workspace/code-plugins/{plugin}/enable      plugins:enable
 *   POST /api/workspace/code-plugins/{plugin}/disable     plugins:disable
 *   POST /api/workspace/code-plugins/{plugin}/trial       plugins:enable (WHIT-1125)
 *   GET  /api/workspace/onboarding                        plugins:read (WHIT-1148)
 *   GET  /api/workspace/code-plugins/{plugin}/history     plugins:read
 *   GET  /api/plugins/workspace-access                    plugins:read, operator only
 *   PUT  /api/plugins/{plugin}/workspace-access {access, freeForAll?}
 *                                                         plugins:enable, operator only
 *
 * A workspace sees every active plugin the operator hasn't kept to the
 * system workspace. Only a `choose` plugin has a switch; an `all` plugin is
 * always on. Turning a plugin off keeps its data, and every change is audited
 * (`code_plugin.enabled`, `code_plugin.disabled`,
 * `code_plugin.access_changed`).
 */
final class WorkspaceCodePluginsApiHandler
{
    public const AUDIT_ENABLED = 'code_plugin.enabled';
    public const AUDIT_DISABLED = 'code_plugin.disabled';
    public const AUDIT_ACCESS = 'code_plugin.access_changed';
    public const AUDIT_TRIAL = 'code_plugin.trial_started';

    private const HISTORY_LIMIT = 50;

    public function __construct(
        private readonly PluginLoader $loader,
        private readonly CodePluginActivation $activation,
        private readonly PDO $db,
        private readonly ?AuditLogger $audit = null,
        // WHIT-1148: who may see a plugin's guided setup, and in which language.
        private readonly ?\Whity\Auth\RoleChecker $roles = null,
        private readonly ?\Closure $language = null,
    ) {
    }

    private ?\Whity\Core\Plugin\Workspace\PluginSeats $seats = null;

    public function useSeats(\Whity\Core\Plugin\Workspace\PluginSeats $seats): void
    {
        $this->seats = $seats;
    }

    /** @var (\Closure(int): list<array<string, mixed>>)|null */
    private ?\Closure $templateOnboardings = null;

    /**
     * WHIT-1195: the guided setups of the templates a workspace chose join
     * the plugins' setups, under the names `template-{key}`.
     *
     * @param \Closure(int): list<array<string, mixed>> $onboardings by workspace
     */
    public function useTemplateOnboardings(\Closure $onboardings): void
    {
        $this->templateOnboardings = $onboardings;
    }

    /**
     * GET /api/workspace/onboarding: the guided setups (SDK 1.109) of the
     * plugins on in this workspace that the caller may run, in their language.
     */
    public function onboarding(Request $request): Response
    {
        return $this->inWorkspace(function (int $tenantId) use ($request): Response {
            $profileId = self::profileId($request);
            $language = $this->language !== null ? (string) ($this->language)() : 'en';
            $data = [];
            $templates = $this->templateOnboardings !== null ? ($this->templateOnboardings)($tenantId) : [];
            foreach ([...$this->loader->getOnboardings(), ...$templates] as $onboarding) {
                $permission = (string) $onboarding['permission'];
                if ($this->roles !== null && ($profileId === null || !$this->roles->hasPermissionForProfile($profileId, $permission, $tenantId))) {
                    continue;
                }
                $steps = [];
                foreach ($onboarding['steps'] as $step) {
                    $steps[] = [
                        'id' => $step['id'],
                        'title' => self::text($step['title'], $language),
                        'description' => self::text($step['description'] ?? null, $language),
                        'blocks' => array_map(
                            static fn (array $block): array => \Whity\Sdk\Frontend\Blocks\BlockText::resolve($block, $language),
                            $step['blocks'],
                        ),
                    ];
                }
                $data[] = [
                    'plugin' => $onboarding['plugin'],
                    'title' => self::text($onboarding['title'], $language),
                    'description' => self::text($onboarding['description'] ?? null, $language),
                    'status' => $onboarding['status'],
                    'steps' => $steps,
                ];
            }

            return Response::json(['data' => $data]);
        });
    }

    /** `{en, ar}` in the reader's language, English when that one is missing. */
    private static function text(mixed $value, string $language): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }
        $text = $value[$language] ?? $value['en'] ?? null;

        return is_string($text) ? $text : null;
    }

    public function list(Request $request): Response
    {
        return $this->inWorkspace(function (int $tenantId): Response {
            $rows = $this->activation->workspaceRows($tenantId);
            $onboarding = $this->loader->pluginsWithOnboarding();
            $data = [];
            foreach ($this->activePlugins() as $name => $version) {
                $access = $this->activation->accessOf($name);
                if ($access === CodePluginActivation::OPERATOR) {
                    continue;
                }
                $row = $rows[$name] ?? null;
                $state = $this->activation->stateFor($name, $tenantId);
                $paid = $this->activation->paidAccessOf($name);
                $data[] = [
                    'name' => $name,
                    'version' => $version,
                    'access' => $access,
                    'canSwitch' => $access === CodePluginActivation::CHOOSE,
                    'enabled' => $state['state'] !== CodePluginActivation::STATE_OFF,
                    'changedAt' => $row['changed_at'] ?? null,
                    'changedBy' => $row['changed_by'] ?? null,
                    // WHIT-1125: what a paid plugin needs, and where it stands.
                    'state' => $state['state'],
                    'reason' => $state['reason'],
                    'graceUntil' => $state['graceUntil'],
                    'trialEndsAt' => $state['trialEndsAt'],
                    'paid' => $paid !== null,
                    'product' => $paid['product'] ?? null,
                    'trialDays' => $paid['trialDays'] ?? 0,
                    'trialAvailable' => $this->activation->trialAvailable($name, $tenantId),
                    'mayTurnOn' => $this->activation->mayTurnOn($name, $tenantId),
                    // WHIT-1148: it has a guided setup, and what it needs here.
                    'onboarding' => in_array($name, $onboarding, true),
                    'dependsOn' => $this->loader->workspaceDependenciesOf($name),
                    // SDK 1.110: sold per seat, and how many this workspace has.
                    'seats' => $this->seats?->of($name, $tenantId),
                ];
            }

            return Response::json(['data' => $data]);
        });
    }

    /** @param array<string, string> $params */
    public function enable(Request $request, array $params = []): Response
    {
        return $this->setEnabled($request, $params, true);
    }

    /** @param array<string, string> $params */
    public function disable(Request $request, array $params = []): Response
    {
        return $this->setEnabled($request, $params, false);
    }

    /**
     * POST /api/workspace/code-plugins/{plugin}/trial: the workspace's one free
     * trial of a paid plugin, which also turns it on (WHIT-1125).
     *
     * @param array<string, string> $params
     */
    public function trial(Request $request, array $params = []): Response
    {
        return $this->inWorkspace(function (int $tenantId) use ($request, $params): Response {
            $name = $this->visiblePlugin((string) ($params['plugin'] ?? ''));
            if ($name === null) {
                return self::notFound();
            }

            return $this->startTrialFor($tenantId, self::profileId($request), $name);
        });
    }

    /**
     * Start a plugin's trial in this workspace, with every rule the Plugins
     * page applies: once per workspace, dependencies turned on, audited.
     * `$days` is a workspace template's own trial length (WHIT-1195).
     */
    public function startTrialFor(int $tenantId, ?int $profileId, string $name, ?int $days = null): Response
    {
        if ($this->visiblePlugin($name) === null) {
            return self::notFound();
        }
        if (!$this->activation->trialAvailable($name, $tenantId)) {
            return Response::error('This workspace has already had its trial, or the plugin offers none', 409, ['reason' => 'trial_unavailable']);
        }
        $blocked = $this->dependencyRefusal($name, $tenantId);
        if ($blocked !== null) {
            return $blocked;
        }

        $this->turnOnDependencies($name, $tenantId, $profileId);
        $this->activation->startTrial($tenantId, $name, $profileId, $days);
        $state = $this->activation->stateFor($name, $tenantId);
        $this->audit?->record(self::AUDIT_TRIAL, [
            'tenant_id' => $tenantId,
            'actor_user_id' => $profileId,
            'target_type' => 'code_plugin',
            'metadata' => array_filter(['plugin' => $name, 'until' => $state['trialEndsAt'], 'days' => $days], static fn (mixed $v): bool => $v !== null),
        ]);
        $this->loader->notifyWorkspaceSwitch($name, $tenantId, true);

        return Response::json(['data' => ['name' => $name, 'enabled' => true, 'trialEndsAt' => $state['trialEndsAt']]]);
    }

    /** @param array<string, string> $params */
    public function history(Request $request, array $params = []): Response
    {
        return $this->inWorkspace(function (int $tenantId) use ($params): Response {
            $name = $this->visiblePlugin((string) ($params['plugin'] ?? ''));
            if ($name === null) {
                return self::notFound();
            }

            $stmt = $this->db->prepare(
                "SELECT a.action, a.metadata, a.created_at, a.actor_user_id, p.display_name
                   FROM audit_log a
                   LEFT JOIN profiles p ON p.id = a.actor_user_id
                  WHERE a.tenant_id = :tenant_id AND a.target_type = 'code_plugin'
                  ORDER BY a.id DESC
                  LIMIT 500"
            );
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->execute();

            $events = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $meta = is_string($row['metadata']) ? json_decode($row['metadata'], true) : $row['metadata'];
                if (!is_array($meta) || ($meta['plugin'] ?? null) !== $name) {
                    continue;
                }
                $events[] = [
                    'action' => match ($row['action']) {
                        self::AUDIT_ENABLED => 'enabled',
                        self::AUDIT_TRIAL => 'trial_started',
                        default => 'disabled',
                    },
                    'at' => (string) $row['created_at'],
                    'by' => $row['actor_user_id'] !== null
                        ? ['id' => (int) $row['actor_user_id'], 'name' => (string) ($row['display_name'] ?? '')]
                        : null,
                ];
                if (count($events) >= self::HISTORY_LIMIT) {
                    break;
                }
            }

            return Response::json(['data' => $events]);
        });
    }

    /** The operator's view: every active plugin and who may use it. */
    public function accessList(Request $request): Response
    {
        if (($refusal = PluginOperatorGate::refusal()) !== null) {
            return $refusal;
        }

        $data = [];
        foreach ($this->activePlugins() as $name => $version) {
            $data[] = [
                'name' => $name,
                'version' => $version,
                'access' => $this->activation->accessOf($name),
                'freeForAll' => $this->activation->isFreeForAll($name),
            ];
        }

        return Response::json(['data' => $data]);
    }

    /** @param array<string, string> $params */
    public function setAccess(Request $request, array $params = []): Response
    {
        if (($refusal = PluginOperatorGate::refusal()) !== null) {
            return $refusal;
        }

        try {
            $name = $this->installedPlugin((string) ($params['plugin'] ?? ''));
            if ($name === null) {
                return self::notFound();
            }
            $body = JsonBody::parsed($request);
            $access = $body['access'] ?? null;
            $free = $body['freeForAll'] ?? null;
            if (!is_string($access) || !in_array($access, CodePluginActivation::ACCESS, true)) {
                return Response::error('Choose who can use it', 422, [
                    'reason' => 'invalid_access',
                    'errors' => ['access: one of ' . implode(', ', CodePluginActivation::ACCESS)],
                ]);
            }
            if ($free !== null && !is_bool($free)) {
                return Response::error('Choose who can use it', 422, [
                    'reason' => 'invalid_access',
                    'errors' => ['freeForAll: true or false'],
                ]);
            }

            $before = $this->activation->accessOf($name);
            $this->activation->setAccess($name, $access, $free, self::profileId($request));
            $this->audit?->record(self::AUDIT_ACCESS, [
                'tenant_id' => TenantContext::getTenantId() ?? 0,
                'actor_user_id' => self::profileId($request),
                'target_type' => 'code_plugin_access',
                'metadata' => ['plugin' => $name, 'from' => $before, 'to' => $access, 'freeForAll' => $this->activation->isFreeForAll($name)],
            ]);

            return Response::json(['data' => [
                'name' => $name,
                'access' => $access,
                'freeForAll' => $this->activation->isFreeForAll($name),
            ]]);
        } catch (Throwable $e) {
            error_log('[WorkspaceCodePluginsApiHandler] ' . $e->getMessage());

            return Response::error('The request could not be processed', 500);
        }
    }

    /** @param array<string, string> $params */
    private function setEnabled(Request $request, array $params, bool $enabled): Response
    {
        return $this->inWorkspace(function (int $tenantId) use ($request, $params, $enabled): Response {
            $name = $this->visiblePlugin((string) ($params['plugin'] ?? ''));
            if ($name === null) {
                return self::notFound();
            }
            if ($this->activation->accessOf($name) !== CodePluginActivation::CHOOSE) {
                return Response::error('This plugin is on for every workspace', 409, ['reason' => 'always_on']);
            }

            // A paid plugin needs a plan, an add-on or a trial first (WHIT-1125).
            if ($enabled && !$this->activation->mayTurnOn($name, $tenantId)) {
                $paid = $this->activation->paidAccessOf($name);

                return Response::error('This plugin needs a plan that includes it, a purchase or a trial', 402, [
                    'reason' => 'needs_purchase',
                    'product' => $paid['product'] ?? null,
                    'trialAvailable' => $this->activation->trialAvailable($name, $tenantId),
                ]);
            }

            if ($enabled) {
                $blocked = $this->dependencyRefusal($name, $tenantId);
                if ($blocked !== null) {
                    return $blocked;
                }
            } else {
                // A plugin another one here needs stays on (WHIT-1148).
                $needing = array_values(array_filter(
                    $this->loader->dependentsOf($name),
                    fn (string $dependent): bool => $this->activation->isVisible($dependent, $tenantId),
                ));
                if ($needing !== []) {
                    return Response::error('Other plugins here need this one; turn them off first', 409, ['reason' => 'required_by', 'plugins' => $needing]);
                }
            }

            $profileId = self::profileId($request);
            if ($enabled) {
                $this->turnOnDependencies($name, $tenantId, $profileId);
            }
            $this->activation->setEnabled($tenantId, $name, $enabled, $profileId);
            $this->audit?->record($enabled ? self::AUDIT_ENABLED : self::AUDIT_DISABLED, [
                'tenant_id' => $tenantId,
                'actor_user_id' => $profileId,
                'target_type' => 'code_plugin',
                'metadata' => ['plugin' => $name],
            ]);
            $this->loader->notifyWorkspaceSwitch($name, $tenantId, $enabled);

            return Response::json(['data' => ['name' => $name, 'enabled' => $enabled]]);
        });
    }

    /**
     * Why the plugins this one depends on can't be turned on here, or null
     * (WHIT-1148). A dependency the operator keeps to the platform, or a paid
     * one this workspace can't use yet, stops the switch.
     */
    private function dependencyRefusal(string $name, int $tenantId): ?Response
    {
        foreach ($this->loader->workspaceDependenciesOf($name) as $dependency) {
            if ($this->activation->isVisible($dependency, $tenantId)) {
                continue;
            }
            if ($this->activation->accessOf($dependency) === CodePluginActivation::OPERATOR) {
                return Response::error("{$name} needs {$dependency}, which this platform doesn't offer to workspaces", 409, ['reason' => 'dependency_unavailable', 'plugin' => $dependency]);
            }
            if (!$this->activation->mayTurnOn($dependency, $tenantId)) {
                return Response::error("{$name} needs {$dependency} first", 402, ['reason' => 'dependency_needs_purchase', 'plugin' => $dependency]);
            }
        }

        return null;
    }

    /** Turn on, for this workspace, what the plugin depends on and is still off. */
    private function turnOnDependencies(string $name, int $tenantId, ?int $profileId): void
    {
        foreach ($this->loader->workspaceDependenciesOf($name) as $dependency) {
            if ($this->activation->isVisible($dependency, $tenantId)) {
                continue;
            }
            $this->activation->setEnabled($tenantId, $dependency, true, $profileId);
            $this->audit?->record(self::AUDIT_ENABLED, [
                'tenant_id' => $tenantId,
                'actor_user_id' => $profileId,
                'target_type' => 'code_plugin',
                'metadata' => ['plugin' => $dependency, 'for' => $name],
            ]);
            $this->loader->notifyWorkspaceSwitch($dependency, $tenantId, true);
        }
    }

    /**
     * Active plugins, by name, with their version.
     *
     * @return array<string, string>
     */
    private function activePlugins(): array
    {
        $plugins = [];
        foreach ($this->loader->getPluginMetadata() as $meta) {
            if ($meta['status'] === PluginState::Active->value) {
                $plugins[$meta['name']] = $meta['version'];
            }
        }
        ksort($plugins);

        return $plugins;
    }

    /** The plugin's name when it is active, whatever its access. */
    private function installedPlugin(string $name): ?string
    {
        return array_key_exists($name, $this->activePlugins()) ? $name : null;
    }

    /** The plugin's name when this workspace may see it at all. */
    private function visiblePlugin(string $name): ?string
    {
        $name = $this->installedPlugin($name);
        if ($name === null || $this->activation->accessOf($name) === CodePluginActivation::OPERATOR) {
            return null;
        }

        return $name;
    }

    /** @param callable(int): Response $then */
    private function inWorkspace(callable $then): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                // The system workspace has every plugin on; it uses Admin › Plugins.
                return Response::error('A workspace is required', 403);
            }

            return $then($tenantId);
        } catch (Throwable $e) {
            error_log('[WorkspaceCodePluginsApiHandler] ' . $e->getMessage());

            return Response::error('The request could not be processed', 500);
        }
    }

    private static function profileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
    }

    private static function notFound(): Response
    {
        return Response::error('Not Found', 404);
    }
}
