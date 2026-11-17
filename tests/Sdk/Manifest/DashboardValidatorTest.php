<?php

declare(strict_types=1);

namespace Tests\Sdk\Manifest;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Manifest\DashboardValidator;

/**
 * {@see \Whity\Sdk\PluginDashboardInterface} (SDK 1.115, WHIT-1177): setup
 * points and dashboard widgets.
 */
final class DashboardValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function dashboard(): array
    {
        return [
            'setup' => [
                'permission' => 'salons:manage',
                'status' => '/api/salons/setup-points',
                'points' => [[
                    'id' => 'chairs',
                    'label' => ['en' => 'Add your chairs', 'ar' => 'أضف كراسي الصالون'],
                    'why' => ['en' => 'Bookings need a chair', 'ar' => 'الحجوزات تحتاج إلى كرسي'],
                    'step' => 'chairs',
                ]],
            ],
            'widgets' => [
                self::widget(),
                [
                    'id' => 'quick',
                    'kind' => 'actions',
                    'title' => ['en' => 'Quick actions', 'ar' => 'إجراءات سريعة'],
                    'permission' => 'salons:manage',
                    'blocks' => [['type' => 'button', 'label' => ['en' => 'New booking', 'ar' => 'حجز جديد'], 'href' => '/admin/x/salons-bookings']],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function widget(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'todays-bookings',
            'kind' => 'list',
            'title' => ['en' => "Today's bookings", 'ar' => 'حجوزات اليوم'],
            'permission' => 'salons:read',
            'source' => '/api/salons/today',
            'sizes' => ['medium', 'large'],
            'blocks' => [['type' => 'dataList', 'source' => '/api/salons/today', 'itemField' => 'line']],
        ];
    }

    public function testAFullDeclarationIsValid(): void
    {
        self::assertSame([], DashboardValidator::errors(self::dashboard()));
        self::assertSame([], DashboardValidator::errors([]), 'Both parts are optional');
    }

    public function testAPointOpensExactlyOneOfAStepAndAScreen(): void
    {
        $point = self::dashboard()['setup']['points'][0];
        self::assertSame([], DashboardValidator::pointErrors(['screen' => 'salons-chairs'] + array_diff_key($point, ['step' => 1])));
        self::assertNotSame([], DashboardValidator::pointErrors($point + ['screen' => 'salons-chairs']));
        self::assertNotSame([], DashboardValidator::pointErrors(array_diff_key($point, ['step' => 1])));
    }

    public function testVisibleTextIsEnglishAndArabic(): void
    {
        $point = self::dashboard()['setup']['points'][0];
        $point['label'] = ['en' => 'Add your chairs'];
        self::assertContains('point.label.ar: required, at most 80 characters', DashboardValidator::pointErrors($point));

        self::assertNotSame([], DashboardValidator::widgetErrors(self::widget(['title' => 'Bookings'])));
    }

    public function testTheSetupNeedsAPermissionAStatusRouteAndPoints(): void
    {
        $setup = self::dashboard()['setup'];
        self::assertNotSame([], DashboardValidator::setupErrors(['permission' => 'admin'] + $setup));
        self::assertNotSame([], DashboardValidator::setupErrors(['status' => '/api/salons/{id}'] + $setup));
        self::assertNotSame([], DashboardValidator::setupErrors(['points' => []] + $setup));
        $twice = $setup;
        $twice['points'][] = $setup['points'][0];
        self::assertContains('dashboard.setup.points[1].id: chairs is used twice', DashboardValidator::setupErrors($twice));
    }

    public function testAWidgetIsBuiltOnlyFromTheBlocksItsKindAllows(): void
    {
        $errors = DashboardValidator::widgetErrors(self::widget(['blocks' => [
            ['type' => 'dataList', 'source' => '/api/salons/today', 'itemField' => 'line'],
            ['type' => 'chart', 'source' => '/api/salons/today', 'chartType' => 'bar', 'series' => [['field' => 'n', 'label' => 'n']]],
        ]]));
        self::assertNotSame([], $errors);
        self::assertStringContainsString('not chart', implode(' ', $errors));
    }

    public function testEveryDataBlockReadsTheWidgetsOwnSource(): void
    {
        $errors = DashboardValidator::widgetErrors(self::widget(['blocks' => [
            ['type' => 'dataList', 'source' => '/api/salons/other', 'itemField' => 'line'],
        ]]));
        self::assertContains("widget.blocks[0].source: must be the widget's own source", $errors);
    }

    public function testAKindNeedsItsMainBlock(): void
    {
        $errors = DashboardValidator::widgetErrors(self::widget(['blocks' => [['type' => 'text', 'value' => ['en' => 'Hi', 'ar' => 'مرحبا']]]]));
        self::assertContains('widget.blocks: a list widget needs one of dataList, dataTable', $errors);
    }

    public function testOnlyAnActionsWidgetGoesWithoutASource(): void
    {
        $noSource = self::widget();
        unset($noSource['source']);
        self::assertContains('widget.source: required for a list widget', DashboardValidator::widgetErrors($noSource));
        self::assertSame([], DashboardValidator::widgetErrors(self::dashboard()['widgets'][1]));
    }

    public function testSizesAreKnownAndDefaultByKind(): void
    {
        self::assertNotSame([], DashboardValidator::widgetErrors(self::widget(['sizes' => ['huge']])));
        self::assertNotSame([], DashboardValidator::widgetErrors(self::widget(['sizes' => ['medium', 'medium']])));
        self::assertSame(['medium', 'large'], DashboardValidator::sizesOf(self::widget()));
        self::assertSame(['small', 'medium'], DashboardValidator::sizesOf(['kind' => 'stat']));
    }

    public function testAWidgetIdIsUsedOnce(): void
    {
        $dashboard = self::dashboard();
        $dashboard['widgets'][] = self::widget();
        self::assertContains('dashboard.widgets[2].id: todays-bookings is used twice', DashboardValidator::errors($dashboard));
    }

    public function testUnknownKeysAreRefused(): void
    {
        self::assertContains('dashboard.cards: unknown key', DashboardValidator::errors(['cards' => []]));
        self::assertContains('widget.colour: unknown key', DashboardValidator::widgetErrors(self::widget(['colour' => 'red'])));
    }
}
