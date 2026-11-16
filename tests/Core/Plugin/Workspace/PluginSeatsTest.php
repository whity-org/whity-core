<?php

declare(strict_types=1);

namespace Tests\Core\Plugin\Workspace;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Billing\External\BillingActor;
use Whity\Core\Billing\External\BillingPortal;
use Whity\Core\Billing\External\SubscriptionLine;
use Whity\Core\Plugin\Workspace\PluginSeats;

/**
 * WHIT-1152 (SDK 1.110): a plugin sold per seat is bought for the count the
 * workspace set, and when that count changes the subscription follows.
 */
final class PluginSeatsTest extends TestCase
{
    private const ACCESS = [
        'Salons' => [
            'entitlement' => 'salons.access',
            'product' => 'plugin.salons',
            'trialDays' => 14,
            'seats' => ['setting' => 'chairs', 'unit' => ['en' => 'chair', 'ar' => 'كرسي'], 'units' => ['en' => 'chairs', 'ar' => 'كراسي']],
        ],
        'FrontOffice' => ['entitlement' => 'front_office.access', 'product' => 'plugin.front-office', 'trialDays' => 14],
    ];

    /** @var array<string, mixed> */
    private array $settings = ['salons:chairs' => '3'];

    public function testTheCountComesFromThePluginsOwnSetting(): void
    {
        $seats = $this->seats();

        $this->assertSame(['count' => 3, 'unit' => ['en' => 'chair', 'ar' => 'كرسي'], 'units' => ['en' => 'chairs', 'ar' => 'كراسي']], $seats->of('Salons', 5));
        $this->assertSame(3, $seats->quantityForPlan('plugin.salons.half_year', 5));
        $this->assertNull($seats->of('FrontOffice', 5), 'Not sold per seat');
        $this->assertNull($seats->quantityForPlan('plugin.front-office.monthly', 5));

        $this->settings = [];
        $this->assertSame(1, $seats->quantityForPlan('plugin.salons.monthly', 5), 'One until the workspace says otherwise');
    }

    public function testChangingTheCountChangesTheLiveSubscriptionsQuantity(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO plans (id, plan_key, name, is_active, sort_order, is_addon, created_at, updated_at) VALUES (40, 'plugin.salons.monthly', 'Salons', true, 1, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO plan_prices (plan_id, currency, unit_amount, billing_period, is_per_seat, is_active, external_ref, created_at, updated_at) VALUES (40, 'USD', 2000, 'month', true, true, 'pri_salon', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        $changed = [];
        $portal = $this->createStub(BillingPortal::class);
        $portal->method('subscriptionsFor')->willReturn([
            new SubscriptionLine('sub_tier', 'pri_team', 'active', true, 1),
            new SubscriptionLine('sub_salon', 'pri_salon', 'active', true, 2),
        ]);
        $portal->method('changeQuantity')->willReturnCallback(function (BillingActor $actor, string $ref, int $quantity) use (&$changed): void {
            $changed[] = [$ref, $quantity];
        });

        $seats = $this->seats($pdo, $portal);
        $seats->onSettingChanged(['tenant_id' => 5, 'plugin' => 'salons', 'key' => 'chairs']);
        $this->assertSame([['sub_salon', 3]], $changed, 'Only the plugin\'s own subscription, to the new count');

        $changed = [];
        $seats->onSettingChanged(['tenant_id' => 5, 'plugin' => 'salons', 'key' => 'something_else']);
        $seats->onSettingChanged(['tenant_id' => null, 'plugin' => 'salons', 'key' => 'chairs']);
        $this->assertSame([], $changed);
    }

    private function seats(?PDO $pdo = null, ?BillingPortal $portal = null): PluginSeats
    {
        return new PluginSeats(fn (int $tenantId): array => $this->settings, static fn (): array => self::ACCESS, $pdo, $portal);
    }
}
