<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Whity\Sdk\Mail\EmailAttachment;
use Whity\Sdk\Mail\EmailMessage;
use Whity\Sdk\Mail\EmailTemplate;
use Whity\Sdk\Mail\PluginEmailTemplatesInterface;
use Whity\Sdk\PluginNamespace;

/**
 * The email templates core can send, keyed `<owner>:<key>` (WHIT-959).
 *
 * Plugins add emails (Front Office's invoice and receipt, Whity Pay's
 * payment-failed) by declaring {@see EmailTemplate}s through
 * {@see PluginEmailTemplatesInterface}; this reads them, refusing a malformed
 * declaration whole, and renders each through the same {@see EmailLayout} as
 * core's own emails. So a plugin's email has the EM.1 look and the sending
 * workspace's brand, and the plugin never patches core or writes HTML.
 */
final class EmailTemplateRegistry
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_.]*$/';
    private const MAX_KEY_LENGTH = 100;

    /** @var array<string, EmailTemplate> namespaced key => template */
    private array $templates = [];

    /**
     * A plugin's declared templates, under its own namespace.
     *
     * @throws \InvalidArgumentException on a malformed declaration
     */
    public static function fromPlugin(PluginEmailTemplatesInterface $plugin, string $slug): self
    {
        $registry = new self();
        // Untrusted: the list shape on the interface is PHPDoc only.
        /** @var mixed $declared */
        $declared = $plugin->getEmailTemplates();
        if (!is_array($declared)) {
            throw new \InvalidArgumentException('getEmailTemplates() must return a list of EmailTemplate.');
        }
        foreach ($declared as $template) {
            if (!$template instanceof EmailTemplate) {
                throw new \InvalidArgumentException('getEmailTemplates() must return only EmailTemplate objects.');
            }
            $registry->register($slug, $template);
        }

        return $registry;
    }

    /**
     * Add a template under `$owner` (a plugin's slug, or `core`).
     *
     * @throws \InvalidArgumentException for a bad or repeated key
     */
    public function register(string $owner, EmailTemplate $template): void
    {
        $key = $template->key();
        $namespaced = $owner . PluginNamespace::SEPARATOR . $key;
        if (preg_match(self::KEY_PATTERN, $owner) !== 1
            || preg_match(self::KEY_PATTERN, $key) !== 1
            || strlen($namespaced) > self::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException("Bad email template key: {$key}");
        }
        if (isset($this->templates[$namespaced])) {
            throw new \InvalidArgumentException("Email template {$key} is declared twice.");
        }
        $this->templates[$namespaced] = $template;
    }

    public function has(string $key): bool
    {
        return isset($this->templates[$key]);
    }

    /** @return list<string> the namespaced keys, in the order they were added */
    public function keys(): array
    {
        return array_keys($this->templates);
    }

    /**
     * Build and lay out one email.
     *
     * @param array<string, mixed> $data
     * @return array{subject: string, email: RenderedEmail, attachments: list<MailAttachment>}
     * @throws \InvalidArgumentException for an unknown key, or what the template throws
     */
    public function render(string $key, array $data, string $locale, EmailBranding $branding, EmailLayout $layout = new EmailLayout()): array
    {
        $template = $this->templates[$key] ?? null;
        if ($template === null) {
            throw new \InvalidArgumentException("There is no email template {$key}.");
        }
        $locale = EmailContent::normaliseLocale($locale);
        $message = $template->build($data, $locale);
        // Untrusted: a template is plugin code.
        /** @var mixed $built */
        $built = $message;
        if (!$built instanceof EmailMessage) {
            throw new \InvalidArgumentException("Email template {$key} did not build an EmailMessage.");
        }

        $attachments = [];
        foreach ($message->attachments as $attachment) {
            /** @var mixed $attachment */
            if (!$attachment instanceof EmailAttachment) {
                throw new \InvalidArgumentException("Email template {$key} attached something that is not an EmailAttachment.");
            }
            try {
                $attachments[] = new MailAttachment($attachment->filename, $attachment->contentType, $attachment->content);
            } catch (MailException $e) {
                throw new \InvalidArgumentException($e->getMessage(), 0, $e);
            }
        }

        return [
            'subject' => self::oneLine($message->subject),
            'email' => $layout->render(self::contentOf($message, $locale), $branding),
            'attachments' => $attachments,
        ];
    }

    /** The layout's content for a plugin's message. */
    public static function contentOf(EmailMessage $m, string $locale): EmailContent
    {
        $rows = [];
        foreach ($m->rows as $row) {
            /** @var mixed $row */
            if (is_array($row)) {
                $values = array_values($row);
                $rows[] = [self::text($values[0] ?? ''), self::text($values[1] ?? '')];
            }
        }

        return new EmailContent(
            heading: $m->heading,
            paragraphs: array_values(array_map(self::text(...), $m->paragraphs)),
            ctaLabel: $m->buttonLabel,
            ctaUrl: $m->buttonUrl,
            callout: $m->note,
            footnote: $m->footnote,
            rows: $rows,
            code: $m->code,
            preheader: $m->preheader,
            locale: EmailContent::normaliseLocale($locale),
            calloutPending: $m->notePending,
        );
    }

    /** A subject is a header: no line breaks, at most 255 characters. */
    private static function oneLine(string $subject): string
    {
        $subject = trim(preg_replace('/[\r\n\t]+/', ' ', $subject) ?? '');

        return mb_substr($subject, 0, 255);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
