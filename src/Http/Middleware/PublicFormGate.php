<?php

declare(strict_types=1);

namespace Whity\Http\Middleware;

use Whity\Core\Plugin\PluginPublicRoutes;
use Whity\Core\Plugin\PublicForm\BotCheck;
use Whity\Core\Plugin\PublicForm\PublicFormKeyRepository;
use Whity\Core\Plugin\PublicForm\PublicFormOrigins;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request as CoreRequest;
use Whity\Core\Router;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Cors;
use Whity\Sdk\Http\PublicForm;
use Whity\Sdk\Http\PublicRead;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * Everything the host does before a plugin's public form handler runs (WHIT-906).
 *
 * WHY THE HOST DOES IT. A check a plugin has to remember is a check some plugin
 * forgets, and the plugin that forgets it is open to the whole internet. So a route
 * declared `'public' => 'form'` can't be reached any other way: the tenant
 * middleware and the handler wrapper both refuse a request this gate didn't pass.
 *
 * THE ORDER, each step before the next costs anything:
 *
 *  1. Cookies, Authorization and the tenant header are removed, here rather than
 *     at the handler, so {@see CsrfGuard} and everything after it sees an anonymous
 *     request. No session is read, and Set-Cookie is removed from the answer.
 *  2. Per-IP limit, counted BEFORE the key is looked at, so a limiter firing
 *     differently for real keys can't tell anyone which keys are real.
 *  3. Body cap and media type. JSON only: it needs a preflight, so a page on
 *     another site can't send one blind, and no multipart body goes unmeasured
 *     (PHP drains those into $_FILES).
 *  4. The key, which alone names the tenant. Malformed, unknown, revoked, another
 *     plugin's and another form's all give the same 404, as in
 *     {@see \Whity\Api\PublicFormsApiHandler}, and for the same reasons.
 *  5. Origin, against the key's list and the instance's allowed origins. A
 *     script can forge this, so it stops other sites' pages rather than bots.
 *  6. Whether the bot check is set up. See {@see BotCheck} for why not is a refusal.
 *  7. The per-form-and-tenant ceiling, which holds across many addresses. Charged
 *     before the bot check, and given back if the check refuses, so that failed
 *     tokens can't use up a workspace's ceiling and lock its real visitors out.
 *  8. The bot check, server to server.
 *
 * A refusal is a plain 4xx or 429 and the handler never runs. A provider that can't
 * be reached is a 403 too, with Retry-After: it is a refusal of this request, and
 * anonymous traffic during someone else's outage shouldn't read as our 5xx.
 *
 * The tenant is locked into {@see TenantContext} only after all of it, so the
 * post-auth limiter and the payment wall treat the request as the tenant's: a form
 * spends the tenant's request budget, and a lapsed tenant's form answers 402.
 *
 * A PUBLIC READ (WHIT-704), `'public' => 'read'` on GET, goes through the same steps
 * minus the ones that only make sense for a body: 1, a per-IP limit, 4 (the same
 * keys), 5, and the ceiling per read and tenant. No bot check: a read changes
 * nothing, and a page fetching it can't be asked to solve one each time. Origin is
 * checked only when the caller sends one. A browser sends none for a link opened
 * directly, and without the CORS headers only an allowed origin gets, no page on
 * another site can read the answer anyway. Reads are OFF until the operator sets
 * `PLUGIN_PUBLIC_READS=on`: until then this gate leaves them alone, so the tenant
 * middleware asks for a sign-in and the handler wrapper refuses even a signed-in one.
 *
 * Runs after the pre-auth limiter and before anything that reads a credential.
 * Holds no request state, so it is safe in a FrankenPHP worker.
 */
final class PublicFormGate
{
    public const WINDOW_SECONDS = 3600;

    /** Per IP, per form, an hour. The same reasoning as core's public form submit. */
    public const IP_MAX = 20;

    /** Per IP on the read side (preflights and the bot-check config), an hour. */
    public const READ_IP_MAX = 120;

