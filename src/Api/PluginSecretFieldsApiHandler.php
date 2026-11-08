<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Plugin\HostPluginSecrets;
use Whity\Core\Plugin\HostPluginSecretStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginNamespace;
use Whity\Sdk\Secrets\PluginSecretFieldsInterface;
use Whity\Sdk\Secrets\SecretStore;

/**
 * Behind the host-drawn `secretField` block (SDK 1.88, WHIT-1016).
 *
 * WRITE-ONLY. No response carries a value, whoever asks: a browser learns
 * whether a secret is set and when, and that is all. This handler never calls
 * {@see SecretStore::get()}.
 *
 * THE HOST DECIDES WHOSE SECRET THIS IS. The plugin comes from the loader, the
 * workspace from the caller's token, and the permission from the plugin's own
 * declaration. An undeclared key is a 404, so these routes can't reach a secret
 * the plugin keeps for itself.
 *
 * Not an MCP tool: the routes opt out, because an agent never handles secrets.
 */
final class PluginSecretFieldsApiHandler
{
    /**
     * @param \Closure(): array<PluginInterface> $plugins the plugins the loader loaded
     */
    public function __construct(
        private readonly RoleChecker $roles,
        private readonly HostPluginSecrets $secrets,
        private readonly \Closure $plugins,
    ) {
    }

    /** @param array<string, string> $params */
    public function status(Request $request, array $params = []): Response
    {
        $field = $this->field($request, $params);
        if ($field instanceof Response) {
            return $field;
        }
        return $this->answer($field);
    }

    /** @param array<string, string> $params */
    public function save(Request $request, array $params = []): Response
    {
        $field = $this->field($request, $params);
        if ($field instanceof Response) {
            return $field;
        }

        $value = JsonBody::parsed($request)['value'] ?? null;
        if (!is_string($value) || $value === '') {
            return Response::error('Validation failed', 422, ['value' => 'Enter a value.']);
        }
        if (strlen($value) > SecretStore::MAX_VALUE_BYTES) {
            return Response::error('Validation failed', 422, ['value' => 'This value is too long.']);
        }

        $field['store']->set($field['workspace'], $field['key'], $value);
        return $this->answer($field);
    }

    /** @param array<string, string> $params */
    public function clear(Request $request, array $params = []): Response
    {
        $field = $this->field($request, $params);
        if ($field instanceof Response) {
            return $field;
        }

        $field['store']->delete($field['workspace'], $field['key']);
        return $this->answer($field);
    }

    /** @param array{store: HostPluginSecretStore, workspace: int, key: string} $field */
    private function answer(array $field): Response
    {
        $setAt = $field['store']->setAt($field['workspace'], $field['key']);
        return Response::json(['is_set' => $setAt !== null, 'updated_at' => $setAt]);
    }

    /**
     * @param array<string, string> $params
     * @return array{store: HostPluginSecretStore, workspace: int, key: string}|Response
     */
    private function field(Request $request, array $params): array|Response
    {
        $profile = $request->user->profile_id ?? null;
        if (!is_int($profile)) {
            return Response::error('Authentication required', 401);
        }
        $workspace = TenantContext::getTenantId();
        if ($workspace === null || $workspace <= 0) {
            return Response::error('Secrets belong to a workspace.', 400);
        }

        $key = (string) ($params['key'] ?? '');
        $plugin = $this->plugin((string) ($params['plugin'] ?? ''));
        $permission = $plugin === null ? null : $this->permissionFor($plugin, $key);
        if ($plugin === null || $permission === null) {
            return Response::error('Not Found', 404);
        }
        if (!$this->roles->hasPermissionForProfile($profile, $permission, $workspace)) {
            return Response::error('Insufficient permissions', 403, ['required' => $permission]);
        }

        return ['store' => $this->secrets->for($plugin), 'workspace' => $workspace, 'key' => $key];
    }

    private function plugin(string $slug): ?PluginInterface
    {
        foreach (($this->plugins)() as $plugin) {
            if ($plugin instanceof PluginSecretFieldsInterface && PluginNamespace::slug($plugin->getName()) === $slug) {
                return $plugin;
            }
        }
        return null;
    }

    private function permissionFor(PluginInterface $plugin, string $key): ?string
    {
        if (!$plugin instanceof PluginSecretFieldsInterface || preg_match('/^[a-z0-9_.-]{1,128}$/', $key) !== 1) {
            return null;
        }
        try {
            $permission = $plugin->getSecretFields()[$key]['permission'] ?? null;
        } catch (\Throwable) {
            return null;
        }
        return is_string($permission) && $permission !== '' ? $permission : null;
    }
}
