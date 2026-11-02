<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FormFieldsApiHandler;
use Whity\Core\Form\FieldType;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRepository;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;

/**
 * Writing a condition through the fields API, on the migrated schema.
 *
 * A rule that could never be evaluated is refused when an author writes it, and
 * the three write paths (add, save the whole set, edit one) have to agree.
 */
final class FormFieldConditionsApiRealEngineTest extends TestCase
{
    private const TENANT = 1;

    private FormFieldRepository $fields;
    private FormFieldsApiHandler $handler;
    private int $formId;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make();
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");

        $forms = new FormRepository($pdo);
        $this->fields = new FormFieldRepository($pdo);
        $this->handler = new FormFieldsApiHandler($forms, $this->fields);
        $this->formId = $forms->create(self::TENANT, 'supplier_request', ['en' => 'Supplier request'], null, null, null);

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
    private function request(string $method, array $body = []): Request
    {
        return new Request(
            $method,
            '/api/forms/' . $this->formId . '/fields',
            [],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function body(string $key, string $type, array $extra = []): array
    {
        return $extra + ['field_key' => $key, 'field_type' => $type, 'label' => ['en' => $key]];
    }

    private function create(string $key, string $type, mixed $visibleWhen = null): Response
    {
        return $this->handler->create(
            $this->request('POST', self::body($key, $type, ['visible_when' => $visibleWhen])),
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

    public function testAConditionIsStoredAndComesBackOnTheField(): void
    {
        self::assertSame(201, $this->create('has_stock', FieldType::CHECKBOX)->getStatusCode());
        $created = $this->create('quantity', FieldType::NUMBER, ['field' => 'has_stock', 'equals' => true]);

        self::assertSame(201, $created->getStatusCode());
        self::assertSame(['field' => 'has_stock', 'equals' => true], self::data($created)['visible_when']);
    }

    public function testAFieldWithNoConditionReadsBackNull(): void
    {
        self::assertNull(self::data($this->create('notes', FieldType::TEXT))['visible_when']);
    }

    public function testAddingARuleOnAMissingFieldIsRefused(): void
    {
        $response = $this->create('quantity', FieldType::NUMBER, ['field' => 'gone', 'equals' => true]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->fields->listForForm(self::TENANT, $this->formId));
    }

    public function testEditingCanSetAndClearARuleButNotMakeALoop(): void
    {
        $a = (int) self::data($this->create('a', FieldType::TEXT))['id'];
        $b = (int) self::data($this->create('b', FieldType::TEXT, ['field' => 'a', 'equals' => 'x']))['id'];
        $patch = fn (int $id, mixed $rule): Response => $this->handler->update(
            $this->request('PATCH', ['visible_when' => $rule]),
            ['id' => (string) $this->formId, 'fieldId' => (string) $id],
        );

        self::assertSame(422, $patch($a, ['field' => 'b', 'equals' => 'x'])->getStatusCode());
        self::assertNull($this->fields->find(self::TENANT, $this->formId, $a)['visible_when'] ?? null);

        self::assertSame(200, $patch($b, null)->getStatusCode());
        self::assertNull($this->fields->find(self::TENANT, $this->formId, $b)['visible_when'] ?? null);
    }

    public function testRetypingAControllerAsAFileIsRefused(): void
    {
        $a = (int) self::data($this->create('a', FieldType::TEXT))['id'];
        $this->create('b', FieldType::TEXT, ['field' => 'a', 'equals' => 'x']);

        $response = $this->handler->update(
            $this->request('PATCH', ['field_type' => FieldType::FILE]),
            ['id' => (string) $this->formId, 'fieldId' => (string) $a],
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testSavingTheWholeSetKeepsRulesAndRefusesADanglingOne(): void
    {
        $good = $this->handler->replace(
            $this->request('PUT', ['fields' => [
                self::body('has_stock', FieldType::CHECKBOX),
                self::body('quantity', FieldType::NUMBER, ['visible_when' => ['field' => 'has_stock', 'equals' => true]]),
            ]]),
            ['id' => (string) $this->formId],
        );
        self::assertSame(200, $good->getStatusCode());
        self::assertSame(
            ['field' => 'has_stock', 'equals' => true],
            $this->fields->listForForm(self::TENANT, $this->formId)[1]['visible_when'],
        );

        // Dropping the controller while keeping the rule is one request, and it
        // must leave the saved set as it was.
        $bad = $this->handler->replace(
            $this->request('PUT', ['fields' => [
                self::body('quantity', FieldType::NUMBER, ['visible_when' => ['field' => 'has_stock', 'equals' => true]]),
            ]]),
            ['id' => (string) $this->formId],
        );
        self::assertSame(422, $bad->getStatusCode());
        self::assertCount(2, $this->fields->listForForm(self::TENANT, $this->formId));
    }

    public function testDeletingAQuestionOthersDependOnIsRefusedUntilTheyStop(): void
    {
        $a = (int) self::data($this->create('a', FieldType::TEXT))['id'];
        $b = (int) self::data($this->create('b', FieldType::TEXT, ['field' => 'a', 'equals' => 'x']))['id'];
        $delete = fn (int $id): Response => $this->handler->delete(
            $this->request('DELETE'),
            ['id' => (string) $this->formId, 'fieldId' => (string) $id],
        );

        self::assertSame(422, $delete($a)->getStatusCode());
        self::assertSame(200, $delete($b)->getStatusCode());
        self::assertSame(200, $delete($a)->getStatusCode());
    }
}
