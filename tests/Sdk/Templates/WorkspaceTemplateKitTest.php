<?php

declare(strict_types=1);

namespace Tests\Sdk\Templates;

use Tests\Support\ExampleBakeryTemplate;
use Whity\Core\Money\Currency;
use Whity\Sdk\Templates\DryRunWorkspaceSetup;
use Whity\Sdk\Templates\MemoryLedger;
use Whity\Sdk\Templates\TemplateEngine;
use Whity\Sdk\Templates\TemplateMoney;
use Whity\Sdk\Testing\FakeWorkspace;
use Whity\Sdk\Testing\WorkspaceTemplateTestCase;

/**
 * WHIT-1409 (SDK 1.169): the template engine in the SDK, its dry run, and the
 * kit template authors test with, used here as an author would.
 */
final class WorkspaceTemplateKitTest extends WorkspaceTemplateTestCase
{
    public function testAnAuthorsTemplatePassesTheKit(): void
    {
        $bakery = ExampleBakeryTemplate::manifest();

        $this->assertTemplateValid($bakery);
        $this->assertIdempotent($bakery);
        $this->assertUpgradeKeepsData($bakery, self::nextVersion($bakery));
    }

    public function testApplyingAddsKeepsAndReportsEachItem(): void
    {
        $workspace = $this->workspace();
        $workspace->call('POST', '/api/calendar/services', ['name' => ['en' => 'cake ORDER', 'ar' => 'x'], 'duration_minutes' => 15]);

        $result = $this->applyTemplate(ExampleBakeryTemplate::manifest(), $workspace);

        $actions = self::actions($result['items']);
        self::assertSame(TemplateEngine::KEEP, $actions['services:cake-order'], 'Same name in any case is the same service');
        self::assertSame(TemplateEngine::LEFT, $actions['services:tasting'], 'Not ticked: left for the guided setup');
        self::assertSame(TemplateEngine::KEEP, $actions['settings:billing.default_currency'], 'Chosen at creation, never overwritten');
        self::assertSame(TemplateEngine::ADD, $actions['settings:timezone']);
        self::assertSame(TemplateEngine::ADD, $actions['roles:counter']);
        self::assertSame(TemplateEngine::ADD, $actions['forms:cake-order']);
        self::assertSame(TemplateEngine::ADD, $actions['examples:first'], 'The example booking uses the service already there');
        self::assertCount(1, $workspace->services, 'Nothing made twice');
        self::assertSame('USD', $workspace->settings['billing.default_currency']);
        self::assertSame('Asia/Amman', $workspace->settings['timezone']);
    }

    public function testWhatThePersonDeletedIsNotMadeAgain(): void
    {
        $bakery = ExampleBakeryTemplate::manifest();
        $workspace = $this->workspace();
        $ledger = new MemoryLedger();
        $this->applyTemplate($bakery, $workspace, $ledger);
        $workspace->services = [];

        $again = $this->applyTemplate($bakery, $workspace, $ledger);

        self::assertSame([], $workspace->services, 'The ledger remembers it was made, so it stays deleted');
        self::assertSame(TemplateEngine::DONE, self::actions($again['items'])['services:cake-order']);

        // Without the ledger it would come back: the check above can fail.
        $this->applyTemplate($bakery, $workspace, new MemoryLedger());
        self::assertCount(1, $workspace->services);
    }

    public function testARoleGetsOnlyThePermissionsThePersonHolds(): void
    {
        $workspace = new FakeWorkspace(permissions: ['calendar:write', 'contacts:write']);

        $result = $this->applyTemplate(ExampleBakeryTemplate::manifest(), $workspace);

        self::assertSame(['calendar:write', 'contacts:write'], array_values($workspace->roles)[0]['permissions']);
        self::assertContains('Some permissions were left out: you do not hold them yourself.', array_column($result['skipped'], 'reason'));
    }

