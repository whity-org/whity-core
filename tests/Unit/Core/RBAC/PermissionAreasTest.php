<?php

declare(strict_types=1);

namespace Tests\Unit\Core\RBAC;

use PHPUnit\Framework\TestCase;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionAreas;

final class PermissionAreasTest extends TestCase
{
    public function testEveryCorePermissionHasAnArea(): void
    {
        $unmapped = array_values(array_filter(
            CorePermissions::all(),
            static fn (string $slug): bool => !PermissionAreas::isMapped($slug)
        ));

        self::assertSame([], $unmapped, 'Add these to PermissionAreas::AREAS.');
    }

    public function testActionsComeFromTheSlug(): void
    {
        self::assertSame(['see'], PermissionAreas::of('users:read')['actions']);
        self::assertSame(['create', 'change'], PermissionAreas::of('users:write')['actions']);
        self::assertSame(['delete'], PermissionAreas::of('users:delete')['actions']);
        self::assertSame(['approve'], PermissionAreas::of('password_resets:approve')['actions']);
        self::assertSame([], PermissionAreas::of('roles:manage')['actions'], 'Off the grid, still listed under its area.');
        self::assertSame([], PermissionAreas::of('documents:read:all')['actions'], 'A wider kind of seeing, not See.');
    }

    public function testARelatedPermissionSharesItsArea(): void
    {
        self::assertSame('roles', PermissionAreas::of('permissions:read')['area']);
        self::assertSame('people', PermissionAreas::of('users:read')['areaGroup']);
        self::assertSame('documents', PermissionAreas::of('forms:read')['areaGroup']);
    }

    public function testAPluginPermissionLandsUnderApps(): void
    {
        $area = PermissionAreas::of('acme-stock:widgets:read');

        self::assertSame('apps', $area['areaGroup']);
        self::assertSame('Acme Stock', $area['areaLabel']);
        self::assertSame('acme-stock', $area['area']);
    }
}
