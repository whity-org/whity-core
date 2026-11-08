<?php

declare(strict_types=1);

namespace Tests\Sdk;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Whity\Api\ResourceRoleGrantsApiHandler;
use Whity\Api\SsoAuthHandler;
use Whity\Core\DataType\DataTypeLifecycleService;
use Whity\Core\Signup\CustomerSignupService;
use Whity\Core\TimeWindow\WindowCloseReporter;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Testing\OfflinePluginHostConformanceTestCase;

/**
 * The published list of events has to match what core fires, and the documented
 * list of events a listener can change has to match what core reads back.
 */
final class HookEventCatalogueTest extends TestCase
{
    /** @return array<string, string> file => source */
    private static function coreSources(): array
    {
        $root = dirname(__DIR__, 2);
        $sources = [];
        foreach (['src', 'public'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $sources;
    }

    public function testEveryEventCoreFiresByNameIsPublished(): void
    {
        $published = array_values((new ReflectionClass(Events::class))->getConstants());

        $names = [];
        foreach (self::coreSources() as $source) {
            preg_match_all('/(?:hooks?|hookManager)\??->dispatch(?:Async)?\(\s*\'([^\']+)\'\s*,/i', $source, $matches);
            array_push($names, ...$matches[1]);
        }

        $this->assertNotEmpty($names, 'The scan found no dispatches, so it proves nothing.');
        $this->assertContains('user.membership.added', $names);
        $this->assertSame([], array_values(array_unique(array_diff($names, $published))));
    }

    public function testEventsCoreNamesThroughItsOwnConstantsArePublishedWithTheSameName(): void
    {
        $this->assertSame(Events::AUTH_FEDERATED_SIGNED_IN, SsoAuthHandler::SIGNED_IN_EVENT);
        $this->assertSame(Events::RBAC_RESOURCE_GRANT_VERIFY_RESOURCE, ResourceRoleGrantsApiHandler::VERIFY_RESOURCE_HOOK);
        $this->assertSame(Events::TIME_WINDOW_CLOSE_REPORT, WindowCloseReporter::HOOK);
        $this->assertSame(Events::DATATYPE_LIFECYCLE_CHANGING, DataTypeLifecycleService::HOOK_CHANGING);
        $this->assertSame(Events::DATATYPE_LIFECYCLE_CHANGED, DataTypeLifecycleService::HOOK_CHANGED);
        $this->assertSame(Events::MEMBERSHIP_CUSTOMER_JOINED, CustomerSignupService::HOOK_CUSTOMER_JOINED);
        $this->assertSame('registration.approved', Events::REGISTRATION_APPROVED);
        $this->assertSame('registration.rejected', Events::REGISTRATION_REJECTED);
    }

    /**
     * Mirrors "What a listener may change" in docs/wiki/Plugin-Development.md. If
     * this fails, a dispatch started or stopped using what listeners return:
     * update that table too.
     */
    public function testOnlyTheDocumentedEventsHaveTheirReturnValueRead(): void
    {
        $read = [];
        foreach (self::coreSources() as $source) {
            preg_match_all('/\$\w+\s*=\s*\$this->(?:hooks|hookManager)\??->dispatch\(\s*([^,\s]+)\s*,/', $source, $matches);
            array_push($read, ...$matches[1]);
        }
        sort($read);

        $this->assertSame([
            "'navigation.register'",
            "'ou.creating'",
            "'role.creating'",
            "'tenant.creating'",
            'Events::DOCUMENT_ISSUING',
            'self::HOOK',
            'self::VERIFY_RESOURCE_HOOK',
        ], $read);
    }

    public function testTheConformanceKitDoesNotRequireAnObserverToReturnAnArray(): void
    {
        $normalize = (new ReflectionClass(OfflinePluginHostConformanceTestCase::class))->getMethod('normalizeHooks');
        $observer = static function (): void {
        };
        $filter = static fn (array $d): array => $d;

        $this->assertSame([[$observer, true]], $normalize->invoke(null, ['callback' => $observer, 'observe' => true]));
        $this->assertSame([[$filter, false]], $normalize->invoke(null, ['callback' => $filter]));
        $this->assertSame([[$observer, true], [$filter, false]], $normalize->invoke(null, [['callback' => $observer, 'observe' => true], $filter]));
    }
}
