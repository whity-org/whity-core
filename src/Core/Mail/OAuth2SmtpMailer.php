<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Closure;
use Whity\Core\Mail\OAuth\OAuthTokenException;
use Whity\Core\Mail\OAuth\SmtpOAuthTokenManager;

/**
 * SMTP mail that signs in with an OAuth2 access token (WHIT-901). {@see SmtpMailer}
 * still does all the talking; this supplies the token and, when the server refuses
 * one, refreshes and tries exactly once more.
 */
final class OAuth2SmtpMailer implements Mailer
{
    /**
     * @param (Closure(SmtpConfig): SmtpConnection)|null $connector Test seam,
     *        passed through to {@see SmtpMailer}.
     */
    public function __construct(
        private readonly SmtpConfig $config,
        private readonly SmtpOAuthTokenManager $tokens,
        private readonly ?Closure $connector = null,
    ) {
    }

    public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
    {
        // WHIT-991: no mail from a proposal preview.
        \Whity\Core\Sandbox\SideEffectGuard::assertAllowed('Sending mail');
        // Checked before any token exists, so a wrong host never receives one.
        $problem = SmtpOAuthTokenManager::connectionProblem(
            $this->tokens->provider(),
            $this->config->host,
            $this->config->encryption,
        );
        if ($problem !== null) {
            throw new OAuthTokenException($problem);
        }

        $token = $this->tokens->accessToken();
        try {
            $this->sendWith($token, $toEmail, $subject, $textBody, $htmlBody);
        } catch (SmtpAuthRejectedException) {
            try {
                $this->sendWith($this->tokens->accessToken($token), $toEmail, $subject, $textBody, $htmlBody);
            } catch (SmtpAuthRejectedException $e) {
                $this->tokens->recordSmtpRejected();
                throw $e;
            }
        }

        $this->tokens->recordConnected();
    }

    private function sendWith(
        #[\SensitiveParameter] string $token,
        string $toEmail,
        string $subject,
        string $textBody,
        ?string $htmlBody,
    ): void {
        (new SmtpMailer($this->config->withAccessToken($token), $this->connector))
            ->send($toEmail, $subject, $textBody, $htmlBody);
    }
}