    /** Per form and tenant across every address, an hour. Core's public forms use the same. */
    public const FORM_MAX = 300;

    /** Per IP, per read, an hour (WHIT-704). A page may fetch a read on every view. */
    public const READ_ROUTE_IP_MAX = 600;

    /** Per read and tenant across every address, an hour (WHIT-704). */
    public const READ_ROUTE_MAX = 6000;

    /** A form with no files. Well above any real one, and far below the API's 1 MiB. */
    public const MAX_BODY_BYTES = 65536;

    private const STRIPPED_HEADERS = ['cookie', 'authorization', 'x-tenant-id', 'x-auth-mode'];

    private const PREFLIGHT_MAX_AGE = 600;

    /** @param \Closure(): list<string> $instanceOrigins */
    public function __construct(
        private readonly Router $router,
        private readonly PublicFormKeyRepository $keys,
        private readonly SharedStoreInterface $store,
        private readonly BotCheck $botCheck,
        private readonly ?\Closure $instanceOrigins = null,
        private readonly bool $readsEnabled = false,
    ) {
    }

    /**
     * Whether the operator switched plugins' public reads on (WHIT-704). Off unless set.
     *
     * @param array<string, mixed> $env
     */
    public static function readsEnabledFromEnv(array $env): bool
    {
        return in_array(strtolower(trim((string) ($env['PLUGIN_PUBLIC_READS'] ?? ''))), ['on', 'true', '1', 'yes'], true);
    }

    public function handle(Request $request, callable $next): Response
    {
        if (strtoupper($request->getMethod()) === PluginPublicRoutes::READ_METHOD) {
            return $this->handleRead($request, $next);
        }
        if (strtoupper($request->getMethod()) !== PluginPublicRoutes::METHOD) {
            return $next($request);
        }
        $form = PluginPublicRoutes::formFor($request, $this->router);
        if ($form === null) {
            return $next($request);
        }

        $botToken = $request->getHeader(PublicForm::BOT_TOKEN_HEADER);
        $request = self::anonymous($request);
        $ip = ClientIp::fromRequest($request);

        // 2. Per IP, before anything about the key is known.
        if (($limited = $this->charge('pluginform:ip:' . $form['plugin'] . ':' . $form['form'] . ':' . ($ip ?? 'unknown'), self::IP_MAX)) !== null) {
            return $limited;
        }

        // 3. Size and type.
        $declared = $request->getHeader('Content-Length');
        if (strlen($request->getBody()) > self::MAX_BODY_BYTES || ($declared !== null && (int) $declared > self::MAX_BODY_BYTES)) {
            return Response::error('This form is too large', 413);
        }
        if (strtolower(trim(explode(';', (string) $request->getHeader('Content-Type'), 2)[0])) !== 'application/json') {
            return Response::error('This form must be sent as JSON', 415);
        }

        try {
            // 4. The key, and through it the tenant. Nothing else can name one.
            $row = $this->keys->findLive($form['key']);
            if ($row === null || $row['plugin'] !== $form['plugin'] || $row['form_name'] !== $form['form']) {
                return self::notFound();
            }

            // 5. Origin. Refusals from here on are to someone holding a live key.
            $origin = (string) $request->getHeader('Origin');
            if (!PublicFormOrigins::allows($origin, $row['allowed_origins'], $this->instanceOrigins())) {
                return Response::error('This form cannot be sent from this site', 403);
            }
            $cors = self::corsFor(strtolower(trim($origin)));

            // 6. Is there a bot check to run, or an operator's say-so that none is needed?
            if (!$this->botCheck->isReady()) {
                return Response::error('This form is not open yet', 403)->withHeaders($cors);
            }

            // 7. The ceiling across addresses.
            $tenantId = (int) $row['tenant_id'];
            $ceilingKey = 'pluginform:form:' . $tenantId . ':' . $form['plugin'] . ':' . $form['form'];
            if (($limited = $this->charge($ceilingKey, self::FORM_MAX)) !== null) {
                return $limited->withHeaders($cors);
            }

            // 8. The bot check.
            if ($this->botCheck->isRequired()) {
                $verdict = $this->botCheck->verify((string) $botToken, $ip);
                if ($verdict !== BotCheck::PASSED) {
                    $this->store->decrement($ceilingKey);

                    return ($verdict === BotCheck::UNREACHABLE
                        ? Response::error('This form cannot check for bots right now. Please try again shortly.', 403)
                            ->withHeaders(['Retry-After' => '60'])
                        : Response::error('The bot check did not pass. Please try again.', 403)
                    )->withHeaders($cors);
                }
            }
        } catch (\Throwable $e) {
            error_log('[PublicFormGate] the check failed: ' . $e->getMessage());

            return Response::error('This form is temporarily unavailable', 503);
        }

        TenantContext::setTenantId($tenantId);
        $request->setAttribute(PublicForm::ATTRIBUTE, new PublicForm($tenantId, $form['plugin'], $form['form'], strtolower(trim($origin))));

        $response = $next($request);

        // No session is set either, whatever the handler asked for.
        if (!headers_sent()) {
            header_remove('Set-Cookie');
        }

        return self::withoutCookies($response)->withHeaders($cors);
    }

