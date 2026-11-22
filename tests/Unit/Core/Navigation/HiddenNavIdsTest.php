<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Navigation\HiddenNavIds;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * #2386: the ids an operator hides, from the environment and the setting.
 */
final class HiddenNavIdsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV[HiddenNavIds::ENV]);
        putenv(HiddenNavIds::ENV);
    }

    public function testParseTrimsDropsBlanksDuplicatesAndNonIds(): void
    {
        self::assertSame(
            ['calendar', 'plugin-reports', 'settings.sso'],
            HiddenNavIds::parse(' calendar,, plugin-reports ,settings.sso,calendar,../admin, ')
        );
        self::assertSame([], HiddenNavIds::parse(null));
        self::assertSame([], HiddenNavIds::parse('  '));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function values(): iterable
    {
        yield 'empty' => ['', true];
        yield 'menu ids' => ['calendar, contacts', true];
        yield 'plugin and settings' => ['plugin-com.example.reports,settings.sso', true];
        yield 'a path' => ['/admin/users', false];
        yield 'traversal' => ['calendar,../x', false];
        yield 'the settings screen' => ['website-settings', false];
        yield 'the general settings page' => ['contacts,settings.general', false];
    }

    #[DataProvider('values')]
    public function testValidate(string $value, bool $ok): void
    {
        self::assertSame($ok, HiddenNavIds::validate($value) === null, (string) HiddenNavIds::validate($value));
    }

    public function testTooManyIdsAreRefused(): void
    {
        $ids = implode(',', array_map(static fn (int $i): string => "page-{$i}", range(1, HiddenNavIds::MAX_IDS + 1)));

        self::assertNotNull(HiddenNavIds::validate($ids));
    }

    public function testEnvironmentAndSettingAreJoined(): void
    {
        $_ENV[HiddenNavIds::ENV] = 'calendar, website-settings';
        $settings = new SettingsService(new GlobalSettingsRepository($pdo = SchemaFromMigrations::make()), new TenantSettingsRepository($pdo));
        $settings->setGlobal(HiddenNavIds::SETTING, 'contacts,settings.sso,calendar');

        // The environment may hide the settings screen; the setting may not.
        self::assertSame(['calendar', 'contacts', 'settings.sso', 'website-settings'], HiddenNavIds::all($settings));
        self::assertSame(
            ['nav' => ['calendar', 'contacts', 'website-settings'], 'settings' => ['sso']],
            HiddenNavIds::split(HiddenNavIds::all($settings))
        );
    }

    public function testNoSettingsServiceReadsTheEnvironmentOnly(): void
    {
        $_ENV[HiddenNavIds::ENV] = 'tasks';

        self::assertSame(['tasks'], HiddenNavIds::all(null));
    }
}
