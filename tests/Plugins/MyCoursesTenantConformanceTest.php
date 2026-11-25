<?php

declare(strict_types=1);

namespace Tests\Plugins;

use MyCourses\Migrations\CreateMyCoursesEnrolmentsTable;
use Whity\Core\Tenant\CoreTenantTableRegistry;
use Whity\Sdk\MigrationInterface;
use Whity\Sdk\Tenant\TenantTableRegistry;
use Whity\Sdk\Testing\TenantIsolationConformanceTestCase;

require_once dirname(__DIR__, 2) . '/plugins/MyCourses/Migrations/CreateMyCoursesEnrolmentsTable.php';

/**
 * Proves the MyCourses reference plugin (WHIT-1426, design section 41 CA3)
 * passes the SDK tenant-isolation kit, like every reference plugin.
 */
final class MyCoursesTenantConformanceTest extends TenantIsolationConformanceTestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../plugins/MyCourses';

    protected function tenantTableRegistry(): TenantTableRegistry
    {
        return TenantTableRegistry::for([
            'mycourses_enrolments' => 'A contact on a course; carries tenant_id (CreateMyCoursesEnrolmentsTable, WHIT-1426).',
        ])->merge(CoreTenantTableRegistry::build());
    }

    protected function migrationsDirectory(): string
    {
        return self::PLUGIN_DIR . '/Migrations';
    }

    /** @return list<string> */
    protected function handlerSourceDirectories(): array
    {
        return [self::PLUGIN_DIR . '/Api'];
    }

    /** @return list<MigrationInterface> */
    protected function schemaMigrations(): array
    {
        return [new CreateMyCoursesEnrolmentsTable()];
    }

    /** @return list<string> */
    protected function ownTenantTables(): array
    {
        return ['mycourses_enrolments'];
    }
}
