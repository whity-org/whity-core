<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Messaging\MessagingException;
use Whity\Core\Messaging\MessagingService;
use Whity\Core\Messaging\WebChat\WebChatChannel;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\RateLimit\RateLimitRule;
use Whity\Core\RateLimit\RateLimits;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Http\JsonBody;
use Whity\Http\Validation\Field;
use Whity\Http\Validation\FieldCheck;
use Whity\Http\Validation\FieldValidator;
use Whity\Http\Middleware\RequestBodyValidator;
use Whity\Http\WebsiteCors;
use Whity\Sdk\Messaging\InboundMessage;

/**
 * `POST /api/public/contact`: the contact form on whity.dev (section 29,
 * "Contact form contract (v1)").
 *
 * WHERE IT GOES. Into the Messages inbox of the workspace the operator names in
 * `website.contact_workspace`, through {@see MessagingService::ingest()}, so the
 * team answers it like any other message. Nothing is set up: the form answers
 * 503 and the page offers hello@whity.dev instead.
 *
 * NOTHING ABOUT THE SENDER IS TRUSTED. Anyone can type anyone's address, so the
 * message is filed as a website message, never on the email channel: it is not
 * linked to a customer with that address, it never joins that customer's email
 * thread, and a reply from the inbox does not email whoever was named. The
 * address the visitor gave is in the message, marked as not checked.
 *
 * ABUSE. Counted per address before anything is read: 5 an hour, IPv6 counted
 * per /64 (one connection holds a whole /64), and one shared bucket of 30 an
 * hour for requests whose address the proxy did not pass on
 * (TRUSTED_PROXY_HOPS), so a missing address never turns the limit off. Then
 * 200 filed messages an hour for the whole form, charged only for messages
 * that are actually filed, so junk cannot use up everyone's allowance. A hidden
 * `website` field people never fill drops the message quietly with the same
 * answer as a real one; and length caps.
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

        $address = self::addressKey(ClientIp::fromRequest($request));
        $limited = $address === null
            ? $this->limit('contact:ip:', 'unknown', RateLimits::publicContactPerUnknownAddress())
            : $this->limit('contact:ip:', $address, RateLimits::publicContactPerAddress());
        if ($limited !== null) {
            return $form ? $this->back($lang, false) : $limited;
        }

        // The trap: answer exactly like a saved message and write nothing.
        if (trim((string) ($body['website'] ?? '')) !== '') {
            return $form ? $this->back($lang, true) : self::saved();
        }

        $checked = self::check($body);
        if (!$checked->ok()) {
            return $form
                ? $this->back($lang, false)
                : $checked->refusal('Please check the form.');
        }
        $fields = self::fields($body, $checked);

        $tenantId = $this->inbox();
        if ($tenantId === null) {
            return $form
                ? $this->back($lang, false)
                : Response::error('Messages can\'t be received here right now.', 503);
        }
        if (($limited = $this->limit('contact:all:', 'form', RateLimits::publicContactPerForm())) !== null) {
            return $form ? $this->back($lang, false) : $limited;
        }

        $from = 'website:' . substr(hash('sha256', strtolower($fields['contact'])), 0, 24);
        $text = implode("\n", array_filter([
            'From the website contact form (' . self::TOPIC_LABEL[$fields['topic']] . ')',
            'Name: ' . $fields['name'],
            $fields['company'] !== '' ? 'Company: ' . $fields['company'] : null,
            'Reply to (as typed, not checked): ' . $fields['contact'],
            'Language: ' . ($lang === 'ar' ? 'Arabic' : 'English'),
        ])) . "\n\n" . $fields['message'];

        try {
            $saved = $this->messaging->ingest(
                $tenantId,
                WebChatChannel::KEY,
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
     * The form's fields through the shared validator (#2174). `details.fields`
     * keeps the English sentences the website has always shown; the Arabic and
     * the codes come alongside.
     *
     * @param array<string, mixed> $body
     */
    private static function check(array $body): FieldCheck
    {
        return FieldValidator::check($body, [
            'name' => Field::text()->required()->max(self::MAX_NAME)
                ->say('required', 'Please write your name.', 'يرجى كتابة اسمك.')
                ->say('too_long', 'Your name is too long.', 'اسمك طويل جدًا.'),
            'company' => Field::text()->max(self::MAX_COMPANY)
                ->say('too_long', 'The company name is too long.', 'اسم الشركة طويل جدًا.'),
            'contact' => Field::emailOrPhone('/^\+?[0-9 ()\-]{6,24}$/')->required()->max(self::MAX_CONTACT)
                ->say('required', 'Please write an email address or a phone number.', 'يرجى كتابة عنوان بريد إلكتروني أو رقم هاتف.'),
            'message' => Field::text()->required()->max(self::MAX_MESSAGE)
                ->say('required', 'Please write your message.', 'يرجى كتابة رسالتك.')
                ->say(
                    'too_long',
                    'Your message is too long. Please keep it under ' . self::MAX_MESSAGE . ' characters.',
                    'رسالتك طويلة جدًا. يرجى إبقاءها أقل من ' . self::MAX_MESSAGE . ' حرف.'
                ),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{topic: string, name: string, company: string, contact: string, message: string}
     */
    private static function fields(array $body, FieldCheck $checked): array
    {
        return [
            'topic' => is_string($body['topic'] ?? null) && in_array($body['topic'], self::TOPICS, true) ? $body['topic'] : 'other',
            'name' => $checked->value('name'),
            'company' => $checked->value('company'),
            'contact' => $checked->value('contact'),
            'message' => $checked->value('message'),
        ];
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

    /** The address to count by: IPv4 as is, IPv6 by its /64; null when unknown. */
    private static function addressKey(?string $ip): ?string
    {
        if ($ip === null || trim($ip) === '') {
            return null;
        }
        $packed = @inet_pton(trim($ip));
        if ($packed === false) {
            return trim($ip);
        }
        if (strlen($packed) === 16) {
            return bin2hex(substr($packed, 0, 8)) . '::/64';
        }

        return trim($ip);
    }

    private function limit(string $prefix, string $subject, RateLimitRule $rule): ?Response
    {
        $key = $prefix . hash('sha256', $subject);
        $limited = $rule->hit($this->store, $key);
        if ($limited !== null) {
            // A refused message gives its place back.
            $this->store->decrement($key);
        }

        return $limited;
    }
}
