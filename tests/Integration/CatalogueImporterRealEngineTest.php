<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Plan\CatalogueImporter;
use Whity\Core\Plan\PlanPriceRepository;
use Whity\Core\Plan\PlanRepository;

/**
 * The catalogue import only ever adds. Each test here is a way it could
 * otherwise reprice something an operator has already decided.
 */
final class CatalogueImporterRealEngineTest extends TestCase
{
    private PDO $pdo;
    private CatalogueImporter $importer;
    private PlanRepository $plans;
    private PlanPriceRepository $prices;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->importer = CatalogueImporter::forDatabase($this->pdo);
        $this->plans = new PlanRepository($this->pdo);
        $this->prices = new PlanPriceRepository($this->pdo);
    }

    public function testAFreshCatalogueIsWrittenInFull(): void
    {
        $report = $this->importer->apply($this->catalogue());

        $plan = $this->plans->findByKey('sample');
        self::assertNotNull($plan);
        self::assertSame('Sample', $plan['name']);
        self::assertSame(20, $plan['sort_order']);

        $price = $this->prices->findActive($plan['id'], 'JOD', 'month');
        self::assertSame(5000, $price['unit_amount'] ?? null);
        self::assertSame(
            ['mcp.access' => 'true', 'members.max' => '7'],
            $this->sortedBundle($plan['id'])
        );
        self::assertContains('plan sample', $report['added']);
        self::assertSame([], $report['skipped']);
    }

    public function testAnAddOnIsMarkedAsOne(): void
    {
        $catalogue = $this->catalogue();
        $catalogue['plans'][0]['is_addon'] = true;

        $this->importer->apply($catalogue);

        $flag = $this->pdo->query("SELECT is_addon FROM plans WHERE plan_key = 'sample'")->fetchColumn();
        self::assertTrue(\Whity\Core\Db\DbBool::of($flag));
    }

    public function testRunningItAgainChangesNothingAndSaysSo(): void
    {
        $this->importer->apply($this->catalogue());
        $before = $this->snapshot();

        $report = $this->importer->apply($this->catalogue());

        self::assertSame($before, $this->snapshot());
        self::assertSame([], $report['added']);
        self::assertCount(4, $report['skipped']);
    }

    public function testAnEditedPriceSurvivesARerun(): void
    {
        $this->importer->apply($this->catalogue());
        $this->pdo->exec('UPDATE plan_prices SET unit_amount = 9999');

        $this->importer->apply($this->catalogue());

        $plan = $this->plans->findByKey('sample');
        self::assertSame(9999, $this->prices->findActive($plan['id'], 'JOD', 'month')['unit_amount'] ?? null);
    }

    /** Retiring is how a price is withdrawn, so a rerun must not bring it back. */
    public function testARetiredPriceIsNotBroughtBack(): void
    {
        $this->importer->apply($this->catalogue());
        $plan = $this->plans->findByKey('sample');
        $this->prices->deactivate($this->prices->findActive($plan['id'], 'JOD', 'month')['id']);

        $this->importer->apply($this->catalogue());

        self::assertNull($this->prices->findActive($plan['id'], 'JOD', 'month'));
        self::assertCount(1, $this->prices->listForPlan($plan['id']));
    }

    public function testAnEditedPlanAndLimitSurviveARerun(): void
    {
        $this->importer->apply($this->catalogue());
        $plan = $this->plans->findByKey('sample');
        $this->plans->updatePlan($plan['id'], ['name' => 'Renamed']);
        $this->plans->setEntitlement($plan['id'], 'members.max', '99');

        $this->importer->apply($this->catalogue());

        self::assertSame('Renamed', $this->plans->findByKey('sample')['name']);
        self::assertSame('99', $this->plans->getEntitlements($plan['id'])['members.max']);
    }

    public function testAMissingPriceOrLimitIsAddedToAnExistingPlan(): void
    {
        $id = $this->plans->createPlan('sample', 'Sample', null, true, 0);

        $report = $this->importer->apply($this->catalogue());

        self::assertSame('Sample', $this->plans->findByKey('sample')['name']);
        self::assertNotNull($this->prices->findActive($id, 'JOD', 'month'));
        self::assertContains('plan sample: already exists', $report['skipped']);
    }

    public function testALimitThatWouldTightenALiveTierIsSkippedAndReported(): void
    {
        $id = $this->plans->createPlan('sample', 'Sample', null, true, 0);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (5, 'five', 'five')");
        $this->plans->setTenantPlan(5, $id, null);

        $report = $this->importer->apply($this->catalogue());

        self::assertArrayNotHasKey('members.max', $this->plans->getEntitlements($id));
        $lines = array_filter($report['skipped'], static fn (string $l): bool => str_starts_with($l, 'limit sample members.max'));
        self::assertCount(1, $lines);
        self::assertStringContainsString('1 workspace(s)', array_values($lines)[0]);
    }

    /**
     * @dataProvider badFiles
     * @param callable(array<string, mixed>): array<string, mixed> $spoil
     */
    public function testABadFileWritesNothing(callable $spoil, string $expected): void
    {
        // The good plan comes first, so a check made while writing would have
        // written it already.
        $catalogue = $this->catalogue();
        $catalogue['plans'][] = $spoil($this->catalogue()['plans'][0]);

        $problems = CatalogueImporter::problems($catalogue);
        self::assertNotSame([], $problems);
        self::assertStringContainsString($expected, implode("\n", $problems));

        try {
            $this->importer->apply($catalogue);
            self::fail('A bad file must be refused.');
        } catch (\Whity\Core\Plan\PlanValidationException) {
        }

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM plan_prices')->fetchColumn());
    }

    /** @return array<string, array{0: callable, 1: string}> */
    public static function badFiles(): array
    {
        $with = static fn (array $changes): callable => static fn (array $plan): array => array_merge(['plan_key' => 'second'], $changes) + $plan;

        return [
            'unknown entitlement' => [$with(['limits' => ['customers.per_month' => 500]]), 'unknown entitlement key "customers.per_month"'],
            'wrong limit type' => [$with(['limits' => ['mcp.access' => 5]]), 'mcp.access must be a boolean'],
            'limit as text' => [$with(['limits' => ['members.max' => '5']]), 'must be a number or true/false'],
            'bad currency' => [$with(['prices' => [['currency' => 'dinars', 'amount' => 1, 'period' => 'month']]]), 'three-letter'],
            'fractional amount' => [$with(['prices' => [['currency' => 'JOD', 'amount' => 1.5, 'period' => 'month']]]), 'whole number of minor units'],
            'negative amount' => [$with(['prices' => [['currency' => 'JOD', 'amount' => -1, 'period' => 'month']]]), 'zero or a positive'],
            'bad period' => [$with(['prices' => [['currency' => 'JOD', 'amount' => 1, 'period' => 'weekly']]]), 'must be one of'],
            'repeated terms' => [$with(['prices' => [
                ['currency' => 'JOD', 'amount' => 1, 'period' => 'month'],
                ['currency' => 'jod', 'amount' => 2, 'period' => 'month'],
            ]]), 'repeats the terms'],
            'mistyped field' => [$with(['sortorder' => 1]), 'unknown field "sortorder"'],
            'duplicate key' => [$with(['plan_key' => 'SAMPLE']), 'appears more than once'],
            'bad key' => [$with(['plan_key' => 'no spaces']), 'plan_key must be lowercase'],
            'no name' => [$with(['name' => '  ']), 'needs a name'],
        ];
    }

    public function testAFileWithNoPlansIsRefused(): void
    {
        self::assertNotSame([], CatalogueImporter::problems(['plans' => []]));
        self::assertNotSame([], CatalogueImporter::problems('nonsense'));
    }

    public function testTheShippedExampleIsValid(): void
    {
        $raw = (string) file_get_contents(__DIR__ . '/../../ops/billing-catalogue.example.json');

        self::assertSame([], CatalogueImporter::problems(json_decode($raw, true, 32, JSON_THROW_ON_ERROR)));
    }

    /** @return array{plans: list<array<string, mixed>>} */
    private function catalogue(): array
    {
        return ['plans' => [[
            'plan_key' => 'sample',
            'name' => 'Sample',
            'description' => 'An invented plan.',
            'sort_order' => 20,
            'prices' => [['currency' => 'JOD', 'amount' => 5000, 'period' => 'month', 'per_seat' => false]],
            'limits' => ['members.max' => 7, 'mcp.access' => true],
        ]]];
    }

    /** @return array<string, string> */
    private function sortedBundle(int $planId): array
    {
        $bundle = $this->plans->getEntitlements($planId);
        ksort($bundle);

        return $bundle;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        $out = [];
        foreach (['plans', 'plan_prices', 'plan_entitlements'] as $table) {
            $out[$table] = $this->pdo->query("SELECT * FROM {$table} ORDER BY 1, 2")->fetchAll(PDO::FETCH_ASSOC);
        }

        return $out;
    }
}
