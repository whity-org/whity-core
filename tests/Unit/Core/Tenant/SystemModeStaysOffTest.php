<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Tenant;

use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\TenantContext;

/**
 * Nothing that ships turns system mode on (#1144, Phase 3 and 4).
 *
 * {@see TenantContext::setSystemMode()} is the one switch that tells code to
 * skip tenant scoping. It was built for migrations and operator tooling, and
 * today nothing in src/, plugins/ or sdk/ calls it with `true`: every
 * cross-tenant read is an explicit system-tenant (id 0) branch instead, which
 * the tenant guard can see. That is the better shape, and this keeps it. A new
 * caller would be a process-wide bypass that no per-query check notices; if
 * one is ever needed, it belongs here with its reason. The kernel turning it
 * off again after every request is pinned by
 * {@see \Tests\Security\BootScopedStaticSurvivalTest}, and the route probe
 * ({@see \Tests\Integration\CrossTenantRouteProbeRealBootTest}) fails if any
 * handler runs with it on.
 */
final class SystemModeStaysOffTest extends TestCase
{
    public function testNoShippedCodeTurnsSystemModeOn(): void
    {
        $root = dirname(__DIR__, 4);
        $callers = [];
        foreach (['src', 'plugins', 'sdk/src'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = (string) $file;
                if (str_ends_with($path, '.php') && preg_match('/setSystemMode\(\s*true/', (string) file_get_contents($path)) === 1) {
                    $callers[] = $path;
                }
            }
        }

        self::assertSame([], $callers, 'Code that turns tenant scoping off for the whole process.');
    }
}
