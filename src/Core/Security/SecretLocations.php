<?php

declare(strict_types=1);

namespace Whity\Core\Security;

use Whity\Core\Mail\MailerFactory;
use Whity\Core\Mail\OAuth\SmtpOAuthStore;
use Whity\Core\Notification\Push\VapidKeys;
use Whity\Core\Observability\ErrorTrackerConfig;
use Whity\Core\Payment\PaymentSecrets;
use Whity\Core\Plugin\PublicForm\BotCheck;

/**
 * Every place core stores a secret encrypted with the instance key ring
 * (merge map plumbing, WHIT-1047). {@see SecretLocationsTest} fails when a new
 * `*_encrypted` setting or column appears that this list doesn't name, so a
 * rotation never leaves one behind.
 *
 * Two-step sign-in secrets (`profiles.two_factor_secret`) are not here: they
 * have their own `whity-cli totp reencrypt`, which also reads their legacy
 * scheme.
 */
final class SecretLocations
{
    /** @return list<SecretRef> */
    public static function all(): array
    {
        return [
            SecretRef::column('identity_providers', 'client_secret_encrypted', 'Sign-in provider client secrets'),
            SecretRef::column('tenant_storage_config', 'secret_encrypted', 'Workspace file storage secrets'),
            SecretRef::column('plugin_secrets', 'value', 'Plugin secrets'),
            SecretRef::column('instance_signing_keys', 'secret_key', 'Document seal signing keys'),
            SecretRef::column('messaging_settings', 'inbound_secret', 'Inbound email webhook secrets', 'tenant_id'),
            SecretRef::instanceSetting(MailerFactory::SMTP_PASSWORD_SETTING_KEY, 'SMTP password'),
            SecretRef::instanceSetting(SmtpOAuthStore::CLIENT_SECRET_KEY, 'Mail OAuth client secret'),
            SecretRef::instanceSetting(SmtpOAuthStore::REFRESH_TOKEN_KEY, 'Mail OAuth refresh token'),
            SecretRef::instanceSetting(SmtpOAuthStore::ACCESS_TOKEN_KEY, 'Mail OAuth access token'),
            SecretRef::instanceSetting(ErrorTrackerConfig::DSN_SETTING_KEY, 'Error tracking DSN'),
            SecretRef::instanceSetting(PaymentSecrets::MOCK_SECRET_KEY, 'Test payment webhook secret'),
            SecretRef::instanceSetting(BotCheck::SETTING_SECRET, 'Public form bot-check secret'),
            SecretRef::instanceSettingJsonField(VapidKeys::SETTING_KEY, 'private', 'Web push private key'),
            SecretRef::workspaceSetting('notifications.%.credentials_encrypted', 'Notification channel credentials'),
            // Where migration 294 moves the inbound email secret.
            SecretRef::workspaceSetting('messaging.inbound_secret_encrypted', 'Inbound email webhook secrets (settings)'),
        ];
    }
}
