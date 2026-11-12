<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Messaging\Email\EmailChannel;
use Whity\Core\Messaging\MessagingException;
use Whity\Core\Messaging\MessagingService;
use Whity\Core\Messaging\WebChat\WebChatChannel;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Http\JsonBody;
use Whity\Http\Middleware\RequestBodyValidator;
use Whity\Http\WebsiteCors;
use Whity\Sdk\Messaging\InboundMessage;

/**
 * `POST /api/public/contact`: the contact form on whity.dev (section 29,
 * "Contact form contract (v1)").
 *
 * WHERE IT GOES. Into the Messages inbox of the workspace the operator names in
 * `website.contact_workspace`, through {@see MessagingService::ingest()}, so the
 * team answers it like any other message. An email address arrives on the email
 * channel, so a reply goes back by email; a phone number arrives as a web
 * message the team calls back. Nothing is set up: the form answers 503 and the
 * page offers hello@whity.dev instead.
 *
 * ABUSE. Per address (5 an hour) and for the whole form (200 an hour), counted
 * before anything is read; a hidden `website` field people never fill, which
 * drops the message quietly with the same answer as a real one; and length caps.
 *
 * WHAT COMES BACK. 201 with no copy of the message (the contract says never
 * send it back), or, for the scripts-off form, a redirect to the contact page
 * with `?sent=1` (or `?sent=0` when it could not be saved).
 */
final class PublicContactApiHandler
{
    public const TOPICS = ['question', 'demo', 'partners', 'other'];
    private const TOPIC_LABEL = [
        'question' => 'A question',
        'demo' => 'A demo',
        'partners' => 'Partnering',
        'other' => 'Something else',
    ];

    private const WINDOW = 3600;
    private const PER_ADDRESS = 5;
    private const PER_FORM = 200;

    private const MAX_NAME = 120;
    private const MAX_COMPANY = 200;
    private const MAX_CONTACT = 200;
    private const MAX_MESSAGE = 5000;

    public function __construct(
        private readonly MessagingService $messaging,
        private readonly TenantHostRepository $tenants,
        private readonly GlobalSettingsRepository $settings,
        private readonly SharedStoreInterface $store,
    ) {
    }

    /** @param array<string, string> $params */
    public function send(Request $request, array $params = []): Response
    {
        $form = RequestBodyValidator::isWebsiteContactForm($request);
        $body = JsonBody::parsed($request);
        $lang = ($body['lang'] ?? '') === 'ar' ? 'ar' : 'en';

        $limited = $this->limit('contact:ip:', ClientIp::fromRequest($request), self::PER_ADDRESS)
            ?? $this->limit('contact:all:', 'form', self::PER_FORM);
        if ($limited !== null) {
            return $form ? $this->back($lang, false) : $limited;
        }

        // The trap: answer exactly like a saved message and write nothing.
        if (trim((string) ($body['website'] ?? '')) !== '') {
            return $form ? $this->back($lang, true) : self::saved();
        }

        $fields = self::fields($body);
        if (isset($fields['errors'])) {
            return $form
                ? $this->back($lang, false)
                : Response::error('Please check the form.', 422, ['fields' => $fields['errors']]);
        }

        $tenantId = $this->inbox();
        if ($tenantId === null) {
            return $form
                ? $this->back($lang, false)
                : Response::error('Messages can\'t be received here right now.', 503);
        }

        $isEmail = filter_var($fields['contact'], FILTER_VALIDATE_EMAIL) !== false;
        $from = $isEmail
            ? strtolower($fields['contact'])
            : 'website:' . substr(hash('sha256', $fields['contact']), 0, 24);
        $text = implode("\n", array_filter([
            'From the website contact form (' . self::TOPIC_LABEL[$fields['topic']] . ')',
            'Name: ' . $fields['name'],
            $fields['company'] !== '' ? 'Company: ' . $fields['company'] : null,
            'Reply to: ' . $fields['contact'],
            'Language: ' . ($lang === 'ar' ? 'Arabic' : 'English'),
        ])) . "\n\n" . $fields['message'];

        try {
            $saved = $this->messaging->ingest(
                $tenantId,
                $isEmail ? EmailChannel::KEY : WebChatChannel::KEY,
                new InboundMessage(
                    from: $from,
                    fromName: $fields['name'],
                    body: $text,
                    subject: 'Website: ' . self::TOPIC_LABEL[$fields['topic']],
                ),
            );
        } catch (MessagingException $e) {
            error_log('[PublicContact] not filed: ' . $e->getMessage());
            $saved = null;
        } catch (\Throwable $e) {
            error_log('[PublicContact] failed: ' . $e->getMessage());
            $saved = null;
        }
        if ($saved === null) {
            return $form
                ? $this->back($lang, false)
                : Response::error('The message could not be saved. Please try again.', 500);
        }

        return $form ? $this->back($lang, true) : self::saved();
    }