    /**
     * A plugin's public read (WHIT-704). See the class comment for the steps.
     */
    private function handleRead(Request $request, callable $next): Response
    {
        if (!$this->readsEnabled) {
            return $next($request);
        }
        $read = PluginPublicRoutes::readFor($request, $this->router);
        if ($read === null) {
            return $next($request);
        }

        $request = self::anonymous($request);
        $ip = ClientIp::fromRequest($request);

        if (($limited = $this->charge('pluginread:ip:' . $read['plugin'] . ':' . $read['form'] . ':' . ($ip ?? 'unknown'), self::READ_ROUTE_IP_MAX)) !== null) {
            return $limited;
        }

        try {
            $row = $this->keys->findLive($read['key']);
            if ($row === null || $row['plugin'] !== $read['plugin'] || $row['form_name'] !== $read['form']) {
                return Response::error('This link is not valid, or is no longer open', 404);
            }

            $origin = $request->getHeader('Origin');
            $cors = [];
            if ($origin !== null) {
                if (!PublicFormOrigins::allows($origin, $row['allowed_origins'], $this->instanceOrigins())) {
                    return Response::error('This link cannot be read from this site', 403);
                }
                $cors = self::corsFor(strtolower(trim($origin)));
            }

            $tenantId = (int) $row['tenant_id'];
            if (($limited = $this->charge('pluginread:read:' . $tenantId . ':' . $read['plugin'] . ':' . $read['form'], self::READ_ROUTE_MAX)) !== null) {
                return $limited->withHeaders($cors);
            }
        } catch (\Throwable $e) {
            error_log('[PublicFormGate] the read check failed: ' . $e->getMessage());

            return Response::error('This link is temporarily unavailable', 503);
        }

        TenantContext::setTenantId($tenantId);
        $request->setAttribute(PublicRead::ATTRIBUTE, new PublicRead($tenantId, $read['plugin'], $read['form'], $origin === null ? '' : strtolower(trim($origin))));

        $response = $next($request);

        if (!headers_sent()) {
            header_remove('Set-Cookie');
        }

        return self::withoutCookies($response)->withHeaders($cors);
    }

