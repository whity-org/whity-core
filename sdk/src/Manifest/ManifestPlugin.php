<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

use ReflectionClass;
use Whity\Sdk\Contribute\PluginContactFieldsInterface;
use Whity\Sdk\Contribute\PluginDashboardInterface;
use Whity\Sdk\Contribute\PluginJobsInterface;
use Whity\Sdk\Contribute\PluginNotificationsInterface;
use Whity\Sdk\Contribute\PluginOnboardingInterface;
use Whity\Sdk\Contribute\PluginScheduledJobsInterface;
use Whity\Sdk\Contribute\PluginSettingsInterface;
use Whity\Sdk\Contribute\PluginThemesInterface;
use Whity\Sdk\JobInterface;
use Whity\Sdk\DataType\PluginDataTypesInterface;
use Whity\Sdk\PluginAgentPresetsInterface;
use Whity\Sdk\PluginEntitlementsInterface;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginHomeCardInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;
use Whity\Sdk\PluginRolesInterface;
use Whity\Sdk\Tenant\PluginTablesInterface;

/**
 * A plugin declared in `plugin.json` (SDK 1.47, WHIT-758).
 *
 * Extend it with an empty class next to the manifest and the host loads the
 * plugin like any other:
 *
 * ```php
 * namespace CakeOrders;
 *
 * final class CakeOrdersPlugin extends \Whity\Sdk\Manifest\ManifestPlugin
 * {
 * }
 * ```
 *
 * Every capability is answered from the manifest in the shape the matching
 * interface already defines, so nothing in the host knows or cares that the
 * declarations came from JSON. That is what "one runtime" means: a builder-made
 * plugin, an exported one and a hand-written one go through the same loader,
 * the same registries and the same checks.
 *
 * HYBRID BY OVERRIDING. A subclass may override any method to add what JSON
 * can't express (routes and their handlers, hooks, migrations, job handlers
 * that need collaborators), and may
 * call the parent to keep the declared part:
 *
 * ```php
 * public function getPermissions(): array
 * {
 *     return [...parent::getPermissions(), 'cake_orders:export'];
 * }
 * ```
 *
 * AN INVALID MANIFEST REFUSES THE WHOLE PLUGIN. The constructor throws
 * {@see InvalidManifestException} with every error, and the host's loader
 * already treats a plugin that throws while being constructed as one that
 * doesn't load. Loading half a manifest would leave a role granting a
 * permission that was dropped, or a screen gated by one, which is worse than
 * not loading.
 */
