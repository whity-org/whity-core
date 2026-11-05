<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Plugin\PluginPublicRoutes;
use Whity\Core\Plugin\PublicForm\BotCheck;
use Whity\Core\Plugin\PublicForm\PublicFormKeyRepository;
use Whity\Core\Plugin\PublicForm\PublicFormOrigins;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Cors;
use Whity\Http\JsonBody;
use Whity\Http\Middleware\PublicFormGate;

/**
 * Keys for a plugin's public forms (WHIT-906), and the one public read beside them.
 *
 *  - GET  /api/v1/public-form-keys               this workspace's keys and the forms that can have one
 *  - POST /api/v1/public-form-keys               issue; the key is in this answer and nowhere else
 *  - PATCH /api/v1/public-form-keys/{id}         change the label or the allowed origins
 *  - POST /api/v1/public-form-keys/{id}/revoke   stop it working, at once and for good
 *  - GET  /api/v1/public/plugin-forms/{key}      what a form's page needs to show its bot check
 *
 * The first four need `public_form_keys:manage` and bind the caller's own tenant,
 * so an operator issues a key by acting in that workspace, like anything else.
 */
final class PublicFormKeysApiHandler
{
    private const MAX_LABEL_LENGTH = 120;

    /** @param (\Closure(): list<string>)|null $instanceOrigins */
    public function __construct(
        private readonly PublicFormKeyRepository $keys,
        private readonly BotCheck $botCheck,
        private readonly SharedStoreInterface $store,
        private readonly ?AuditLoggerInterface $audit = null,
        private readonly ?\Closure $instanceOrigins = null,
    ) {
    }