    /**
     * @param array<string, mixed> $body
     * @return array{topic: string, name: string, company: string, contact: string, message: string}|array{errors: array<string, string>}
     */
    private static function fields(array $body): array
    {
        $get = static fn (string $k, int $max): string => mb_substr(trim(is_string($body[$k] ?? null) ? $body[$k] : ''), 0, $max + 1);
        $topic = is_string($body['topic'] ?? null) && in_array($body['topic'], self::TOPICS, true) ? $body['topic'] : 'other';
        $name = $get('name', self::MAX_NAME);
        $company = $get('company', self::MAX_COMPANY);
        $contact = $get('contact', self::MAX_CONTACT);
        $message = $get('message', self::MAX_MESSAGE);

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Please write your name.';
        } elseif (mb_strlen($name) > self::MAX_NAME) {
            $errors['name'] = 'Your name is too long.';
        }
        if (mb_strlen($company) > self::MAX_COMPANY) {
            $errors['company'] = 'The company name is too long.';
        }
        if ($contact === '') {
            $errors['contact'] = 'Please write an email address or a phone number.';
        } elseif (
            filter_var($contact, FILTER_VALIDATE_EMAIL) === false
            && preg_match('/^\+?[0-9 ()\-]{6,24}$/', $contact) !== 1
        ) {
            $errors['contact'] = 'That doesn\'t look like an email address or a phone number.';
        }
        if ($message === '') {
            $errors['message'] = 'Please write your message.';
        } elseif (mb_strlen($message) > self::MAX_MESSAGE) {
            $errors['message'] = 'Your message is too long. Please keep it under ' . self::MAX_MESSAGE . ' characters.';
        }
        if ($errors !== []) {
            return ['errors' => $errors];
        }

        return compact('topic', 'name', 'company', 'contact', 'message');
    }

    /** The operator's inbox workspace, or null when none is named or it is gone. */
    private function inbox(): ?int
    {
        $slug = trim((string) ($this->settings->get(SettingsRegistry::WEBSITE_CONTACT_WORKSPACE) ?? ''));
        if ($slug === '') {
            return null;
        }
        try {
            return $this->tenants->findIdBySlug($slug);
        } catch (\Throwable $e) {
            error_log('[PublicContact] inbox lookup failed: ' . $e->getMessage());

            return null;
        }
    }

    private static function saved(): Response
    {
        return Response::json(['data' => ['received' => true]], 201);
    }

    /** Back to the contact page, for the form posted with scripts off. */
    private function back(string $lang, bool $sent): Response
    {
        $page = WebsiteCors::siteOrigin() . ($lang === 'ar' ? '/ar' : '') . '/contact.html?sent=' . ($sent ? '1' : '0');

        return new Response(303, '', ['Location' => $page]);
    }

    private function limit(string $prefix, ?string $subject, int $max): ?Response
    {
        if ($subject === null || $subject === '') {
            return null;
        }
        $key = $prefix . hash('sha256', $subject);
        if ($this->store->increment($key, self::WINDOW) > $max) {
            $this->store->decrement($key);

            return Response::error('Too many messages. Please try again later.', 429)
                ->withHeaders(['Retry-After' => (string) max($this->store->ttl($key), 1)]);
        }

        return null;
    }
}