abstract class ManifestPlugin implements
    PluginInterface,
    PluginRequirementsInterface,
    PluginRolesInterface,
    PluginFrontendInterface,
    PluginTablesInterface,
    PluginDataTypesInterface,
    PluginEntitlementsInterface,
    PluginAgentPresetsInterface,
    PluginHomeCardInterface,
    PluginSettingsInterface,
    PluginNotificationsInterface,
    PluginJobsInterface,
    PluginScheduledJobsInterface,
    PluginDashboardInterface,
    PluginOnboardingInterface,
    PluginContactFieldsInterface,
    PluginThemesInterface
{
    /** The file name the manifest is read from, next to the subclass. */
    public const FILE_NAME = 'plugin.json';

    private readonly PluginManifest $manifest;

    /** @var array<string, JobInterface>|null built on first use */
    private ?array $jobs = null;

    /**
     * @throws InvalidManifestException
     */
    public function __construct()
    {
        $this->manifest = PluginManifest::fromFile($this->manifestPath());
        if ($this->manifest->records() !== []) {
            // The record store serves workspace plugins (WHIT-761). An
            // install-wide plugin keeps its records in its own tables, declared
            // under `tables` and `dataTypes`; accepting `records` here and
            // serving nothing would be a silent no-op.
            throw new InvalidManifestException($this->manifestPath(), [
                'records: only a workspace plugin keeps records in the shared store; an installed plugin declares tables and dataTypes',
            ]);
        }
    }

    /**
     * Where the manifest lives: `plugin.json` in the subclass's own directory.
     * Override to read it from elsewhere.
     */
    protected function manifestPath(): string
    {
        $file = (new ReflectionClass($this))->getFileName();

        return dirname((string) $file) . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    /** The validated manifest, for subclasses that build on it. */
    final protected function manifest(): PluginManifest
    {
        return $this->manifest;
    }

    public function getName(): string
    {
        return $this->manifest->name();
    }

    public function getVersion(): string
    {
        return $this->manifest->version();
    }

    /** JSON declares no handlers; a subclass adds routes in PHP. */
    public function getRoutes(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return $this->manifest->permissions();
    }

    /** JSON declares no callbacks; a subclass adds hooks in PHP. */
    public function getHooks(): array
    {
        return [];
    }

    /** Tables declared under `tables` are created by migrations a subclass ships. */
    public function getMigrations(): array
    {
        return [];
    }

    public function getSdkConstraint(): string
    {
        return $this->manifest->sdkConstraint();
    }

    public function getCoreConstraint(): string
    {
        return $this->manifest->coreConstraint();
    }

    public function getPluginDependencies(): array
    {
        return $this->manifest->pluginDependencies();
    }

    public function getRoles(): array
    {
        return $this->manifest->roles();
    }

    public function getRolePermissions(): array
    {
        return $this->manifest->rolePermissions();
    }

    public function getFrontendFeatures(): array
    {
        return $this->manifest->frontendFeatures();
    }

    public function getOwnedTables(): array
    {
        return $this->manifest->ownedTables();
    }

    public function getDataTypes(): array
    {
        return $this->manifest->dataTypes();
    }

    public function getEntitlements(): array
    {
        return $this->manifest->entitlements();
    }

    /**
     * The manifest's `agentPresets`, in the interface's list shape (SDK 1.61).
     *
     * @return list<array<string, mixed>>
     */
    public function getAgentPresets(): array
    {
        $presets = [];
        foreach ($this->manifest->agentPresets() as $key => $preset) {
            $presets[] = ['key' => (string) $key] + $preset;
        }

        /** @var list<array<string, mixed>> */
        return $presets;
    }

    /**
     * The manifest's `homeCard` (SDK 1.62). JSON declares no routes, so its `source`
     * must be one a subclass adds in PHP; the host drops it otherwise.
     *
     * @return array<string, mixed>|null
     */
    public function getHomeCard(): ?array
    {
        return $this->manifest->homeCard();
    }

    /** `settings` from the manifest (SDK 1.163, D4). */
    public function getSettings(): array
    {
        return $this->manifest->settings();
    }

    /** `notifications` from the manifest (SDK 1.163, D4). */
    public function getNotificationTypes(): array
    {
        /** @var array<string, array{label: array{en: string, ar: string}, emailDefault?: bool}> */
        return $this->manifest->notificationTypes();
    }

    /**
     * The handlers `jobs` names, each built once with no constructor
     * arguments (SDK 1.163, D4). A subclass whose handlers need collaborators
     * overrides this and may call the parent for the rest.
     *
     * @return array<string, JobInterface>
     * @throws InvalidManifestException when a class is missing or isn't a JobInterface
     */
    public function getJobs(): array
    {
        if ($this->jobs !== null) {
            return $this->jobs;
        }
        $jobs = [];
        $problems = [];
        foreach ($this->manifest->jobs() as $name => $declaration) {
            $class = ltrim($declaration['class'], '\\');
            if (!class_exists($class) || !is_subclass_of($class, JobInterface::class)) {
                $problems[] = "jobs.{$name}.class: {$class} is not a class implementing " . JobInterface::class;
                continue;
            }
            $jobs[$name] = new $class();
        }
        if ($problems !== []) {
            throw new InvalidManifestException($this->manifestPath(), $problems);
        }

        return $this->jobs = $jobs;
    }

    /** The jobs whose declaration says `"submittable": true` (SDK 1.163, D4). */
    public function getSubmittableJobs(): array
    {
        $names = [];
        foreach ($this->manifest->jobs() as $name => $declaration) {
            if (($declaration['submittable'] ?? false) === true) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /** Each job with a `schedule`, bare name => cron (SDK 1.163, D4). */
    public function getScheduledJobs(): array
    {
        $schedules = [];
        foreach ($this->manifest->jobs() as $name => $declaration) {
            if (isset($declaration['schedule'])) {
                $schedules[(string) $name] = $declaration['schedule'];
            }
        }

        return $schedules;
    }

    /**
     * `dashboard` from the manifest, or nothing (SDK 1.163, D4). Its routes
     * are ones a subclass adds in PHP; the host drops what names none.
     */
    public function getDashboard(): array
    {
        return $this->manifest->dashboard() ?? [];
    }

    /**
     * `onboarding` from the manifest, or nothing (SDK 1.163, D4). Its `status`
     * is a GET route a subclass adds in PHP.
     */
    public function getOnboarding(): array
    {
        /** @phpstan-ignore return.type (the shape is checked by OnboardingValidator when the manifest loads) */
        return $this->manifest->onboarding() ?? [];
    }

    /** `contactFields` from the manifest (SDK 1.163, D4). */
    public function getContactFields(): array
    {
        /** @phpstan-ignore return.type (the shape is checked by ContactFieldValidator when the manifest loads) */
        return $this->manifest->contactFields();
    }

    /**
     * The theme files `themes` lists, read from the plugin's directory and
     * decoded (SDK 1.163, D4). The host checks each against
     * `schemas/theme/v1.json` and drops one that fails.
     *
     * @throws InvalidManifestException when a file can't be read or isn't JSON
     */
    public function getThemes(): array
    {
        $dir = dirname($this->manifestPath());
        $themes = [];
        $problems = [];
        foreach ($this->manifest->themeFiles() as $i => $file) {
            $raw = @file_get_contents($dir . DIRECTORY_SEPARATOR . $file);
            $theme = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($theme)) {
                $problems[] = "themes[{$i}]: {$file} can't be read as JSON";
                continue;
            }
            $themes[] = $theme;
        }
        if ($problems !== []) {
            throw new InvalidManifestException($this->manifestPath(), $problems);
        }

        /** @var list<array<string, mixed>> */
        return $themes;
    }
}
