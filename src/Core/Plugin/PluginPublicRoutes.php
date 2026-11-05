<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Core\Router;
use Whity\Sdk\Http\PublicForm;
use Whity\Sdk\PluginNamespace;

/**
 * The plugin webhook routes that bypass tenant resolution (WHIT-671).
 *
 * WHY THIS EXISTS
 * ---------------
 * An external service delivering a notification (a billing or identity
 * provider's `subscription.updated`, say) holds no session and never will, so
 * {@see \Whity\Http\Middleware\EnforceTenantIsolation} 401s it before it is
 * routed. Core's own such endpoints are listed by hand in that middleware.
 * Before this, a plugin could not get onto that list at all, and core must
 * not name a product to put it there.
 *
 * WHAT A PLUGIN MAY MAKE PUBLIC: exactly one shape, decided by
 * {@see self::refusal()}. All of these must hold:
 *   - the method is POST (a notification, never a read);
 *   - the path is `/api/<the plugin's own slug>/webhooks/<name>`, with
 *     <name> matching `[a-z0-9][a-z0-9-]{0,62}` and no parameters, so a plugin
 *     can never open another plugin's or core's paths, and nothing can be
 *     public beneath an open prefix;
 *   - the route demands no permission and no role (asking for both is a
 *     contradiction, so it is refused rather than guessed at).
 *
 * WHAT IS ADMITTED AT REQUEST TIME is narrower than "this path": the METHOD
 * must be the registered one (POST), and the route the router actually
 * resolves for the request must belong to the plugin that registered it
 * ({@see self::admits()}). So a later route on the same path with another
 * method, or a core PATTERN route that happens to capture the plugin's literal
 * path, is never reached without a tenant because of this registry — the
 * bypass is tied to the plugin's own handler, not to a string.
 *
 * PUBLIC MEANS ONLY "NO TENANT RESOLUTION". It does not authenticate the
 * caller; the plugin's handler must, by verifying the sender's signature over
 * the raw body before acting on anything in it. That is the same rule core's
 * own webhooks follow.
 *
 * A PUBLIC FORM (WHIT-906) is the second kind: `'public' => 'form'` on POST
 * `/api/<slug>/forms/<name>/{key}`, for an anonymous browser rather than a signed
 * server. {@see self::formRefusal()} decides the shape, and admission needs one
 * more thing than a webhook's: {@see \Whity\Http\Middleware\PublicFormGate} must
 * already have passed the request. If the gate isn't running, the route is closed.
 *
 * PER PROCESS. Every FrankenPHP worker loads plugins at boot and fills its own
 * copy; a disabled, reloaded or uninstalled plugin's routes are removed with
 * its other routes.
 */
final class PluginPublicRoutes
{
    /** The only method a public plugin route may use. */
    public const METHOD = 'POST';

    private const NAME_PATTERN = '[a-z0-9][a-z0-9-]{0,62}';

    /** @var array<string, string> "METHOD path" => owner (the plugin's namespace prefix) */
    private static array $owners = [];

    /** @var array<string, array{owner: string, plugin: string, form: string}> served path => the form route */
    private static array $forms = [];

    /**
     * Why a declared public route is refused, or null when it is acceptable.
     */
    public static function refusal(
        string $pluginName,
        string $method,
        string $path,
        mixed $requiredPermission,
        mixed $requiredRole,
    ): ?string {
        if (strtoupper($method) !== self::METHOD) {
            return 'a public plugin route must be POST';
        }
        $slug = PluginNamespace::slug($pluginName);
        if ($slug === null) {
            return 'the plugin name yields no namespace slug';
        }
        // `D`: `$` must not also match before a trailing newline.
        $shape = '#^/api/' . preg_quote($slug, '#') . '/webhooks/' . self::NAME_PATTERN . '$#D';
        if (preg_match($shape, $path) !== 1) {
            return "a public plugin route must be exactly /api/{$slug}/webhooks/<name>, <name> matching " . self::NAME_PATTERN;
        }
        if ($requiredPermission !== null || $requiredRole !== null) {
            return 'a public plugin route cannot also require a permission or a role';
        }
        return null;
    }

