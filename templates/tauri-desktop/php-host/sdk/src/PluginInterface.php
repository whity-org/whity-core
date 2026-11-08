<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * The plugin contract for the Whity platform (SDK v1.0).
 *
 * Plugins implement this interface — and only this interface — to register
 * their capabilities (routes, permissions, hooks, and migrations) with a host
 * application. The SDK is a standalone package: implementing a plugin requires
 * `whity/plugin-sdk`, never the host framework itself, which is what makes a
 * plugin distributable across Whity-based applications.
 *
 * Route handlers receive a {@see \Whity\Sdk\Http\Request} and return a
 * {@see \Whity\Sdk\Http\Response}; hook names are catalogued as constants on
 * {@see \Whity\Sdk\Hooks\Events}; migrations implement
 * {@see \Whity\Sdk\MigrationInterface}.
 */
interface PluginInterface
{
    /**
     * Get the unique name of the plugin
     *
     * @return string Plugin name
     */
    public function getName(): string;

    /**
     * Get the version of the plugin
     *
     * @return string Plugin version (e.g., '1.0.0')
     */
    public function getVersion(): string;

    /**
     * Get the routes defined by the plugin
     *
     * Each route should be an associative array with:
     * - 'method' (string): HTTP method (e.g., 'GET', 'POST')
     * - 'path' (string): Request path; `{param}` placeholders (optionally
     *   constrained, e.g. `{id:\d+}`) capture path segments
     * - 'handler' (callable): Handler callback:
     *   function(\Whity\Sdk\Http\Request $request, array $params = []): \Whity\Sdk\Http\Response
     *   where $params holds the captured path parameters (name => value)
     * - 'requiredRole' (string|null, optional): Required role name or null
     * - 'requiredPermission' (string|null, optional, host-enforced since 1.2):
     *   a `resource:action` permission (matching
     *   /^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/) the host's RBAC middleware
     *   enforces before the handler runs — a caller without it receives a 403
     *   naming the missing permission. A malformed value fails CLOSED: the
     *   host refuses to register the route rather than serve it unprotected.
     * - 'schema' (array, optional, since 1.1.1): typed OpenAPI declaration the
     *   host's generator reads — 'summary' (string), 'tags' (list<string>),
     *   'request' (component name or inline JSON-Schema array),
     *   'responses' (map of status => component name | inline array |
     *   {description: ...} object), and 'components' (map of component name =>
     *   JSON-Schema definition contributed to components.schemas).
     *
     *   **MCP typed contracts (WC-d93f7ea2)**: For a POST/PUT/PATCH route to
     *   appear as a fully typed MCP tool, the `schema['request']` key MUST be
     *   provided. When `schema['request']` is a component name (string), the
     *   corresponding schema MUST be resolvable — either through the global
     *   components map or, preferably, through a `schema['components']` entry
     *   on the same route. A missing or unresolvable request schema causes
     *   the host to emit a derivation-time lint warning and produces an MCP
     *   tool with no body parameters, which gives AI clients no guidance on
     *   the expected request shape. Inline schemas (array) are always resolved.
     * - 'public' (bool, optional, host-enforced since core 0.2.11): `true`
     *   makes the route reachable WITHOUT a session or tenant, for a webhook
     *   from an outside service that cannot hold one. Accepted for exactly one
     *   shape: method POST, path `/api/<your plugin's slug>/webhooks/<name>`
     *   (<name> matching `[a-z0-9][a-z0-9-]{0,62}`, no parameters), and no
     *   requiredPermission or requiredRole. Any other route asking to be public
     *   is NOT registered (fail-closed, with a logged reason), as is any value
     *   other than the boolean `true`. Public means only "no tenant
     *   resolution": YOUR HANDLER MUST AUTHENTICATE THE SENDER, by verifying
     *   its signature over the raw request body with `hash_equals` BEFORE
     *   decoding or acting on anything in it, and must work out which tenant
     *   an event concerns from data it trusts, never from the caller's say-so.
     *   It must not THROW on unverified input either: it answers anybody.
     *   Only the declared POST, and only while this plugin's own route is
     *   the one that answers that path, is admitted without a tenant.
     *   On an older host the key is ignored and the route stays private.
     *
     *   `'form'` (SDK 1.67, core 0.2.26) is the other accepted value, for a form an
     *   anonymous browser submits. Accepted for exactly one shape: method POST, path
     *   `/api/<your plugin's slug>/forms/<name>/{key}` (<name> as above), and no
     *   requiredPermission or requiredRole; anything else is NOT registered, with a
     *   logged reason. YOU DO NOT CHECK ANYTHING BUT THE FORM'S CONTENT: before your
     *   handler runs the host resolves the tenant from `{key}`, applies its per-IP
     *   limit, its ceiling per form and tenant, a body cap and JSON-only rule, the
     *   Origin check and the bot check, and strips cookies and Authorization. Read
     *   the tenant from {@see Http\PublicForm::from()}, never from the request. Your
     *   handler answers strangers, so it must return a 4xx on bad input rather than
     *   throw. A workspace admin issues the key. An older host doesn't register
     *   the route or keeps it private, so it is never public by accident: use
     *   {@see PluginRequirementsInterface::getCoreConstraint()} to refuse to load there.
     *
     *   `'read'` (SDK 1.69, WHIT-704) is the third, for something anybody holding a
     *   link may see. Accepted for exactly one shape: method GET, path
     *   `/api/<your plugin's slug>/public/<name>/{key}` (<name> as above, and not
     *   the name of one of your forms), and no requiredPermission or requiredRole;
     *   anything else is NOT registered, with a logged reason. The key is the same
     *   kind a form uses, issued by a workspace admin. Before your handler runs the
     *   host resolves the tenant from `{key}`, applies a per-IP limit and a ceiling
     *   per read and tenant, checks the Origin when the caller sends one, and strips
     *   cookies and Authorization. Read the tenant from
     *   {@see Http\PublicRead::from()}, never from the request. OFF BY DEFAULT: the
     *   route stays private (sign-in required, and then still refused) until the
     *   instance's operator sets `PLUGIN_PUBLIC_READS=on`. Return only what anybody
     *   with the link may see, and a 4xx on bad input rather than a throw.
     *
     * @return array<array{method: string, path: string, handler: callable, requiredRole?: ?string, requiredPermission?: ?string, schema?: array<string, mixed>}>
     */
    public function getRoutes(): array;

    /**
     * Get the permissions declared by the plugin
     *
     * Returns an array of permission strings in `resource:action` colon
     * notation (validated against /^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/).
     *
     * @return array<string>
     */
    public function getPermissions(): array;

    /**
     * Get the hook subscriptions defined by the plugin
     *
     * Returns an associative array mapping event names (see
     * {@see \Whity\Sdk\Hooks\Events}) to subscriptions. A subscription can be:
     * - A callable: function(array $data, array $context): array
     * - An array containing:
     *   - 'callback' (callable): The callback function
     *   - 'priority' (int, optional): Execution priority (default 10)
     *   - 'observe' (bool, optional, SDK 1.89): true makes the listener
     *     observe-only: its return value is ignored, it cannot change the
     *     payload and it cannot veto
     * - An array of callables or arrays as described above
     *
     * @return array<string, callable|array{callback: callable, priority?: int, observe?: bool}|array<callable|array{callback: callable, priority?: int, observe?: bool}>>
     */
    public function getHooks(): array;

    /**
     * Get the migrations defined by the plugin
     *
     * Returns an array of migration class names (FQCNs). Each class implements
     * {@see \Whity\Sdk\MigrationInterface}.
     *
     * @return array<string>
     */
    public function getMigrations(): array;
}
