<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Psr\Log\LoggerInterface;
use Whity\Sdk\Mail\EmailSender;
use Whity\Sdk\PluginNamespace;

/**
 * One plugin's email sender (WHIT-959), from {@see HostPluginEmailSender::for()}.
 * It reaches only that plugin's own templates.
 */
final class HostEmailSender implements EmailSender
{
    /**
     * @param \Closure(): EmailBranding $branding The sending workspace's brand, read per email.
     */
    public function __construct(
        private readonly EmailTemplateRegistry $templates,
        private readonly string $slug,
        private readonly Mailer $mailer,
        private readonly \Closure $branding,
        private readonly EmailLayout $layout = new EmailLayout(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function send(string $key, string $to, array $data = [], string $locale = 'en'): void
    {
        $to = trim($to);
        if (strpbrk($to, "\r\n") !== false || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('That is not an email address.');
        }
        $built = $this->build($key, $data, $locale);
        $email = $built['email'];

        if ($built['attachments'] !== []) {
            if (!$this->mailer instanceof ThreadedMailer) {
                throw new MailException('This mail transport cannot send attachments.');
            }
            $this->mailer->sendThreaded(new OutgoingEmail(
                to: $to,
                subject: $built['subject'],
                textBody: $email->text,
                htmlBody: $email->html,
                attachments: $built['attachments'],
            ));
        } else {
            $this->mailer->send($to, $built['subject'], $email->text, $email->html);
        }

        $this->logger?->info('Plugin email sent', ['template' => $this->namespaced($key)]);
    }

    public function preview(string $key, array $data = [], string $locale = 'en'): array
    {
        $built = $this->build($key, $data, $locale);

        return ['subject' => $built['subject'], 'text' => $built['email']->text, 'html' => $built['email']->html];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{subject: string, email: RenderedEmail, attachments: list<MailAttachment>}
     */
    private function build(string $key, array $data, string $locale): array
    {
        $namespaced = $this->namespaced($key);
        if (str_contains($key, PluginNamespace::SEPARATOR) || !$this->templates->has($namespaced)) {
            throw new \InvalidArgumentException("This plugin declares no email template {$key}.");
        }

        return $this->templates->render($namespaced, $data, $locale, ($this->branding)(), $this->layout);
    }

    private function namespaced(string $key): string
    {
        return $this->slug . PluginNamespace::SEPARATOR . $key;
    }
}
