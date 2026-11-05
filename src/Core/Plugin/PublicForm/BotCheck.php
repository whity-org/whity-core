<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\PublicForm;

use Whity\Core\Http\HttpClient;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;

/**
 * The instance's bot check for plugin public forms (WHIT-906).
 *
 * WITH NO PROVIDER CHOSEN THE ROUTE IS REFUSED, unless the operator has turned on
 * "allow without a bot check". A plugin's form can't go public unprotected because
 * nobody got round to the setting. A provider chosen with its secret or site key
 * missing is also refused: that switch only covers having no provider.
 *
 * The endpoint comes from {@see self::PROVIDERS}, in code, and is never a setting,
 * so whoever holds the settings can't aim the secret at another server. A provider
 * that can't be reached refuses the request too.
 *
 * ADDING A PROVIDER. hCaptcha (https://api.hcaptcha.com/siteverify) and reCAPTCHA
 * (https://www.google.com/recaptcha/api/siteverify) take the same form-encoded
 * `secret`, `response` and `remoteip` and answer with `success`, so each is one
 * entry here plus its name in the settings screen. reCAPTCHA v3 also needs a
 * minimum `score`, which belongs beside the endpoint.
 */
final class BotCheck
{
    /** @var array<string, string> Provider => the only address its token is checked at. */
    public const PROVIDERS = [
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ];

    public const SETTING_PROVIDER = 'public_forms.bot_provider';
    public const SETTING_SITE_KEY = 'public_forms.bot_site_key';
    public const SETTING_SECRET = 'public_forms.bot_secret_encrypted';
    public const SETTING_ALLOW_NONE = 'public_forms.allow_without_bot_check';

    /** Turnstile's documented ceiling on a token. A longer one isn't sent anywhere. */
    public const MAX_TOKEN_BYTES = 2048;

    public const PASSED = 'passed';
    public const FAILED = 'failed';
    public const UNREACHABLE = 'unreachable';

    public function __construct(
        private readonly GlobalSettingsRepository $settings,
        private readonly EncryptedSecretStore $secrets,
        private readonly HttpClient $http,
    ) {
    }

    /** The chosen provider, or null when none is or the stored one isn't known. */
    public function provider(): ?string
    {
        $provider = (string) $this->settings->get(self::SETTING_PROVIDER);

        return isset(self::PROVIDERS[$provider]) ? $provider : null;
    }

    public function siteKey(): string
    {
        return (string) $this->settings->get(self::SETTING_SITE_KEY);
    }

    public function hasSecret(): bool
    {
        return (string) $this->settings->get(self::SETTING_SECRET) !== '';
    }

    public function allowsNoBotCheck(): bool
    {
        return $this->settings->get(self::SETTING_ALLOW_NONE) === '1';
    }

    /** Whether requests must carry a token: a provider has been chosen. */
    public function isRequired(): bool
    {
        return (string) $this->settings->get(self::SETTING_PROVIDER) !== '';
    }

    /** Whether a form can be served at all, given the settings as they stand. */
    public function isReady(): bool
    {
        if (!$this->isRequired()) {
            return $this->allowsNoBotCheck();
        }

        return $this->provider() !== null && $this->hasSecret() && $this->siteKey() !== '';
    }

    public function setSecret(#[\SensitiveParameter] string $secret): void
    {
        $this->settings->set(self::SETTING_SECRET, $this->secrets->encrypt($secret));
    }

    public function clearSecret(): void
    {
        $this->settings->delete(self::SETTING_SECRET);
    }

    /** Ask the provider about a token, server to server. Anything but a clear yes isn't a pass. */
    public function verify(#[\SensitiveParameter] string $token, ?string $clientIp): string
    {
        $provider = $this->provider();
        if ($provider === null || $token === '' || strlen($token) > self::MAX_TOKEN_BYTES) {
            return self::FAILED;
        }

        try {
            $secret = $this->secrets->decrypt((string) $this->settings->get(self::SETTING_SECRET));
        } catch (\Throwable) {
            // Not the exception's text: it is about a secret.
            error_log('[BotCheck] the stored secret could not be read');

            return self::UNREACHABLE;
        }

        $params = ['secret' => $secret, 'response' => $token];
        if ($clientIp !== null) {
            $params['remoteip'] = $clientIp;
        }

        try {
            $answer = $this->http->postForm(self::PROVIDERS[$provider], $params);
        } catch (\Throwable) {
            error_log('[BotCheck] the provider could not be reached');

            return self::UNREACHABLE;
        }
        if ($answer === null || !array_key_exists('success', $answer)) {
            return self::UNREACHABLE;
        }

        return $answer['success'] === true ? self::PASSED : self::FAILED;
    }
}
