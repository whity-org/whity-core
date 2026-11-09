<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginNamespace;

/**
 * Who a plugin object is, as the loader saw it (WHIT-1030).
 *
 * A host service that hands a plugin something of its own (`->for($this)`) has
 * to know which plugin is asking. `getName()` cannot answer that: it is code the
 * plugin wrote, so a stub class can return another plugin's name, and even the
 * real plugin can return something different on a later call.
 *
 * So the loader binds each plugin OBJECT to the name it had when it was loaded,
 * and a service asks here. An object the loader never loaded has no identity and
 * is refused. The map holds no strong reference, so a plugin replaced by a
 * reload is simply forgotten.
 *
 * Two different plugin names can reduce to one slug (`acme-x`, `acme_x`). The
 * first to load keeps the slug and the second gets no identity, because a shared
 * slug would mean shared jobs and audit entries.
 *
 * Per-process, not per-request: which plugins this process loaded does not change
 * between requests. Plugins run in the host's process, so this is a boundary of
 * the API, not a sandbox.
 */
final class PluginIdentity
{
    /** @var \WeakMap<PluginInterface, string>|null plugin object => slug */
    private static ?\WeakMap $slugs = null;

    /** @var array<string, string> slug => the plugin name that holds it */
    private static array $holders = [];

    private function __construct()
    {
    }

    /** Called by the loader only, with the name the plugin was loaded under. */
    public static function bind(PluginInterface $plugin, string $name): void
    {
        $slug = PluginNamespace::slug($name);
        if ($slug === null || (self::$holders[$slug] ?? $name) !== $name) {
            return;
        }
        self::$holders[$slug] = $name;
        self::$slugs ??= new \WeakMap();
        self::$slugs[$plugin] = $slug;
    }

    /**
     * The slug this plugin was loaded under.
     *
     * @throws \InvalidArgumentException for an object the loader did not load
     */
    public static function slugOf(PluginInterface $plugin): string
    {
        $slug = self::$slugs[$plugin] ?? null;
        if ($slug === null) {
            throw new \InvalidArgumentException('Only a loaded plugin can use this service. Pass the plugin itself: ->for($this).');
        }

        return $slug;
    }
}
