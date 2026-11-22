<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Document\Issuing\ActingAs;
use Whity\Core\Document\Issuing\DocumentIssueRefused;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\RateLimit\RateLimitRule;
use Whity\Core\RateLimit\RateLimits;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Sales\Print\QuoteDocuments;
use Whity\Core\Sales\Quotes;
use Whity\Core\Sales\SalesRejectedException;
use Whity\Core\Sales\TradingEmails;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\Validation\Field;
use Whity\Http\Validation\FieldValidator;

/**
 * Quotes to customers (WHIT-1321, design section 34 §3, register QT.1-QT.12).
 *
 * Signed in, under `/api/sales/quotes`: list, draft, change a draft, send for
 * approval, send, a new link, record the customer's answer, revise, cancel,
 * and turn into a target, and print (WHIT-1341). Every route is gated by `quotes:read` or
 * `quotes:write` in the router; turning into a target asks the target itself
 * whether this person may.
 *
 * Sending a quote, or giving it a new link, issues its printed copy with the
 * link's QR ({@see QuoteDocuments}); the answer names it as `document_id`.
 * Printing never stops a quote going out: a copy that could not be made is
 * logged and the answer's `document_id` is null.
 *
 * Public, under `/api/public/quotes/{token}`: the customer's page. No
 * account; the 256-bit token names one quote, rate-limited per address, and
 * every "not here" answer reads the same so the page reveals nothing.
 */
final class SalesQuotesApiHandler
{
    private const MAX_PER_PAGE = 200;
    private const LINK_INVALID = 'This link is not valid any more. Ask the business that sent it for a new one.';

    /** Request field => setting key, for GET and PATCH /api/sales/settings. */
    private const SETTING_KEYS = [
        'quote_valid_days' => SettingsRegistry::SALES_QUOTE_VALID_DAYS,
        'quote_route_template_id' => SettingsRegistry::SALES_QUOTE_ROUTE_TEMPLATE_ID,
        'email_quote' => SettingsRegistry::SALES_EMAIL_QUOTE,
        'email_invoice' => SettingsRegistry::SALES_EMAIL_INVOICE,
        'email_receipt' => SettingsRegistry::SALES_EMAIL_RECEIPT,
        'email_reminder' => SettingsRegistry::SALES_EMAIL_REMINDER,
    ];

    public function __construct(
        private readonly Quotes $quotes,
        private readonly SettingsService $settings,
        private readonly SharedStoreInterface $store,
        private readonly string $appBase,
        private readonly ?BrandingService $branding = null,
        private readonly ?\Closure $workspaceName = null,
        private readonly ?TradingEmails $emails = null,
        private readonly ?QuoteDocuments $documents = null,
        private readonly ?RoleChecker $roleChecker = null,
    ) {
    }

    // ── signed in ────────────────────────────────────────────────────────

    public function list(Request $request): Response
    {
        return $this->run($request, 'quotes', function (int $tenantId) use ($request): Response {
            $q = self::query($request);

            return Response::json(['data' => $this->quotes->list($tenantId, [
                'status' => $q['status'] ?? null,
                'contact_id' => isset($q['contact_id']) ? (int) $q['contact_id'] : null,
            ], max(1, min(self::MAX_PER_PAGE, (int) ($q['limit'] ?? 50))), max(0, (int) ($q['offset'] ?? 0)))]);
        });
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        return $this->run($request, 'quote', fn (int $tenantId): Response => Response::json(['data' => $this->quotes->get($tenantId, (int) $params['id'])]));
    }

    public function create(Request $request): Response
    {
        return $this->run($request, 'quote', function (int $tenantId, ?int $actor) use ($request): Response {
            $body = JsonBody::parsed($request);
            $result = $this->quotes->create($tenantId, $actor, $body, [
                'idempotency_key' => is_string($body['idempotency_key'] ?? null) && $body['idempotency_key'] !== '' ? $body['idempotency_key'] : null,
            ]);

            return Response::json(['data' => $result['quote']], $result['repeated'] ? 200 : 201);
        });
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        return $this->run($request, 'quote', fn (int $tenantId): Response => Response::json([
            'data' => $this->quotes->update($tenantId, (int) $params['id'], JsonBody::parsed($request)),
        ]));
    }

    /** @param array<string, string> $params */
    public function submit(Request $request, array $params): Response
    {
        return $this->run($request, 'quote', fn (int $tenantId, ?int $actor): Response => Response::json([
            'data' => $this->quotes->submit($tenantId, (int) $params['id'], $actor),
        ]));
    }

