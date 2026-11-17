<?php

declare(strict_types=1);

namespace Tests\Core\Workspace\Templates;

use PHPUnit\Framework\TestCase;
use Tests\Support\ExampleBakeryTemplate;
use Whity\Core\Workspace\Templates\TemplateSetup;
use Whity\Sdk\Frontend\Blocks\BlockValidator;

/**
 * WHIT-1195: a template's guided setup is drawn by core from its step kinds,
 * as blocks the stepper already draws, with the plugin offer last.
 */
final class TemplateSetupTest extends TestCase
{
    private const AMRO = 7;

    /** @return array<string, mixed> */
    private static function offer(): array
    {
        return ExampleBakeryTemplate::manifest()['offer'] + ['trialDays' => 14];
    }

    public function testEveryStepIsAValidBlockScreenAndTheOfferComesLast(): void
    {
        $template = ExampleBakeryTemplate::manifest();
        $template['setup']['steps'] = [
            ...$template['setup']['steps'],
            ['id' => 'about', 'kind' => 'business', 'title' => ['en' => 'You', 'ar' => 'أنت']],
            ['id' => 'team', 'kind' => 'team', 'title' => ['en' => 'Team', 'ar' => 'الفريق']],
            ['id' => 'rules', 'kind' => 'booking', 'title' => ['en' => 'Rules', 'ar' => 'القواعد']],
            ['id' => 'share', 'kind' => 'link', 'title' => ['en' => 'Share', 'ar' => 'شارك']],
        ];

        $setup = TemplateSetup::onboarding($template, self::offer(), 'settings:write');

        self::assertNotNull($setup);
        self::assertSame('template-bakery', $setup['plugin']);
        self::assertSame('/api/v1/workspace/templates/bakery/setup/status', $setup['status']);
        self::assertSame(['hours', 'menu', 'logo', 'about', 'team', 'rules', 'share', 'offer'], array_column($setup['steps'], 'id'));
        foreach ($setup['steps'] as $step) {
            $result = BlockValidator::validate($step['blocks']);
            self::assertTrue($result['ok'], $step['id'] . ': ' . implode('; ', $result['errors']));
        }

        $offer = json_encode($setup['steps'][7]['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertIsString($offer);
        self::assertStringContainsString('/api/v1/workspace/templates/bakery/setup/offer/trial', $offer);
        self::assertStringContainsString('Start your 30-day free trial', $offer);
        self::assertStringContainsString('✓ الطلبات عند الكاونتر برمز QR', $offer);
        self::assertStringContainsString('/api/v1/workspace/templates/bakery/setup/offer/close', $offer);
    }

    public function testNoOfferWithoutAPaidPluginAndNoSetupWithoutSteps(): void
    {
        $setup = TemplateSetup::onboarding(ExampleBakeryTemplate::manifest(), null, 'settings:write');
        self::assertNotNull($setup);
        self::assertNotContains('offer', array_column($setup['steps'], 'id'));

        $template = ExampleBakeryTemplate::manifest();
        unset($template['setup']);
        self::assertNull(TemplateSetup::onboarding($template, null, 'settings:write'));
    }

    public function testDoneIsWorkedOutFromTheWorkspace(): void
    {
        $api = new FakeSetup();
        $setup = new TemplateSetup($api);
        $template = ExampleBakeryTemplate::manifest();

        self::assertSame(['hours' => false, 'menu' => false, 'logo' => false, 'offer' => false], $setup->status($template, self::AMRO, ['state' => 'off'], false));

        $api->hours = [['weekday' => 6, 'start_time' => '07:00', 'end_time' => '15:00']];
        $api->services = [['id' => 3, 'name' => ['en' => 'Cake order', 'ar' => 'طلب كيك']]];
        self::assertSame(['hours' => true, 'menu' => true, 'logo' => false, 'offer' => true], $setup->status($template, self::AMRO, ['state' => 'on'], false), 'A trial counts');
        self::assertTrue($setup->status($template, self::AMRO, ['state' => 'off'], true)['offer'], '"Not now" counts');
    }

    public function testTheServicesStepAddsOnlyWhatIsTickedAndNew(): void
    {
        $api = new FakeSetup();
        $api->services = [['id' => 3, 'name' => ['en' => 'Tasting', 'ar' => 'تذوق']]];
        $setup = new TemplateSetup($api);

        $rows = $setup->serviceRows(ExampleBakeryTemplate::manifest());
        self::assertSame([['include' => true, 'name_en' => 'Cake order', 'name_ar' => 'طلب كيك', 'minutes' => '15', 'price' => '20.000']], $rows, 'Priced in JOD; what is there is left out');

        $saved = $setup->save('services', self::AMRO, ['services' => [
            ...$rows,
            ['include' => false, 'name_en' => 'Bread', 'minutes' => '10', 'price' => '1'],
            ['include' => true, 'name_en' => 'tasting', 'minutes' => '30', 'price' => ''],
        ]]);

        self::assertSame(['added' => ['Cake order']], $saved);
        self::assertSame(20000, $api->body('POST /api/calendar/services')['price_minor']);
    }

    public function testTheBusinessStepOpensWithWhatTheWorkspaceHas(): void
    {
        $api = new FakeSetup();
        self::assertSame(['name_en' => 'Example Bakery'], (new TemplateSetup($api, static fn (): string => 'Example Bakery'))->current('business', self::AMRO), 'The name it was created with');
        self::assertSame(['name_ar' => 'مخبز المثال'], (new TemplateSetup($api, static fn (): string => 'مخبز المثال'))->current('business', self::AMRO), 'An Arabic name goes in the Arabic field');

        $api->effective = ['company.name' => 'Example Bakery Ltd', 'company.phone' => '+962 6 000 0000', 'branding_main_color' => '#A67C00'];
        $api->overridden = ['branding_main_color'];
        self::assertSame(
            ['name_en' => 'Example Bakery Ltd', 'phone' => '+962 6 000 0000', 'colour' => '#a67c00'],
            (new TemplateSetup($api, static fn (): string => 'Example Bakery'))->current('business', self::AMRO),
            'What the workspace saved wins over its first name',
        );
    }

    public function testAStepSavesOnlyWhatChangedAndSaysWhatIsWrong(): void
    {
        $api = new FakeSetup();
        $setup = new TemplateSetup($api);

        $setup->save('business', self::AMRO, ['name_en' => 'Example Bakery', 'name_ar' => 'مخبز المثال', 'colour' => '#A67C00']);
        self::assertSame(['settings' => ['site_name' => 'Example Bakery', 'company.name' => 'Example Bakery', 'company.name_ar' => 'مخبز المثال', 'branding_main_color' => '#a67c00']], $api->body('PATCH /api/settings'));

        $setup->save('booking', self::AMRO, ['min_notice_minutes' => '30', 'instant_booking' => false]);
        self::assertSame(['booking_page_enabled' => true, 'min_notice_minutes' => 30, 'instant_booking' => false], $api->body('PUT /api/calendar/settings'));

        $this->expectException(\InvalidArgumentException::class);
        $setup->save('team', self::AMRO, ['people' => [['email' => 'not an email']]]);
    }
}
