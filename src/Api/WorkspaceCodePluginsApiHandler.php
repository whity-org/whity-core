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
    ) {
    }

    public function list(Request $request): Response
    {
        return $this->inWorkspace(function (int $tenantId): Response {
            $rows = $this->activation->workspaceRows($tenantId);
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
            if (!$this->activation->trialAvailable($name, $tenantId)) {
                return Response::error('This workspace has already had its trial, or the plugin offers none', 409, ['reason' => 'trial_unavailable']);
            }

            $profileId = self::profileId($request);
            $this->activation->startTrial($tenantId, $name, $profileId);
            $state = $this->activation->stateFor($name, $tenantId);
            $this->audit?->record(self::AUDIT_TRIAL, [
                'tenant_id' => $tenantId,
                'actor_user_id' => $profileId,
                'target_type' => 'code_plugin',
                'metadata' => ['plugin' => $name, 'until' => $state['trialEndsAt']],
            ]);
            $this->loader->notifyWorkspaceSwitch($name, $tenantId, true);

            return Response::json(['data' => ['name' => $name, 'enabled' => true, 'trialEndsAt' => $state['trialEndsAt']]]);
        });
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

            $profileId = self::profileId($request);
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