    /** @param array<string, string> $params */
    public function send(Request $request, array $params): Response
    {
        return $this->run($request, 'quote', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $email = $body['email'] ?? true;
            if (!is_bool($email)) {
                throw SalesRejectedException::field('email', 'email is true (email it to the customer) or false (only make the link)');
            }
            $sent = $this->quotes->send($tenantId, (int) $params['id'], $actor);
            $link = $this->link($sent['token']);
            $documentId = $this->printSent($tenantId, $actor, (int) $params['id'], $link);

            return Response::json([
                'data' => $sent['quote'],
                'link' => $link,
                'document_id' => $documentId,
                'emailed' => $email && $this->emailQuote($tenantId, $sent['quote'], $link),
            ]);
        });
    }

    /** @param array<string, string> $params */
    public function newLink(Request $request, array $params): Response
    {
        return $this->run($request, 'quote', function (int $tenantId, ?int $actor) use ($params): Response {
            $link = $this->link($this->quotes->newLink($tenantId, (int) $params['id']));

            return Response::json(['link' => $link, 'document_id' => $this->printSent($tenantId, $actor, (int) $params['id'], $link)]);
        });
    }

    /**
     * `POST /api/sales/quotes/{id}/print`, `{lang: "en" | "ar"}`: the quote's
     * PDF. A quote that went out prints the copy it went out as; a draft
     * prints a working copy. The document's id is in `X-Document-Id`; when
     * this instance can't make PDFs the answer is JSON naming it, with 202.
     *
     * @param array<string, string> $params
     */
    public function print(Request $request, array $params): Response
    {
        return $this->run($request, 'print', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            if ($this->documents === null || $actor === null) {
                return Response::error('Printing needs a person signed in', 403);
            }
            $lang = JsonBody::parsed($request)['lang'] ?? 'en';
            if (!in_array($lang, ['en', 'ar'], true)) {
                throw SalesRejectedException::field('lang', 'lang is "en" or "ar"');
            }
            try {
                $printed = $this->documents->print($this->actingAs($tenantId, $actor), (int) $params['id'], $lang);
            } catch (DocumentIssueRefused $e) {
                return Response::error($e->clientMessage, $e->status, ['code' => $e->reason] + $e->details);
            }
            if ($printed['pdf'] === null) {
                return Response::json(['data' => [
                    'document_id' => $printed['document_id'],
                    'number' => $printed['number'],
                    'title' => $printed['title'],
                    'pdf' => false,
                ]], 202);
            }
            $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $printed['number'] ?? $printed['title']), '-');

            return new Response(200, $printed['pdf']['bytes'], [
                'Content-Type' => $printed['pdf']['content_type'],
                'Content-Disposition' => 'inline; filename="' . ($name !== '' ? $name : 'quote') . '.pdf"',
                'X-Document-Id' => (string) $printed['document_id'],
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    /** @param array<string, string> $params */
    public function decide(Request $request, array $params): Response
    {
        return $this->run($request, 'quote', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $checked = FieldValidator::check($body, [
                'name' => Field::text()->max(200),
                'reason' => Field::text()->max(1000),
            ]);
            if (!$checked->ok()) {
                return $checked->refusal('The answer could not be recorded');
            }
            $accepted = $body['accepted'] ?? null;
            if (!is_bool($accepted)) {
                throw SalesRejectedException::field('accepted', 'accepted is true (the customer said yes) or false (they declined)');
            }

            return Response::json(['data' => $this->quotes->decide(
                $tenantId, (int) $params['id'], $accepted, $checked->value('name'), $checked->value('reason'), $actor, false
            )]);
        });
    }

    /** @param array<string, string> $params */
    public function revise(Request $request, array $params): Response
    {
        return $this->run($request, 'quote', fn (int $tenantId, ?int $actor): Response => Response::json([
            'data' => $this->quotes->revise($tenantId, (int) $params['id'], $actor),
        ], 201));
    }

    /** @param array<string, string> $params */
    public function cancel(Request $request, array $params): Response
    {
        return $this->run($request, 'quote', fn (int $tenantId, ?int $actor): Response => Response::json([
            'data' => $this->quotes->cancel($tenantId, (int) $params['id'], $actor),
        ]));
    }

    /** @param array<string, string> $params */
    public function targets(Request $request, array $params): Response
    {
        return $this->run($request, 'quote', fn (int $tenantId, ?int $actor): Response => Response::json([
            'data' => $this->quotes->targets($tenantId, (int) $params['id'], (int) $actor),
        ]));
    }

    /** @param array<string, string> $params */
    public function convert(Request $request, array $params): Response
    {
        return $this->run($request, 'quote', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            $checked = FieldValidator::check(JsonBody::parsed($request), [
                'target' => Field::text()->required()->max(60),
            ]);
            if (!$checked->ok()) {
                return $checked->refusal('Choose what to turn the quote into');
            }
            if ($actor === null) {
                return Response::error('Sign in to turn a quote into something else', 403);
            }
            $result = $this->quotes->convert($tenantId, (int) $params['id'], $checked->value('target'), $actor);

            return Response::json(['data' => $result['quote'], 'target' => $result['target']], $result['repeated'] ? 200 : 201);
        });
    }

    public function showSettings(Request $request): Response
    {
        return $this->run($request, 'settings', fn (int $tenantId): Response => Response::json(['data' => $this->settingsFor($tenantId)]));
    }

    public function updateSettings(Request $request): Response
    {
        return $this->run($request, 'settings', function (int $tenantId) use ($request): Response {
            $changes = [];
            foreach (JsonBody::parsed($request) as $field => $value) {
                $key = self::SETTING_KEYS[$field] ?? throw SalesRejectedException::field((string) $field, $field . ' is not a sales setting');
                $text = match (true) {
                    is_bool($value) && str_starts_with((string) $field, 'email_') => $value ? 'true' : 'false',
                    is_int($value) && !str_starts_with((string) $field, 'email_') => (string) $value,
                    $value === null && $field === 'quote_route_template_id' => '0',
                    default => throw SalesRejectedException::field((string) $field, str_starts_with((string) $field, 'email_') ? $field . ' is true or false' : $field . ' is a whole number'),
                };
                $problem = SettingsRegistry::validate($key, $text);
                if ($problem !== null) {
                    throw SalesRejectedException::field((string) $field, $problem);
                }
                $changes[$key] = $text;
            }
            foreach ($changes as $key => $text) {
                $this->settings->setTenant($tenantId, $key, $text);
            }

            return Response::json(['data' => $this->settingsFor($tenantId)]);
        });
    }

    // ── the customer's page ──────────────────────────────────────────────

    /** @param array<string, string> $params */
    public function publicShow(Request $request, array $params): Response
    {
        return $this->guard($request, 'quote:info:', RateLimits::publicQuoteViewPerAddress(), function () use ($params): Response {
            $found = $this->quotes->byToken((string) ($params['token'] ?? ''));
            if ($found === null) {
                return Response::error(self::LINK_INVALID, 404);
            }
            self::enter($found['tenant_id']);
            $view = $this->quotes->publicView($found['tenant_id'], $found['quote_id']);
            $name = $this->workspaceName !== null ? (string) ($this->workspaceName)($found['tenant_id']) : '';

            return Response::json(['data' => $view + [
                'replaced' => $found['replaced'],
                'workspace' => ['name' => $name, 'brand' => $this->brandFor($found['tenant_id'], $name)],
            ]])->withHeaders(['Cache-Control' => 'no-store']);
        });
    }

    /** @param array<string, string> $params */
    public function publicAccept(Request $request, array $params): Response
    {
        return $this->publicDecide($request, $params, true);
    }

    /** @param array<string, string> $params */
    public function publicDecline(Request $request, array $params): Response
    {
        return $this->publicDecide($request, $params, false);
    }

    /** @param array<string, string> $params */
    private function publicDecide(Request $request, array $params, bool $accepted): Response
    {
        return $this->guard($request, 'quote:decide:', RateLimits::publicQuoteAnswerPerAddress(), function () use ($request, $params, $accepted): Response {
            $found = $this->quotes->byToken((string) ($params['token'] ?? ''));
            if ($found === null || $found['replaced']) {
                return Response::error($found === null ? self::LINK_INVALID : 'A newer version of this quote has been sent. Open the newest link to answer.', $found === null ? 404 : 409);
            }
            $checked = FieldValidator::check(JsonBody::parsed($request), [
                'name' => Field::text()->required($accepted)->max(200)
                    ->say('required', 'Type your name to accept the quote', 'اكتب اسمك لقبول عرض السعر'),
                'reason' => Field::text()->max(1000),
            ]);
            if (!$checked->ok()) {
                return $checked->refusal($accepted ? 'Type your name to accept the quote' : 'Your answer could not be sent');
            }
            self::enter($found['tenant_id']);
            $this->quotes->decide($found['tenant_id'], $found['quote_id'], $accepted, $checked->value('name'), $checked->value('reason'), null, true);

            return Response::json(['data' => $this->quotes->publicView($found['tenant_id'], $found['quote_id'])]);
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * Emails a quote just sent to its customer's address. Best effort: the
     * quote has gone out either way, and the answer says whether an email did.
     *
     * @param array<string, mixed> $quote
     */
    private function emailQuote(int $tenantId, array $quote, string $link): bool
    {
        $to = (string) ($quote['contact_email'] ?? '');
        if ($this->emails === null || $to === '') {
            return false;
        }
        $what = [];
        foreach (is_array($quote['lines'] ?? null) ? $quote['lines'] : [] as $line) {
            if (is_array($line) && is_string($line['description'] ?? null) && $line['description'] !== '') {
                $what[] = $line['description'];
            }
        }

        return $this->emails->quote($tenantId, $to, [
            'number' => (string) $quote['number'],
            'customer' => (string) $quote['contact_name'],
            'what' => count($what) <= 2 ? implode(', ', $what) : $what[0] . ', ' . $what[1] . ' …',
            'total_minor' => (int) $quote['total_minor'],
            'currency' => (string) $quote['currency'],
            'valid_until' => (string) $quote['valid_until'],
        ], $link);
    }

    /**
     * The printed copy that goes out with the quote, in the workspace's
     * language, carrying the link's QR. Null when it could not be made: the
     * quote has gone out all the same.
     */
    private function printSent(int $tenantId, ?int $actor, int $quoteId, string $link): ?int
    {
        if ($this->documents === null || $actor === null) {
            return null;
        }
        try {
            $locale = (string) ($this->settings->effective($tenantId)[SettingsRegistry::LOCALE] ?? 'en');
            $lang = str_starts_with(strtolower(trim($locale)), 'ar') ? 'ar' : 'en';

            return $this->documents->issueSent($this->actingAs($tenantId, $actor), $quoteId, $lang, $link)['document_id'];
        } catch (\Throwable $e) {
            error_log('[SalesQuotesApiHandler] the printed copy of quote ' . $quoteId . ' could not be made: ' . $e->getMessage());

            return null;
        }
    }

    private function actingAs(int $tenantId, int $profileId): ActingAs
    {
        $checker = $this->roleChecker;

        return new ActingAs(
            $tenantId,
            $profileId,
            static fn (string $permission): bool => $checker !== null && $checker->hasPermissionForProfile($profileId, $permission, $tenantId),
        );
    }

    private function link(string $token): string
    {
        return rtrim($this->appBase, '/') . '/quote/' . $token;
    }

    /** @return array<string, mixed> */
    private function settingsFor(int $tenantId): array
    {
        $effective = $this->settings->effective($tenantId);
        $defaults = SettingsRegistry::defaults();
        $days = (int) ($effective[SettingsRegistry::SALES_QUOTE_VALID_DAYS] ?? $defaults[SettingsRegistry::SALES_QUOTE_VALID_DAYS]);
        $route = (int) ($effective[SettingsRegistry::SALES_QUOTE_ROUTE_TEMPLATE_ID] ?? 0);

        $on = static fn (string $key): bool => (string) ($effective[$key] ?? $defaults[$key] ?? 'true') === 'true';

        return [
            'quote_valid_days' => $days,
            'quote_route_template_id' => $route === 0 ? null : $route,
            'email_quote' => $on(SettingsRegistry::SALES_EMAIL_QUOTE),
            'email_invoice' => $on(SettingsRegistry::SALES_EMAIL_INVOICE),
            'email_receipt' => $on(SettingsRegistry::SALES_EMAIL_RECEIPT),
            'email_reminder' => $on(SettingsRegistry::SALES_EMAIL_REMINDER),
        ];
    }

    /** @return array<string, mixed>|null */
    private function brandFor(int $tenantId, string $name): ?array
    {
        if ($this->branding === null) {
            return null;
        }
        try {
            return $this->branding->forPublicPage($tenantId, $name);
        } catch (\Throwable) {
            return null;
        }
    }

    private function run(Request $request, string $area, callable $work): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                return Response::error('A workspace is needed for quotes', 403);
            }
            $user = $request->user;
            $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;

            return $work($tenantId, $actor);
        } catch (SalesRejectedException $e) {
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (\Throwable $e) {
            error_log('[SalesQuotesApiHandler] ' . $area . ' failed: ' . $e->getMessage());

            return Response::error('Something went wrong with quotes. Try again.', 500);
        }
    }

    private function guard(Request $request, string $prefix, RateLimitRule $rule, callable $work): Response
    {
        try {
            $ip = ClientIp::fromRequest($request);
            if ($ip !== null && $ip !== '') {
                $limited = $rule->take($this->store, $prefix . $ip);
                if ($limited !== null) {
                    return $limited;
                }
            }

            return $work();
        } catch (SalesRejectedException $e) {
            return $e->status === 404 ? Response::error(self::LINK_INVALID, 404) : Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (\Throwable $e) {
            error_log('[SalesQuotesApiHandler] public page failed: ' . $e->getMessage());

            return Response::error('Something went wrong. Please try again.', 500);
        }
    }

    private static function enter(int $tenantId): void
    {
        if (TenantContext::getTenantId() !== $tenantId) {
            TenantContext::setTenantId($tenantId);
        }
    }

    /** @return array<string, string> */
    private static function query(Request $request): array
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $query[$k] = $v;
                }
            }
        }

        return $query;
    }
}