    /** GET /api/v1/public-form-keys */
    public function index(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        try {
            return Response::json([
                'data' => array_map(self::present(...), $this->keys->listForTenant($tenantId)),
                'meta' => [
                    'forms' => PluginPublicRoutes::forms(),
                    'bot_check_ready' => $this->botCheck->isReady(),
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('[PublicFormKeysApiHandler] index failed: ' . $e->getMessage());

            return Response::error('The keys could not be loaded', 500);
        }
    }

    /** POST /api/v1/public-form-keys */
    public function issue(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $body = JsonBody::parsed($request);
        $plugin = is_string($body['plugin'] ?? null) ? $body['plugin'] : '';
        $form = is_string($body['form'] ?? null) ? $body['form'] : '';
        if (!in_array(['plugin' => $plugin, 'form' => $form], PluginPublicRoutes::forms(), true)) {
            return Response::error('Validation failed', 422, ['form' => 'No plugin here has this form.']);
        }

        $fields = self::validated($body);
        if ($fields instanceof Response) {
            return $fields;
        }

        try {
            $actor = self::actor($request);
            $row = $this->keys->issue($tenantId, $plugin, $form, $fields['label'], $fields['origins'], $actor);
            $this->audit?->record('public_form_key.issued', [
                'tenant_id' => $tenantId,
                'actor_user_id' => $actor,
                'detail' => $plugin . '/' . $form . ' #' . $row['id'],
            ]);

            // The one time the key is returned. It is stored as a hash after this.
            return Response::json(['data' => self::present($row) + ['key' => $row['key']]], 201);
        } catch (\Throwable $e) {
            error_log('[PublicFormKeysApiHandler] issue failed: ' . $e->getMessage());

            return Response::error('The key could not be issued', 500);
        }
    }

    /**
     * PATCH /api/v1/public-form-keys/{id}
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }
        $id = (int) ($params['id'] ?? 0);

        $existing = $this->keys->findForTenant($tenantId, $id);
        if ($existing === null) {
            return Response::error('Key not found', 404);
        }
        if ($existing['revoked_at'] !== null) {
            return Response::error('This key has been revoked', 409);
        }

        $body = JsonBody::parsed($request) + [
            'label' => $existing['label'],
            'allowed_origins' => $existing['allowed_origins'],
        ];
        $fields = self::validated($body);
        if ($fields instanceof Response) {
            return $fields;
        }

        try {
            $this->keys->update($tenantId, $id, $fields['label'], $fields['origins']);
            $this->audit?->record('public_form_key.changed', [
                'tenant_id' => $tenantId,
                'actor_user_id' => self::actor($request),
                'detail' => '#' . $id,
            ]);
            $row = $this->keys->findForTenant($tenantId, $id);

            return Response::json(['data' => $row === null ? null : self::present($row)]);
        } catch (\Throwable $e) {
            error_log('[PublicFormKeysApiHandler] update failed: ' . $e->getMessage());

            return Response::error('The key could not be changed', 500);
        }
    }

    /**
     * POST /api/v1/public-form-keys/{id}/revoke. Revoking twice changes nothing.
     *
     * @param array<string, string> $params
     */
    public function revoke(Request $request, array $params): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }
        $id = (int) ($params['id'] ?? 0);

        try {
            if ($this->keys->findForTenant($tenantId, $id) === null) {
                return Response::error('Key not found', 404);
            }
            $actor = self::actor($request);
            if ($this->keys->revoke($tenantId, $id, $actor)) {
                $this->audit?->record('public_form_key.revoked', [
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $actor,
                    'detail' => '#' . $id,
                ]);
            }

            return Response::json([], 204);
        } catch (\Throwable $e) {
            error_log('[PublicFormKeysApiHandler] revoke failed: ' . $e->getMessage());

            return Response::error('The key could not be revoked', 500);
        }
    }

    /**
     * GET /api/v1/public/plugin-forms/{key}: the site key, for the form's page.
     *
     * A site key isn't a secret, it is in every page that shows the widget, but this
     * still answers only to a live key, with the same 404 for anything else. A browser
     * sends no Origin on a same-origin GET, so a missing one isn't refused here. Only
     * an allowed origin is told it may read the answer from another site.
     *
     * @param array<string, string> $params
     */
    public function config(Request $request, array $params): Response
    {
        $ip = ClientIp::fromRequest($request);
        $bucket = 'pluginform:read:ip:' . ($ip ?? 'unknown');
        if ($this->store->increment($bucket, PublicFormGate::WINDOW_SECONDS) > PublicFormGate::READ_IP_MAX) {
            $this->store->decrement($bucket);

            return Response::error('Too many attempts. Please try again later.', 429)
                ->withHeaders(['Retry-After' => (string) max($this->store->ttl($bucket), 1)]);
        }

        try {
            $row = $this->keys->findLive((string) ($params['key'] ?? ''));
            if ($row === null) {
                return Response::error('This form link is not valid, or is no longer open', 404);
            }

            $origin = $request->getHeader('Origin');
            $headers = [];
            if ($origin !== null) {
                $instance = $this->instanceOrigins !== null ? ($this->instanceOrigins)() : Cors::allowedOriginsFromEnv();
                if (!PublicFormOrigins::allows($origin, $row['allowed_origins'], $instance)) {
                    return Response::error('This form cannot be sent from this site', 403);
                }
                $headers = PublicFormGate::corsFor(strtolower(trim($origin)));
            }

            $required = $this->botCheck->isRequired();

            return Response::json(['data' => [
                'accepting' => $this->botCheck->isReady(),
                'bot_check' => $required ? [
                    'provider' => $this->botCheck->provider(),
                    'site_key' => $this->botCheck->siteKey(),
                ] : null,
            ]])->withHeaders($headers);
        } catch (\Throwable $e) {
            error_log('[PublicFormKeysApiHandler] config failed: ' . $e->getMessage());

            return Response::error('This form is temporarily unavailable', 503);
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array{label: string, origins: list<string>}|Response
     */
    private static function validated(array $body): array|Response
    {
        $errors = [];

        $label = is_string($body['label'] ?? null) ? trim($body['label']) : '';
        if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            $errors['label'] = 'Give this key a name of up to ' . self::MAX_LABEL_LENGTH . ' characters.';
        }

        $origins = [];
        $given = $body['allowed_origins'] ?? [];
        if (!is_array($given) || !array_is_list($given) || count($given) > PublicFormOrigins::MAX_ORIGINS) {
            $errors['allowed_origins'] = 'Give at most ' . PublicFormOrigins::MAX_ORIGINS . ' sites.';
        } else {
            foreach ($given as $origin) {
                $normalized = is_string($origin) ? PublicFormOrigins::normalize($origin) : null;
                if ($normalized === null) {
                    $errors['allowed_origins'] = 'Each site must look like https://www.example.com, with no path.';
                    break;
                }
                $origins[$normalized] = true;
            }
        }

        if ($errors !== []) {
            return Response::error('Validation failed', 422, $errors);
        }

        return ['label' => $label, 'origins' => array_keys($origins)];
    }

    private static function actor(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
    }

    /**
     * What a key looks like to an admin: no hash, and no key.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id' => $row['id'],
            'plugin' => $row['plugin'],
            'form' => $row['form_name'],
            'label' => $row['label'],
            'key_hint' => $row['key_hint'],
            'allowed_origins' => $row['allowed_origins'],
            'created_at' => $row['created_at'],
            'revoked_at' => $row['revoked_at'],
        ];
    }

    /** The permission these routes are registered under. */
    public const PERMISSION = CorePermissions::PUBLIC_FORM_KEYS_MANAGE;
}
