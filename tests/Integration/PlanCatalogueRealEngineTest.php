<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Plan\HostPlanCatalogue;

/** A billing plugin prices plans exactly as the host does, read-only (WHIT-1011). */
final class PlanCatalogueRealEngineTest extends TestCase
{
    private HostPlanCatalogue $plans;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make();
        $pdo->exec("INSERT INTO plans (id, plan_key, name, is_active, is_addon, created_at, updated_at) VALUES
            (901, 'pro', 'Pro', true, false, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (902, 'whity-builder.pro', 'Builder Pro', true, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (903, 'old', 'Old', false, false, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO plan_prices (id, plan_id, currency, unit_amount, billing_period, is_per_seat, is_active, created_at, updated_at) VALUES
            (9001, 901, 'JOD', 49000, 'month', false, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (9002, 901, 'JOD', 39000, 'month', false, false, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (9003, 902, 'USD', 1900, 'year', true, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (9004, 903, 'JOD', 1000, 'month', false, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->plans = new HostPlanCatalogue($pdo);
    }

    public function testTheActivePriceOnTheseTerms(): void
    {
        $terms = $this->plans->activePrice('pro', 'month', 'jod');

        self::assertNotNull($terms);
        self::assertSame(9001, $terms->priceId);
        self::assertSame(49000, $terms->unitAmountMinor);
        self::assertSame('Pro', $terms->planName);
    }

    public function testNothingIsSoldOnOtherTermsOrFromARetiredPlan(): void
    {
        self::assertNull($this->plans->activePrice('pro', 'year', 'JOD'));
        self::assertNull($this->plans->activePrice('pro', 'month', 'USD'));
        self::assertNull($this->plans->activePrice('old', 'month', 'JOD'));
        self::assertNull($this->plans->activePrice('nope', 'month', 'JOD'));
    }

    public function testAPriceReadsBackByIdEvenWhenRetired(): void
    {
        $terms = $this->plans->price(9002);

        self::assertNotNull($terms);
        self::assertSame(39000, $terms->unitAmountMinor);
        self::assertTrue($this->plans->price(9003)?->isPerSeat);
        self::assertNull($this->plans->price(1));
    }

    public function testAddons(): void
    {
        self::assertTrue($this->plans->isAddon('whity-builder.pro'));
        self::assertFalse($this->plans->isAddon('pro'));
        self::assertFalse($this->plans->isAddon('nope'));
    }
}
