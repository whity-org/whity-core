<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FormFieldsApiHandler;
use Whity\Core\Form\FieldType;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRepository;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * A new question of every kind comes back from the server as the builder sent it.
 *
 * The builder compares what it holds with what was saved. A kind the server rewrites
 * looks changed for ever, and a draft saves itself every few seconds with the bar never
 * clearing. `web/__tests__/form-builder.test.tsx` holds the builder to these same
 * vectors, so a new kind has to pass both.
 */
final class FormQuestionBodyVectorsRealEngineTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private static function vectors(): array
    {
        /** @var array<string, array<string, mixed>> */
        return json_decode((string) file_get_contents(__DIR__ . '/../Unit/Core/Form/question-body-vectors.json'), true);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testEveryKindHasAVector(): void
    {
        self::assertEqualsCanonicalizing(FieldType::all(), array_keys(self::vectors()));
    }

    public function testEveryKindComesBackAsItWasSent(): void
    {
        $pdo = SchemaFromMigrations::make();
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        TenantContext::setTenantId(1);
        $forms = new FormRepository($pdo);
        $handler = new FormFieldsApiHandler($forms, new FormFieldRepository($pdo));
        $formId = $forms->create(1, 'supplier_request', ['en' => 'Supplier request'], null, null, null);

        foreach (self::vectors() as $kind => $sent) {
            $request = new Request('PUT', '/x', [], (string) json_encode(['fields' => [$sent]]));
            $response = $handler->replace($request, ['id' => (string) $formId]);
            self::assertSame(200, $response->getStatusCode(), $kind . ': ' . $response->getBody());

            $row = json_decode((string) $response->getBody(), true)['data'][0];
            $row['help_text'] = $row['help_text_i18n'] === [] ? null : $row['help_text_i18n'];
            foreach ($sent as $property => $value) {
                self::assertEquals($value, $row[$property], "{$kind}.{$property}");
            }
        }
    }
}
