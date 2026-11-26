<?php

declare(strict_types=1);

namespace Tests\Core\Workspace\Templates;

use PHPUnit\Framework\TestCase;
use Tests\Support\ExampleClinicTemplates as Clinic;
use Whity\Core\Workspace\Templates\TemplateCatalogue;
use Whity\Sdk\Manifest\WorkspaceTemplateValidator;

/**
 * WHIT-14XX (SDK 1.17X): a template's `order` places its card, lowest first;
 * templates without one come after, by English name, and Start blank stays
 * last. Whity's own templates keep the owner's order of 17 Nov 2026 whichever
 * plugin each comes from.
 */
final class TemplateOrderTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function template(string $key, string $name, ?int $order): array
    {
        $template = Clinic::clinic();
        $template['key'] = $key;
        $template['name'] = ['en' => $name, 'ar' => $name];
        if ($order !== null) {
            $template['order'] = $order;
        }

        return $template + ['plugin' => 'Example'];
    }

    public function testCardsFollowTheirOrderThenTheirName(): void
    {
        $catalogue = new TemplateCatalogue(null, static fn (): array => [
            self::template('zeta', 'Zeta studio', null),
            self::template('clinic', 'Clinic or practice', 50),
            self::template('alpha', 'Alpha gym', null),
            self::template('coffee-house', 'Coffee house', 10),
            self::template('salon', 'Salon or barber shop', 40),
        ]);

        self::assertSame(['coffee-house', 'salon', 'clinic', 'alpha', 'zeta', 'blank'], array_column($catalogue->all(), 'key'));
    }

    public function testOrderIsAWholeNumberInRange(): void
    {
        $valid = Clinic::clinic();
        $valid['order'] = 50;
        self::assertSame([], WorkspaceTemplateValidator::errors($valid));

        foreach ([0, 10000, '10', 1.5] as $bad) {
            $template = Clinic::clinic();
            $template['order'] = $bad;
            self::assertSame(['template.order: a whole number from 1 to 9999'], WorkspaceTemplateValidator::errors($template), var_export($bad, true));
        }
    }
}
