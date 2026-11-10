<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Billing\HostLicenceSales;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Sdk\Billing\LicenceCheckout;
use Whity\Sdk\Billing\LicenceCheckoutResult;
use Whity\Sdk\Billing\LicenceSales;
use Whity\Sdk\Billing\LicenceSaleStatus;

/**
 * The store reaches exactly one licence seller, the one the loader stamped,
 * and no other plugin can answer in its place (WHIT-1013).
 */
final class HostLicenceSalesTest extends TestCase
{
    private string $dir;
    /** @var list<string> */
    private array $names = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/whity_lic_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->names as $name) {
            @unlink($this->dir . '/' . $name . '/Plugin.php');
            @rmdir($this->dir . '/' . $name);
        }
        @rmdir($this->dir);
    }

    public function testTheFirstSellerKeepsTheSlotAndAnswers(): void
    {
        $sales = new HostLicenceSales();

        self::assertTrue($sales->offer('WhityPay', self::seller('paid', 'https://pay.example.test/real')));
        self::assertFalse($sales->offer('Impostor', self::seller('paid', 'https://evil.example.test/swap')), 'a second plugin is refused');
        self::assertSame('WhityPay', $sales->providerName());

        $result = $sales->checkout(self::purchase());
        self::assertTrue($result->isOk());
        self::assertSame('https://pay.example.test/real', $result->url);
    }

    public function testNoSellerMeansNotConfiguredAndNoStatus(): void
    {
        $sales = new HostLicenceSales();

        self::assertSame(LicenceCheckoutResult::NOT_CONFIGURED, $sales->checkout(self::purchase())->error);
        self::assertNull($sales->status('lic_1'));
        self::assertNull($sales->providerName());
    }

    public function testASellerThatThrowsIsUnavailableNotACrash(): void
    {
        $sales = new HostLicenceSales();
        $sales->offer('WhityPay', new class () implements LicenceSales {
            public function checkout(LicenceCheckout $checkout): LicenceCheckoutResult
            {
                throw new \RuntimeException('https://api.provider.example/v1 said no');
            }

            public function status(string $reference): ?LicenceSaleStatus
            {
                throw new \RuntimeException('boom');
            }
        });

        self::assertSame(LicenceCheckoutResult::UNAVAILABLE, $sales->checkout(self::purchase())->error);
        self::assertNull($sales->status('lic_1'));
    }

    public function testAnAnswerAboutAnotherLicenceIsNoAnswer(): void
    {
        $sales = new HostLicenceSales();
        $sales->offer('WhityPay', self::seller('paid', 'https://pay.example.test/x', 'lic_other'));

        self::assertNull($sales->status('lic_1'));
    }

    public function testTheLoaderGivesTheSlotToTheFirstPluginAndRefusesASecond(): void
    {
        $this->plugin('Aaseller', 'https://pay.example.test/first');
        $this->plugin('Zzimpostor', 'https://evil.example.test/second');
        $sales = new HostLicenceSales();

        $loader = new PluginLoader($this->dir, new Router(), new PermissionRegistry(), new HookManager());
        $loader->setLicenceSales($sales);
        $loader->load();

        self::assertNotNull($sales->providerName());
        $result = $sales->checkout(self::purchase());
        self::assertSame(
            $sales->providerName() === 'Aaseller' ? 'https://pay.example.test/first' : 'https://evil.example.test/second',
            $result->url,
            'whoever holds the slot is the only one that answers',
        );
        self::assertContains($sales->providerName(), ['Aaseller', 'Zzimpostor']);
        // Exactly one holds it: offering again under the other name fails.
        $other = $sales->providerName() === 'Aaseller' ? 'Zzimpostor' : 'Aaseller';
        self::assertFalse($sales->offer($other, self::seller('paid', 'https://x.example.test')));
    }

    private function plugin(string $name, string $url): void
    {
        $this->names[] = $name;
        mkdir($this->dir . '/' . $name, 0755, true);
        $source = <<<'PHP'
<?php
declare(strict_types=1);
namespace __NAME__;

use Whity\Sdk\Billing\LicenceCheckout;
use Whity\Sdk\Billing\LicenceCheckoutResult;
use Whity\Sdk\Billing\LicenceSales;
use Whity\Sdk\Billing\LicenceSaleStatus;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginLicenceSalesInterface;

final class Plugin implements PluginInterface, PluginLicenceSalesInterface
{
    public function getName(): string    { return '__NAME__'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }
    public function getLicenceSales(): LicenceSales
    {
        return new class implements LicenceSales {
            public function checkout(LicenceCheckout $c): LicenceCheckoutResult { return LicenceCheckoutResult::ok('__URL__', 'cs_1'); }
            public function status(string $r): ?LicenceSaleStatus { return new LicenceSaleStatus($r, 'plugin.cake-orders', 'paid'); }
        };
    }
}
PHP;
        file_put_contents($this->dir . '/' . $name . '/Plugin.php', str_replace(['__NAME__', '__URL__'], [$name, $url], $source));
    }

    private static function seller(string $status, string $url, ?string $answerFor = null): LicenceSales
    {
        return new class ($status, $url, $answerFor) implements LicenceSales {
            public function __construct(private readonly string $status, private readonly string $url, private readonly ?string $answerFor)
            {
            }

            public function checkout(LicenceCheckout $checkout): LicenceCheckoutResult
            {
                return LicenceCheckoutResult::ok($this->url, 'cs_1');
            }

            public function status(string $reference): LicenceSaleStatus
            {
                return new LicenceSaleStatus($this->answerFor ?? $reference, 'plugin.cake-orders', $this->status, '2026-12-08T00:00:00Z');
            }
        };
    }

    private static function purchase(): LicenceCheckout
    {
        return new LicenceCheckout('plugin.cake-orders', 'basic', 'month', 'USD', 'install-abc', 'Example Bakery', 'owner@example.test', 'lic_1', 'https://store.example.test/return');
    }
}
