<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Psr\Log\LoggerInterface;
use Whity\Core\Tenant\CoreTenantTableRegistry;
use Whity\Sdk\Context\Service;
use Whity\Sdk\Contribute\PluginCapabilitiesInterface;
use Whity\Sdk\PluginInterface;

/**
 * What each loaded plugin declared it uses (SDK 1.151, #1693): its services and
 * its own workspace tables.
 *
 * Filled by the plugin loader in discovery order, so "first to declare a table
 * owns it" is the same in every process. Read by {@see HostPluginScope}, which
 * gives a declaring plugin only what it declared, and by the plugin list API,
 * which shows the operator.
 *
 * PER PROCESS, like the loader's other registries: every worker loads plugins at
 * boot and fills its own copy.
 */
final class PluginCapabilities
{
    private const TABLE_NAME = '/^[a-z_][a-z0-9_]{0,62}$/';

    /** @var array<string, array{services: list<string>, tables: list<string>}> plugin name => declaration */
    private static array $declared = [];

    /** @var array<string, string> table => plugin name */
    private static array $tableOwners = [];

    private function __construct()
    {
    }

    /**
     * Record a plugin's declaration, dropping what it may not have. Returns the
     * reasons for anything dropped, also logged.
     *
     * @return list<string>
     */
    public static function register(PluginInterface $plugin, ?LoggerInterface $logger = null): array
    {
        $name = $plugin->getName();
        if (!$plugin instanceof PluginCapabilitiesInterface) {
            unset(self::$declared[$name]);

            return [];
        }

        $dropped = [];
        try {
            $rawServices = $plugin->getServices();
            $rawTables = $plugin->getTables();
        } catch (\Throwable $e) {
            // A declaration that can't be read grants nothing, rather than everything.
            $rawServices = [];
            $rawTables = [];
            $dropped[] = 'getServices()/getTables() threw ' . $e::class . '; nothing is granted';
        }

        $services = [];
        foreach ($rawServices as $service) {
            if (in_array($service, Service::all(), true)) {
                $services[$service] = true;
            } else {
                $dropped[] = 'unknown service ' . self::describe($service);
            }
        }

        $core = CoreTenantTableRegistry::build();
        $coreCreated = self::coreCreatedTables();
        $tables = [];
        foreach ($rawTables as $raw) {
            $table = self::plainName($raw);
            if ($table === null) {
                $dropped[] = 'table name ' . self::describe($raw) . ' is not plain';
                continue;
            }
            if ($core->isTenantOwned($table) || $core->isGlobal($table) || isset($coreCreated[$table])) {
                $dropped[] = "table '{$table}' is core's";
                continue;
            }
            $owner = self::$tableOwners[$table] ?? null;
            if ($owner !== null && $owner !== $name) {
                $dropped[] = "table '{$table}' was declared first by {$owner}";
                continue;
            }
            self::$tableOwners[$table] = $name;
            $tables[$table] = true;
        }

        self::$declared[$name] = ['services' => array_keys($services), 'tables' => array_keys($tables)];

        foreach ($dropped as $reason) {
            $logger?->warning("Plugin '{$name}' capabilities: {$reason}", [
                'event' => 'plugin.capabilities.dropped',
                'plugin' => $name,
            ]);
        }

        return $dropped;
    }

    /**
     * The plugin's declaration, or null when it declares nothing (and so keeps
     * every service until 2.0).
     *
     * @return array{services: list<string>, tables: list<string>}|null
     */
    public static function of(string $pluginName): ?array
    {
        return self::$declared[$pluginName] ?? null;
    }

    /**
     * Read a plugin's declaration straight from it when the loader hasn't
     * recorded one (a scope built in a test, or before load).
     *
     * @return array{services: list<string>, tables: list<string>}|null
     */
    public static function forPlugin(PluginInterface $plugin): ?array
    {
        $known = self::of($plugin->getName());
        if ($known !== null || !$plugin instanceof PluginCapabilitiesInterface) {
            return $known;
        }
        self::register($plugin);

        return self::of($plugin->getName());
    }

    /** Forget a plugin (disabled, reloaded or uninstalled), freeing its tables. */
    public static function forget(string $pluginName): void
    {
        unset(self::$declared[$pluginName]);
        self::$tableOwners = array_filter(
            self::$tableOwners,
            static fn (string $owner): bool => $owner !== $pluginName
        );
    }

    /** Plugin values are untrusted whatever their PHPDoc says, so these take mixed. */
    private static function plainName(mixed $name): ?string
    {
        return is_string($name) && preg_match(self::TABLE_NAME, $name) === 1 ? $name : null;
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? "'{$value}'" : get_debug_type($value);
    }

    /** @var array<string, true>|null Every table core's own migrations create; read once. */
    private static ?array $coreCreated = null;

    /**
     * Every table a core migration or the baseline schema creates. The core
     * registry lists only tenant-owned and sanctioned-global tables, which
     * leaves out ones like `tenants`; a plugin may declare none of them.
     *
     * @return array<string, true>
     */
    private static function coreCreatedTables(): array
    {
        if (self::$coreCreated !== null) {
            return self::$coreCreated;
        }
        $root = dirname(__DIR__, 3) . '/database';
        $files = array_merge(
            glob($root . '/migrations/*.php') ?: [],
            glob($root . '/baseline/*') ?: []
        );
        $tables = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            preg_match_all(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?"?([a-z_][a-z0-9_]*)"?/i',
                (string) file_get_contents($file),
                $m
            );
            foreach ($m[1] as $table) {
                $tables[strtolower($table)] = true;
            }
        }

        return self::$coreCreated = $tables;
    }

    /** Tests only: start from nothing. */
    public static function reset(): void
    {
        self::$declared = [];
        self::$tableOwners = [];
    }
}
