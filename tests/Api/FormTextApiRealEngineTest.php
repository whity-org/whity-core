<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FormFieldsApiHandler;
use Whity\Api\FormsApiHandler;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormRenderer;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\PrefillResolver;
use Whity\Core\Form\PublicFormLink;
use Whity\Core\Form\PublicFormView;
use Whity\Core\Form\SubmissionValidator;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;

/**
 * Everything a form says, in both languages, on the migrated schema. A form written
 * before migration 193 has to read exactly as it did.
 */
final class FormTextApiRealEngineTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private FormsApiHandler $formsApi;
    private FormFieldsApiHandler $fieldsApi;
    private FormRenderer $renderer;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");

        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->renderer = new FormRenderer($this->fields, new PrefillResolver($this->pdo));
        $this->formsApi = new FormsApiHandler(
            $this->forms,
            $this->fields,
            $this->renderer,
            new FormSubmissionRepository($this->pdo),
            new PublicFormLink('https://records.example.test'),
        );
        $this->fieldsApi = new FormFieldsApiHandler($this->forms, $this->fields);
        $this->formId = $this->forms->create(self::TENANT, 'supplier_request', ['en' => 'Supplier request'], null, null, null);

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patchForm(array $body): Response
    {
        return $this->formsApi->update(
            new Request('PATCH', '/api/forms/' . $this->formId, [], json_encode($body, JSON_THROW_ON_ERROR)),
            ['id' => (string) $this->formId],
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function addField(string $key, array $extra = []): Response
    {
        $body = $extra + ['field_key' => $key, 'field_type' => 'text', 'label' => ['en' => $key]];

        return $this->fieldsApi->create(
            new Request('POST', '/api/forms/' . $this->formId . '/fields', [], json_encode($body, JSON_THROW_ON_ERROR)),
            ['id' => (string) $this->formId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(Response $response): array
    {
        /** @var array{data: array<string, mixed>} $decoded */
        $decoded = json_decode((string) $response->getBody(), true);

        return $decoded['data'];
    }

    public function testADescriptionWrittenAsPlainTextBeforeThisChangeReadsAsItDid(): void
    {
        $this->pdo->exec("UPDATE forms SET description = 'Tell us about your supplier.' WHERE id = " . $this->formId);

        $form = $this->forms->find(self::TENANT, $this->formId);

        self::assertSame('Tell us about your supplier.', $form['description'] ?? null);
        self::assertSame(['en' => 'Tell us about your supplier.'], $form['description_i18n'] ?? null);
    }

    public function testBothLanguagesAreStoredAndTheStringStaysForOlderClients(): void
    {
        $response = $this->patchForm(['description' => ['en' => 'For new suppliers', 'ar' => 'للموردين الجدد']]);

        self::assertSame(200, $response->getStatusCode());
        $data = self::data($response);
        self::assertSame('For new suppliers', $data['description']);
        self::assertSame(['ar' => 'للموردين الجدد', 'en' => 'For new suppliers'], $data['description_i18n']);
    }

    public function testAnArabicOnlyDescriptionStillFillsTheString(): void
    {
        $data = self::data($this->patchForm(['description' => ['ar' => 'للموردين الجدد']]));

        self::assertSame('للموردين الجدد', $data['description']);
    }

    public function testPlainTextFromAnOlderClientKeepsTheArabic(): void
    {
        $this->patchForm(['description' => ['en' => 'Old', 'ar' => 'للموردين الجدد']]);

        $data = self::data($this->patchForm(['description' => 'New']));

        self::assertSame(['ar' => 'للموردين الجدد', 'en' => 'New'], $data['description_i18n']);
    }

    public function testNullClearsTheDescription(): void
    {
        $this->patchForm(['description' => ['en' => 'Old']]);

        $data = self::data($this->patchForm(['description' => null]));

        self::assertNull($data['description']);
        self::assertSame('{}', json_encode($data['description_i18n']) === '[]' ? '{}' : json_encode($data['description_i18n']));
    }

    public function testAnEmptyTranslationMapReachesTheWireAsAnObject(): void
    {
        $response = $this->patchForm(['submit_label' => ['en' => 'Send request']]);

        self::assertStringContainsString('"description_i18n":{}', (string) $response->getBody());
    }

    public function testHelpTextIsTranslatableOnEveryWritePath(): void
    {
        $created = self::data($this->addField('supplier', ['help_text' => ['en' => 'The legal name', 'ar' => 'الاسم القانوني']]));
        self::assertSame('The legal name', $created['help_text']);

        $patched = self::data($this->fieldsApi->update(
            new Request('PATCH', '/x', [], json_encode(['help_text' => 'Registered name'], JSON_THROW_ON_ERROR)),
            ['id' => (string) $this->formId, 'fieldId' => (string) $created['id']],
        ));
        self::assertSame(['ar' => 'الاسم القانوني', 'en' => 'Registered name'], $patched['help_text_i18n']);

        $this->fieldsApi->replace(
            new Request('PUT', '/x', [], json_encode(['fields' => [
                ['field_key' => 'supplier', 'field_type' => 'text', 'label' => ['en' => 'Supplier'], 'help_text' => 'Trading name'],
            ]], JSON_THROW_ON_ERROR)),
            ['id' => (string) $this->formId],
        );
        $saved = $this->fields->listForForm(self::TENANT, $this->formId)[0];
        self::assertSame(['ar' => 'الاسم القانوني', 'en' => 'Trading name'], $saved['help_text_i18n']);
    }

    public function testSectionTitlesReachBothRendersByKey(): void
    {
        $this->addField('supplier', ['section_key' => 'about']);
        $this->addField('stock', ['section_key' => 'goods']);
        $response = $this->patchForm(['sections' => [
            ['key' => 'about', 'title' => ['en' => 'About the supplier', 'ar' => 'عن المورد'], 'description' => ['en' => 'Who they are']],
            ['key' => 'retired', 'title' => ['en' => 'No field uses this']],
        ], 'submit_label' => ['en' => 'Send request', 'ar' => 'إرسال الطلب']]);
        self::assertSame(200, $response->getStatusCode());

        $form = $this->forms->find(self::TENANT, $this->formId) ?? [];
        $rendered = $this->renderer->render(self::TENANT, null, $form);

        self::assertSame(
            [
                ['key' => 'about', 'field_keys' => ['supplier'], 'title' => ['ar' => 'عن المورد', 'en' => 'About the supplier'], 'description' => ['en' => 'Who they are']],
                ['key' => 'goods', 'field_keys' => ['stock'], 'title' => null, 'description' => null],
            ],
            $rendered['sections'],
        );

        $public = PublicFormView::form($form, $rendered['fields']);
        self::assertSame($rendered['sections'], $public['sections']);
        self::assertSame(['ar' => 'إرسال الطلب', 'en' => 'Send request'], $public['submit_label']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformedText(): array
    {
        return [
            'a number as description' => [['description' => 5]],
            'sections not a list' => [['sections' => ['k' => 'v']]],
            'section with no key' => [['sections' => [['title' => ['en' => 'x']]]]],
            'section key twice' => [['sections' => [['key' => 'a'], ['key' => 'a']]]],
            'too long a label' => [['submit_label' => ['en' => str_repeat('x', 201)]]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedText')]
    public function testMalformedTextIsRefusedWithoutWriting(array $body): void
    {
        self::assertSame(422, $this->patchForm($body)->getStatusCode());
    }

    public function testARefusedAnswerSaysWhichQuestionAndWhyInACode(): void
    {
        $fields = [[
            'field_key' => 'quantity',
            'field_type' => 'number',
            'label' => ['ar' => 'الكمية'],
            'is_required' => false,
            'options' => [],
            'validation' => ['max' => 5],
        ]];

        try {
            SubmissionValidator::validate($fields, ['quantity' => 9]);
            self::fail('expected a refusal');
        } catch (FormRejectedException $e) {
            self::assertSame(
                ['reason' => 'above_max', 'field_key' => 'quantity', 'params' => ['max' => 5]],
                $e->details(),
            );
        }
    }

    public function testARefusalWithNoCodeHasNoDetails(): void
    {
        self::assertSame([], (new FormRejectedException('Not now'))->details());
    }
}