    /**
     * Answer a browser's preflight for a form route, or null when the request isn't one.
     *
     * Always a 204, so it can't be asked which keys are real. Only a live key with
     * this origin on its list gets the headers that let the browser go on.
     */
    public function preflight(Request $request): ?Response
    {
        if (strtoupper($request->getMethod()) !== 'OPTIONS') {
            return null;
        }
        $form = PluginPublicRoutes::formFor($request, $this->router);
        if ($form === null) {
            return null;
        }

        $ip = ClientIp::fromRequest($request);
        if (($limited = $this->charge('pluginform:read:ip:' . ($ip ?? 'unknown'), self::READ_IP_MAX)) !== null) {
            return $limited;
        }

        try {
            $row = $this->keys->findLive($form['key']);
            $origin = (string) $request->getHeader('Origin');
            if ($row !== null && $row['plugin'] === $form['plugin'] && $row['form_name'] === $form['form']
                && PublicFormOrigins::allows($origin, $row['allowed_origins'], $this->instanceOrigins())
            ) {
                return new Response(204, '', self::corsFor(strtolower(trim($origin))) + [
                    'Access-Control-Allow-Methods' => PluginPublicRoutes::METHOD,
                    'Access-Control-Allow-Headers' => 'Content-Type, ' . PublicForm::BOT_TOKEN_HEADER,
                    'Access-Control-Max-Age' => (string) self::PREFLIGHT_MAX_AGE,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[PublicFormGate] the preflight check failed: ' . $e->getMessage());
        }

        return new Response(204, '', ['Vary' => 'Origin']);
    }

    /**
     * Whether this request's CORS headers are this gate's to set, instead of the
     * instance-wide ones. Those allow credentials, which a public form never does.
     */
    public function ownsCors(Request $request): bool
    {
        return PluginPublicRoutes::formFor($request, $this->router) !== null
            || ($this->readsEnabled && PluginPublicRoutes::readFor($request, $this->router) !== null)
            || preg_match('#^/api/v1/public/plugin-forms/[^/]+$#D', $request->getPath()) === 1;
    }

    /** @return array<string, string> The headers that let this origin read the answer. */
    public static function corsFor(string $origin): array
    {
        return ['Access-Control-Allow-Origin' => $origin, 'Access-Control-Expose-Headers' => 'Retry-After', 'Vary' => 'Origin'];
    }

    /**
     * One fixed-window count, charged atomically and given back when over, so the
     * limit is a hard ceiling even with workers racing for the last place.
     */
    private function charge(string $key, int $max): ?Response
    {
        if ($this->store->increment($key, self::WINDOW_SECONDS) > $max) {
            $this->store->decrement($key);

            return Response::error('Too many attempts. Please try again later.', 429)
                ->withHeaders(['Retry-After' => (string) max($this->store->ttl($key), 1)]);
        }

        return null;
    }

    private static function notFound(): Response
    {
        return Response::error('This form link is not valid, or is no longer open', 404);
    }

    /** @return list<string> */
    private function instanceOrigins(): array
    {
        return $this->instanceOrigins !== null ? ($this->instanceOrigins)() : Cors::allowedOriginsFromEnv();
    }

    /**
     * The same request with nothing on it that could identify anyone.
     *
     * A new object because a request's headers are immutable. The superglobals go
     * too, since that is where the cookie reader and some plugin code look.
     */
    private static function anonymous(Request $request): Request
    {
        $headers = $request->getHeaders();
        foreach (array_keys($headers) as $name) {
            $lower = strtolower((string) $name);
            if (in_array($lower, self::STRIPPED_HEADERS, true) || $lower === strtolower(PublicForm::BOT_TOKEN_HEADER)) {
                unset($headers[$name]);
            }
        }
        unset($_SERVER['HTTP_COOKIE'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_TENANT_ID'], $_SERVER['HTTP_X_AUTH_MODE'], $_SERVER['HTTP_X_WHITY_BOT_TOKEN']);
        $_COOKIE = [];

        return new CoreRequest($request->getMethod(), $request->getPath(), $headers, $request->getBody());
    }

    private static function withoutCookies(Response $response): Response
    {
        $headers = $response->getHeaders();
        foreach (array_keys($headers) as $name) {
            if (strtolower((string) $name) === 'set-cookie') {
                unset($headers[$name]);

                return new Response($response->getStatusCode(), $response->getBody(), $headers);
            }
        }

        return $response;
    }
}