    /**
     * Why a declared public FORM route is refused, or null when it is acceptable.
     */
    public static function formRefusal(
        string $pluginName,
        string $method,
        string $path,
        mixed $requiredPermission,
        mixed $requiredRole,
    ): ?string {
        if (strtoupper($method) !== self::METHOD) {
            return 'a public form route must be POST';
        }
        $slug = PluginNamespace::slug($pluginName);
        if ($slug === null) {
            return 'the plugin name yields no namespace slug';
        }
        $shape = '#^/api/' . preg_quote($slug, '#') . '/forms/' . self::NAME_PATTERN . '/\{key\}$#D';
        if (preg_match($shape, $path) !== 1) {
            return "a public form route must be exactly /api/{$slug}/forms/<name>/{key}, <name> matching " . self::NAME_PATTERN;
        }
        if ($requiredPermission !== null || $requiredRole !== null) {
            return 'a public form route cannot also require a permission or a role';
        }
        return null;
    }

    /** Record a served form route (the router's /api/v1/... pattern). */
    public static function addForm(string $owner, string $pluginName, string $servedPath, string $declaredPath): void
    {
        $segments = explode('/', $declaredPath);
        self::$forms[$servedPath] = [
            'owner' => $owner,
            'plugin' => (string) PluginNamespace::slug($pluginName),
            'form' => $segments[count($segments) - 2],
        ];
    }

    /**
     * The form route a request is for, or null. It has to be this plugin's own
     * route that the router resolves, and the key comes from the path alone.
     *
     * @return array{owner: string, plugin: string, form: string, key: string}|null
     */
    public static function formFor(\Whity\Sdk\Http\Request $request, ?Router $router): ?array
    {
        if (self::$forms === [] || $router === null || !str_contains($request->getPath(), '/forms/')) {
            return null;
        }
        // A preflight asks about the POST, so the POST is what gets matched.
        $route = $router->match(new \Whity\Core\Request(self::METHOD, $request->getPath(), [], ''));
        $form = $route === null ? null : (self::$forms[$route['path']] ?? null);
        if ($route === null || $form === null || ($route['namespacePrefix'] ?? null) !== $form['owner']) {
            return null;
        }

        return $form + ['key' => (string) ($route['params']['key'] ?? '')];
    }

    /**
     * The form routes that can be issued a key, for the admin screen.
     *
     * @return list<array{plugin: string, form: string}>
     */
    public static function forms(): array
    {
        $out = [];
        foreach (self::$forms as $form) {
            $out[] = ['plugin' => $form['plugin'], 'form' => $form['form']];
        }
        usort($out, static fn (array $a, array $b): int => [$a['plugin'], $a['form']] <=> [$b['plugin'], $b['form']]);

        return $out;
    }

    /** Record a served route (the router's /api/v1/... path) as public. */
    public static function add(string $owner, string $method, string $servedPath): void
    {
        self::$owners[strtoupper($method) . ' ' . $servedPath] = $owner;
    }

    /** Forget every public route an owner (a plugin's namespace) registered. */
    public static function removeOwner(string $owner): void
    {
        self::$owners = array_filter(self::$owners, static fn (string $o): bool => $o !== $owner);
        self::$forms = array_filter(self::$forms, static fn (array $f): bool => $f['owner'] !== $owner);
    }

    /** Whether this exact method and served path were registered as public. */
    public static function allows(string $method, string $path): bool
    {
        return isset(self::$owners[strtoupper($method) . ' ' . $path])
            || (strtoupper($method) === self::METHOD && isset(self::$forms[$path]));
    }

    /**
     * Whether a request may skip tenant resolution as a plugin webhook: the
     * method and path were registered public AND the route the router resolves
     * for it is the registering plugin's own. Without a router to ask, nothing
     * is admitted.
     */
    public static function admits(\Whity\Sdk\Http\Request $request, ?Router $router, string $path): bool
    {
        // The router matches core requests; anything else is not admitted.
        if (!$request instanceof \Whity\Core\Request) {
            return false;
        }
        // A public form: only once the gate has passed it.
        if ($request->hasAttribute(PublicForm::ATTRIBUTE)
            && strtoupper($request->getMethod()) === self::METHOD
            && self::formFor($request, $router) !== null
        ) {
            return true;
        }
        $key = strtoupper($request->getMethod()) . ' ' . $path;
        $owner = self::$owners[$key] ?? null;
        if ($owner === null || $router === null) {
            return false;
        }
        $route = $router->match($request);
        return $route !== null && ($route['namespacePrefix'] ?? null) === $owner;
    }

    /** Tests only: start from nothing. */
    public static function reset(): void
    {
        self::$owners = [];
        self::$forms = [];
    }
}
