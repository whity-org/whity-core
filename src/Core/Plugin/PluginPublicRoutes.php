<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Core\Router;
use Whity\Sdk\Http\PublicForm;
use Whity\Sdk\Http\PublicRead;
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
 * A PUBLIC READ (WHIT-704) is the third: `'public' => 'read'` on GET
 * `/api/<slug>/public/<name>/{key}`, for something anybody holding the link may
 * see. {@see self::readRefusal()} decides the shape. It uses a form's key and
 * shares its names: a plugin can't have a form and a read with the same name, so
 * a key an admin issued for one never opens the other. It is admitted only once
 * {@see \Whity\Http\Middleware\PublicFormGate} has passed it, and the gate passes
 * reads only when the operator has switched them on.
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

    /** The method a public read uses (WHIT-704). */
    public const READ_METHOD = 'GET';

    public const KIND_FORM = 'form';
    public const KIND_READ = 'read';

    /** @var array<string, array{owner: string, plugin: string, form: string, kind: string}> served path => the keyed route */
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

    /**
     * Why a declared public READ route is refused, or null when it is acceptable (WHIT-704).
     */
    public static function readRefusal(
        string $pluginName,
        string $method,
        string $path,
        mixed $requiredPermission,
        mixed $requiredRole,
    ): ?string {
        if (strtoupper($method) !== self::READ_METHOD) {
            return 'a public read route must be GET';
        }
        $slug = PluginNamespace::slug($pluginName);
        if ($slug === null) {
            return 'the plugin name yields no namespace slug';
        }
        $shape = '#^/api/' . preg_quote($slug, '#') . '/public/' . self::NAME_PATTERN . '/\{key\}$#D';
        if (preg_match($shape, $path) !== 1) {
            return "a public read route must be exactly /api/{$slug}/public/<name>/{key}, <name> matching " . self::NAME_PATTERN;
        }
        if ($requiredPermission !== null || $requiredRole !== null) {
            return 'a public read route cannot also require a permission or a role';
        }
        return null;
    }

    /**
     * Record a served form route (the router's /api/v1/... pattern).
     *
     * @return bool False when the plugin already has a read with that name.
     */
    public static function addForm(string $owner, string $pluginName, string $servedPath, string $declaredPath): bool
    {
        return self::addKeyed(self::KIND_FORM, $owner, $pluginName, $servedPath, $declaredPath);
    }

    /**
     * Record a served read route (WHIT-704).
     *
     * @return bool False when the plugin already has a form with that name.
     */
    public static function addRead(string $owner, string $pluginName, string $servedPath, string $declaredPath): bool
    {
        return self::addKeyed(self::KIND_READ, $owner, $pluginName, $servedPath, $declaredPath);
    }

    private static function addKeyed(string $kind, string $owner, string $pluginName, string $servedPath, string $declaredPath): bool
    {
        $segments = explode('/', $declaredPath);
        $plugin = (string) PluginNamespace::slug($pluginName);
        $name = $segments[count($segments) - 2];
        // One name, one route: a key is issued for a plugin and a name, so a form
        // and a read sharing a name would both open with the one key.
        foreach (self::$forms as $existing) {
            if ($existing['plugin'] === $plugin && $existing['form'] === $name && $existing['kind'] !== $kind) {
                return false;
            }
        }
        self::$forms[$servedPath] = ['owner' => $owner, 'plugin' => $plugin, 'form' => $name, 'kind' => $kind];

        return true;
    }

    /**
     * The form route a request is for, or null. It has to be this plugin's own
     * route that the router resolves, and the key comes from the path alone.
     *
     * @return array{owner: string, plugin: string, form: string, key: string}|null
     */
    public static function formFor(\Whity\Sdk\Http\Request $request, ?Router $router): ?array
    {
        if (!str_contains($request->getPath(), '/forms/')) {
            return null;
        }

        // A preflight asks about the POST, so the POST is what gets matched.
        return self::keyedFor(self::KIND_FORM, self::METHOD, $request, $router);
    }

    /**
     * The read route a request is for, or null (WHIT-704). The same rules as
     * {@see self::formFor()}: this plugin's own route, the key from the path alone.
     *
     * @return array{owner: string, plugin: string, form: string, key: string}|null
     */
    public static function readFor(\Whity\Sdk\Http\Request $request, ?Router $router): ?array
    {
        if (!str_contains($request->getPath(), '/public/')) {
            return null;
        }

        return self::keyedFor(self::KIND_READ, self::READ_METHOD, $request, $router);
    }

    /** @return array{owner: string, plugin: string, form: string, key: string}|null */
    private static function keyedFor(string $kind, string $method, \Whity\Sdk\Http\Request $request, ?Router $router): ?array
    {
        if (self::$forms === [] || $router === null) {
            return null;
        }
        $route = $router->match(new \Whity\Core\Request($method, $request->getPath(), [], ''));
        $entry = $route === null ? null : (self::$forms[$route['path']] ?? null);
        if ($route === null || $entry === null || $entry['kind'] !== $kind || ($route['namespacePrefix'] ?? null) !== $entry['owner']) {
            return null;
        }

        return [
            'owner' => $entry['owner'],
            'plugin' => $entry['plugin'],
            'form' => $entry['form'],
            'key' => (string) ($route['params']['key'] ?? ''),
        ];
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

    /**
     * Whether a plugin's named route is a form or a read, for the admin screen.
     * Null when no loaded plugin has it, as when the plugin has been turned off.
     */
    public static function kindOf(string $plugin, string $name): ?string
    {
        foreach (self::$forms as $entry) {
            if ($entry['plugin'] === $plugin && $entry['form'] === $name) {
                return $entry['kind'];
            }
        }

        return null;
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
        $keyed = self::$forms[$path] ?? null;
        $keyedMethod = $keyed === null ? null : ($keyed['kind'] === self::KIND_READ ? self::READ_METHOD : self::METHOD);

        return isset(self::$owners[strtoupper($method) . ' ' . $path])
            || ($keyedMethod !== null && strtoupper($method) === $keyedMethod);
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
        // A public read (WHIT-704): the same, once the gate has passed it as a read.
        if ($request->hasAttribute(PublicRead::ATTRIBUTE)
            && strtoupper($request->getMethod()) === self::READ_METHOD
            && self::readFor($request, $router) !== null
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
