<?php

declare(strict_types=1);

namespace Whity\Sdk\Context;

/**
 * The names of the services on {@see PluginScope} a plugin declares it uses
 * (SDK 1.151, #1693), with
 * {@see \Whity\Sdk\Contribute\PluginCapabilitiesInterface::getServices()}.
 * Each name is the PluginScope method that returns the service.
 */
final class Service
{
    public const SETTINGS = 'settings';
    public const SECRETS = 'secrets';
    public const FILES = 'files';
    public const DOCUMENTS = 'documents';
    public const NOTIFY = 'notify';
    public const JOBS = 'jobs';
    public const AUDIT = 'audit';
    public const HTTP = 'http';
    public const THEMES = 'themes';
    public const INVENTORY = 'inventory';
    /** Core's books, {@see PluginScope::accounting()} (SDK 1.154, WHIT-1318). */
    public const ACCOUNTING = 'accounting';
    public const DATABASE = 'database';

    private function __construct()
    {
    }

    /** @return list<string> Every name, in PluginScope order. */
    public static function all(): array
    {
        return [
            self::SETTINGS, self::SECRETS, self::FILES, self::DOCUMENTS, self::NOTIFY, self::JOBS,
            self::AUDIT, self::HTTP, self::THEMES, self::INVENTORY, self::ACCOUNTING, self::DATABASE,
        ];
    }
}