    public function testTheDryRunPlansTheSameAndChangesNothing(): void
    {
        $bakery = ExampleBakeryTemplate::manifest();
        $real = $this->workspace();
        $planned = $this->workspace();
        $before = $planned->snapshot();

        $dryRun = new DryRunWorkspaceSetup($planned);
        $plan = (new TemplateEngine($dryRun, new MemoryLedger(), static fn (): bool => true, fn (): \DateTimeImmutable => $this->now()))
            ->apply(self::WORKSPACE, self::PROFILE, $bakery, true);
        $applied = $this->applyTemplate($bakery, $real);

        self::assertEquals($before, $planned->snapshot(), 'A dry run changes nothing');
        self::assertSame(self::actions($applied['items']), self::actions($plan['items']), 'The plan is what applying does');
        self::assertSame($applied['made'], $plan['made']);
        self::assertNotSame([], $dryRun->writes());
        self::assertSame('Asia/Amman', self::timezoneSeenByTheBooking($dryRun), 'Settings it would change read back changed');
    }

    public function testTheDryRunSaysWhatThePersonMayNotDo(): void
    {
        $dryRun = new DryRunWorkspaceSetup($this->workspace(), static fn (string $method, string $path): bool => $path !== '/api/roles');

        $plan = (new TemplateEngine($dryRun, new MemoryLedger(), static fn (): bool => true))
            ->apply(self::WORKSPACE, self::PROFILE, ExampleBakeryTemplate::manifest(), false);

        self::assertSame(TemplateEngine::SKIP, self::actions($plan['items'])['roles:counter']);
    }

    public function testTheDryRunOverARealLedgerSavesNothingToIt(): void
    {
        $bakery = ExampleBakeryTemplate::manifest();
        $workspace = $this->workspace();
        $ledger = new MemoryLedger();
        $first = $this->applyTemplate($bakery, $workspace, $ledger);
        $upgrade = self::nextVersion($bakery);

        $plan = (new TemplateEngine(new DryRunWorkspaceSetup($workspace), new MemoryLedger($ledger), static fn (): bool => true))
            ->apply(self::WORKSPACE, self::PROFILE, $upgrade, false);

        $actions = self::actions($plan['items']);
        self::assertSame(TemplateEngine::ADD, $actions['services:croissant'], 'Only what is new in the upgrade is added');
        self::assertSame(TemplateEngine::DONE, $actions['services:cake-order']);
        self::assertSame($first['run'], $plan['run'], 'It plans on the workspace\'s own run');
        self::assertSame(['template:bakery' => 2], $ledger->parts(self::WORKSPACE, $first['run']), 'The real ledger still says version 2');
    }

    public function testPricesUseTheCurrencysOwnDecimalsLikeCore(): void
    {
        self::assertSame(20000, TemplateMoney::toMinor('20.000', 'JOD'));
        self::assertSame(2800, TemplateMoney::toMinor('28.00', 'USD'));
        self::assertSame(2000, TemplateMoney::toMinor('20.000', 'USD'), 'Trailing zeros past the decimals are dropped');
        self::assertSame(1500, TemplateMoney::toMinor('1500', 'JPY'));
        self::assertNull(TemplateMoney::toMinor('20.125', 'USD'), 'Never rounds');
        self::assertNull(TemplateMoney::toMinor('1,000', 'USD'));
        foreach (['USD', 'EUR', 'SAR', 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND', 'JPY', 'KRW', 'VND', 'XOF', 'CLF', 'UYW', 'ISK'] as $code) {
            self::assertSame(Currency::exponentFor($code), TemplateMoney::exponent($code), "{$code}: the SDK and core read its decimals the same");
        }
    }

    /**
     * @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    private static function nextVersion(array $template): array
    {
        $template['version'] = (int) $template['version'] + 1;
        $template['services'][] = ['key' => 'croissant', 'name' => ['en' => 'Croissant box', 'ar' => 'علبة كرواسون'], 'durationMinutes' => 10, 'prices' => ['USD' => '9.00', 'JOD' => '6.000']];

        return $template;
    }

    /**
     * @param list<array{part: string, key: string, action: string}> $items
     * @return array<string, string>
     */
    private static function actions(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[$item['part'] . ':' . $item['key']] = $item['action'];
        }

        return $out;
    }

    private static function timezoneSeenByTheBooking(DryRunWorkspaceSetup $dryRun): string
    {
        foreach ($dryRun->writes() as $write) {
            if ($write['path'] === '/api/calendar/bookings') {
                return (new \DateTimeImmutable((string) $write['body']['starts_at']))->getTimezone()->getName() === '+03:00' ? 'Asia/Amman' : 'other';
            }
        }

        return 'none';
    }
}
