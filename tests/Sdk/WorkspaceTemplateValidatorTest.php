<?php

declare(strict_types=1);

namespace Tests\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExampleBakeryTemplate;
use Whity\Core\Workspace\Templates\TemplateCatalogue;
use Whity\Sdk\Manifest\WorkspaceTemplateValidator;

/** WHIT-1194 (SDK 1.116): the shape of a workspace template. */
final class WorkspaceTemplateValidatorTest extends TestCase
{
    public function testAFullTemplateAndBlankAreValid(): void
    {
        self::assertSame([], WorkspaceTemplateValidator::errors(ExampleBakeryTemplate::manifest()));
        self::assertSame([], WorkspaceTemplateValidator::errors(TemplateCatalogue::blank()));
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $change */
    #[DataProvider('broken')]
    public function testEachMistakeIsNamed(\Closure $change, string $expected): void
    {
        $errors = WorkspaceTemplateValidator::errors($change(ExampleBakeryTemplate::manifest()));

        self::assertNotSame([], $errors);
        self::assertStringContainsString($expected, implode("\n", $errors));
    }

    /** @return iterable<string, array{\Closure, string}> */
    public static function broken(): iterable
    {
        yield 'no Arabic name' => [static fn (array $t): array => ['name' => ['en' => 'Bakery']] + $t, 'template.name.ar'];
        yield 'unknown key' => [static fn (array $t): array => $t + ['code' => 'x'], 'template.code: unknown key'];
        yield 'a real email in examples' => [static function (array $t): array {
            $t['examples']['contacts'][0]['email'] = 'someone@gmail.com';

            return $t;
        }, 'an @example.com address'];
        yield 'a booking for a service it does not have' => [static function (array $t): array {
            $t['examples']['bookings'][0]['service'] = 'bread';

            return $t;
        }, "one of the template's services"];
        yield 'a step kind core does not draw' => [static function (array $t): array {
            $t['setup']['steps'][0]['kind'] = 'payments';

            return $t;
        }, 'kind: one of'];
        yield 'a screen step with no path' => [static function (array $t): array {
            unset($t['setup']['steps'][2]['href']);

            return $t;
        }, 'href: required for a screen step'];
        yield 'a setting it may not set' => [static function (array $t): array {
            $t['settings']['auth.password_sign_in'] = 'on';

            return $t;
        }, 'auth.password_sign_in: unknown key'];
        yield 'a price as a float' => [static function (array $t): array {
            $t['services'][0]['prices'] = ['JOD' => 20.5];

            return $t;
        }, 'prices.JOD'];
        yield 'two services with one key' => [static function (array $t): array {
            $t['services'][1]['key'] = 'cake-order';

            return $t;
        }, 'cake-order is used twice'];
        yield 'an offer with nothing paid' => [static function (array $t): array {
            $t['offer']['paid'] = [];

            return $t;
        }, 'template.offer.paid'];
        yield 'a theme that is not an id' => [static function (array $t): array {
            $t['theme'] = 'Market';

            return $t;
        }, 'template.theme'];
        yield 'a theme that is not text' => [static function (array $t): array {
            $t['theme'] = ['id' => 'templates.market'];

            return $t;
        }, 'template.theme'];
    }

    public function testATemplateMayNameItsTheme(): void
    {
        $template = ExampleBakeryTemplate::manifest();
        $template['theme'] = 'templates.market';

        self::assertSame([], WorkspaceTemplateValidator::errors($template));
    }
}
