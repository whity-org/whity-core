<?php

declare(strict_types=1);

namespace Tests\Plugins;

use Glossary\Migrations\CreateGlossaryTermsTable;
use Whity\Core\Tenant\CoreTenantTableRegistry;
use Whity\Sdk\MigrationInterface;
use Whity\Sdk\Tenant\TenantTableRegistry;
use Whity\Sdk\Testing\TenantIsolationConformanceTestCase;

require_once dirname(__DIR__, 2) . '/plugins/Glossary/Migrations/CreateGlossaryTermsTable.php';

/**
 * Proves the Glossary reference plugin passes the SDK tenant-isolation kit.
 */
final class GlossaryTenantConformanceTest extends TenantIsolationConformanceTestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../plugins/Glossary';

    protected function tenantTableRegistry(): TenantTableRegistry
    {
        return TenantTableRegistry::for([
            'glossary_terms' => 'Glossary terms; carries tenant_id (CreateGlossaryTermsTable).',
        ])->merge(CoreTenantTableRegistry::build());
    }

    protected function migrationsDirectory(): string
    {
        return self::PLUGIN_DIR . '/Migrations';
    }

    /**
     * @return list<string>
     */
    protected function handlerSourceDirectories(): array
    {
        return [self::PLUGIN_DIR . '/Api'];
    }

    /**
     * @return list<MigrationInterface>
     */
    protected function schemaMigrations(): array
    {
        return [new CreateGlossaryTermsTable()];
    }

    /**
     * @return list<string>
     */
    protected function ownTenantTables(): array
    {
        return ['glossary_terms'];
    }
}
