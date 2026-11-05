<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Plugin\PublicForm\BotCheck;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * The instance's bot check for plugin public forms (WHIT-906).
 *
 *  - GET /api/v1/settings/public-forms/bot-check
 *  - PUT /api/v1/settings/public-forms/bot-check
 *
 * Both are `settings:manage` in the system tenant, like the mail settings: this is
 * one setting for the whole instance, and a workspace owner can't reach it.
 *
 * The secret is write-only, encrypted at rest and never returned, logged or
 * audited. A PUT that leaves `secret` out keeps it, and null or empty clears it.
 */
final class PublicFormBotSettingsApiHandler
{
    private const MAX_FIELD_BYTES = 4096;

    public function __construct(
        private readonly GlobalSettingsRepository $settings,
        private readonly BotCheck $botCheck,
        private readonly RoleChecker $roleChecker,
        private readonly ?AuditLoggerInterface $audit = null,
    ) {
    }

    public function show(Request $request): Response
    {
        $auth = $this->authorize($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        return Response::json(['data' => $this->describe()]);
    }

    public function update(Request $request): Response
    {
        $auth = $this->authorize($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        $body = JsonBody::parsed($request);
        $errors = [];

        $provider = $body['provider'] ?? null;
        if (array_key_exists('provider', $body) && $provider !== null && !(is_string($provider) && isset(BotCheck::PROVIDERS[$provider]))) {
            $errors['provider'] = 'Choose a provider this instance supports.';
        }
        foreach (['site_key', 'secret'] as $field) {
            $value = $body[$field] ?? null;
            if ($value !== null && (!is_string($value) || strlen($value) > self::MAX_FIELD_BYTES || preg_match('/[\x00-\x1F\x7F]/', $value) === 1)) {
                $errors[$field] = $field . ' must be a single line of text.';
            }
        }
        if (array_key_exists('allow_without_bot_check', $body) && !is_bool($body['allow_without_bot_check'])) {
            $errors['allow_without_bot_check'] = 'allow_without_bot_check must be true or false.';
        }
        if ($errors !== []) {
            return Response::error('Validation failed', 422, $errors);
        }

        try {
            $changed = [];
            if (array_key_exists('provider', $body)) {
                $changed[] = 'provider';
                $this->store(BotCheck::SETTING_PROVIDER, is_string($provider) ? $provider : '');
            }
            if (array_key_exists('site_key', $body)) {
                $changed[] = 'site key';
                $this->store(BotCheck::SETTING_SITE_KEY, is_string($body['site_key']) ? trim($body['site_key']) : '');
            }
            if (array_key_exists('allow_without_bot_check', $body)) {
                $changed[] = 'allow without a bot check ' . ($body['allow_without_bot_check'] ? 'on' : 'off');
                $this->store(BotCheck::SETTING_ALLOW_NONE, $body['allow_without_bot_check'] ? '1' : '');
            }
            if (array_key_exists('secret', $body)) {
                $secret = is_string($body['secret']) ? trim($body['secret']) : '';
                $changed[] = $secret === '' ? 'secret cleared' : 'secret set';
                if ($secret === '') {
                    $this->botCheck->clearSecret();
                } else {
                    $this->botCheck->setSecret($secret);
                }
            }

            // Who turned the protection off has to be findable afterwards.
            $this->audit?->record('settings.public_forms_bot_check_changed', [
                'tenant_id' => $auth['tenantId'],
                'actor_user_id' => $auth['userId'],
                'detail' => implode(', ', $changed),
            ]);

            return Response::json(['data' => $this->describe()]);
        } catch (\Throwable $e) {
            // Never the secret, or crypto detail.
            error_log('[PublicFormBotSettingsApiHandler] update failed: ' . get_class($e));

            return Response::error('The bot check settings could not be saved', 500);
        }
    }

    private function store(string $key, string $value): void
    {
        if ($value === '') {
            $this->settings->delete($key);
        } else {
            $this->settings->set($key, $value);
        }
    }

    /** @return array<string, mixed> */
    private function describe(): array
    {
        return [
            'provider' => $this->botCheck->provider(),
            'providers' => array_keys(BotCheck::PROVIDERS),
            'site_key' => $this->botCheck->siteKey(),
            'has_secret' => $this->botCheck->hasSecret(),
            'allow_without_bot_check' => $this->botCheck->allowsNoBotCheck(),
            'ready' => $this->botCheck->isReady(),
        ];
    }

    /** @return array{tenantId: int, userId: int}|Response */
    private function authorize(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($userId === null || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::SETTINGS_MANAGE, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::SETTINGS_MANAGE]);
        }
        if ($tenantId !== SettingsService::SYSTEM_TENANT_ID) {
            return Response::error('The bot check is managed by the system tenant only', 403);
        }

        return ['tenantId' => $tenantId, 'userId' => $userId];
    }
}
