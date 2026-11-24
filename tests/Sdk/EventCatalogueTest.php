<?php

declare(strict_types=1);

namespace Tests\Sdk;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Whity\Sdk\Hooks\EventCatalogue;
use Whity\Sdk\Hooks\Events;

require_once dirname(__DIR__, 2) . '/scripts/lib/hook-catalogue.php';

/**
 * Hooks\EventCatalogue (SDK 1.164): one entry per published event, consistent
 * with itself and with what core does, and the source of HOOK_SYSTEM.md's
 * event tables.
 */
final class EventCatalogueTest extends TestCase
{
    public function testEveryPublishedEventHasAnEntryAndNothingElseDoes(): void
    {
        $published = array_values(array_filter((new ReflectionClass(Events::class))->getConstants(), 'is_string'));
        sort($published);
        $catalogued = EventCatalogue::names();
        sort($catalogued);

        self::assertGreaterThan(200, count($catalogued), 'a floor, so an empty catalogue cannot pass');
        self::assertSame($published, $catalogued, 'Add an entry to EventCatalogue for each new Events constant.');
    }

    public function testEachEntryIsConsistent(): void
    {
        $problems = [];
        foreach (EventCatalogue::all() as $name => $info) {
            if (!in_array($info->kind, EventCatalogue::KINDS, true)) {
                $problems[] = "{$name}: unknown kind {$info->kind}";
            }
            if (($info->kind === EventCatalogue::QUEUED) !== str_ends_with($name, '.async')) {
                $problems[] = "{$name}: queued exactly when it is an .async twin";
            }
            if ($info->async !== null && EventCatalogue::get($info->async)?->asyncOf !== $name) {
                $problems[] = "{$name}: its async twin {$info->async} must be published and point back";
            }
            if ($info->asyncOf !== null && EventCatalogue::get($info->asyncOf)?->async !== $name) {
                $problems[] = "{$name}: {$info->asyncOf} must name it as its async twin";
            }
            if (preg_match('/^1\.\d+$/', $info->since) !== 1) {
                $problems[] = "{$name}: since must be an SDK minor such as 1.164";
            }
            if ($info->renamedFrom !== null && ($info->renamedFrom === $name || preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $info->renamedFrom) !== 1)) {
                $problems[] = "{$name}: renamedFrom must be the event's earlier name";
            }
            if ($info->group === '') {
                $problems[] = "{$name}: needs a group";
            }
            foreach ($info->payload as $key) {
                if (preg_match('/^[a-z_][a-z0-9_]*$/', $key) !== 1) {
                    $problems[] = "{$name}: payload key {$key}";
                }
            }
        }

        self::assertSame([], $problems);
    }

    /**
     * The filters are exactly the events whose return value core reads, the
     * list HookEventCatalogueTest pins from the dispatching code.
     */
    public function testTheFiltersAreTheEventsCoreReadsBack(): void
    {
        $filters = array_keys(array_filter(EventCatalogue::all(), static fn ($info): bool => $info->isFilter()));
        sort($filters);

        self::assertSame([
            'document.issuing',
            'membership.customer_inviting',
            'navigation.register',
            'ou.creating',
            'rbac.resource_grant.verify_resource',
            'role.creating',
            'tenant.creating',
            'time_window.close_report',
        ], $filters);
    }

    /**
     * Renames wait for 2.0 (owner, 24 Nov 2026), so no 1.x event carries an
     * earlier name. When 2.0 is decided and the first rename lands, this test
     * goes with it.
     */
    public function testNoEventIsRenamedIn1x(): void
    {
        self::assertStringStartsWith('1.', \Whity\Sdk\Sdk::VERSION);
        $renamed = array_keys(array_filter(EventCatalogue::all(), static fn ($info): bool => $info->renamedFrom !== null));

        self::assertSame([], $renamed);
    }

    public function testNoMinorIsNewerThanTheSdk(): void
    {
        $sdk = implode('.', array_slice(explode('.', \Whity\Sdk\Sdk::VERSION), 0, 2));
        foreach (EventCatalogue::all() as $name => $info) {
            self::assertTrue(version_compare($info->since, $sdk, '<='), "{$name} says since {$info->since}, after SDK {$sdk}");
        }
    }

    public function testTheHookSystemPageIsGeneratedFromTheCatalogue(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/wiki/HOOK_SYSTEM.md');

        self::assertSame(
            whity_hook_catalogue_apply($page),
            str_replace("\r\n", "\n", $page),
            'docs/wiki/HOOK_SYSTEM.md is stale. Run: php scripts/hook-catalogue.php',
        );
    }
}
