<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Whity\Core\Form\FormTemplateFit;

final class FormTemplateFitTest extends TestCase
{
    /**
     * @param array<string, string> $label
     * @return array<string, mixed>
     */
    private static function question(string $key, array $label, string $audience = 'applicant'): array
    {
        return ['field_key' => $key, 'label' => $label, 'audience' => $audience];
    }

    /**
     * @param array<string, string>       $paths
     * @param list<array<string, string>> $own
     * @return array<string, mixed>
     */
    private static function content(array $paths, array $own = []): array
    {
        $runs = [];
        foreach ($paths as $path => $label) {
            $runs[] = ['type' => 'field', 'path' => $path, 'label' => $label];
        }

        return ['blocks' => [['id' => 'b1', 'type' => 'paragraph', 'content' => $runs]], 'page' => null, 'own_fields' => $own];
    }

    public function testAnAnsweredFieldIsFilledAndAQuestionTheFormNeverAskedIsReported(): void
    {
        $fit = FormTemplateFit::check(
            [self::question('supplier', ['en' => 'Supplier'])],
            self::content(['submission.supplier' => 'Supplier', 'submission.quantity' => 'Quantity']),
            null,
            [],
            [],
        );

        self::assertSame(1, $fit['filled']);
        self::assertSame(
            [['path' => 'submission.quantity', 'label' => 'Quantity', 'reason' => FormTemplateFit::NOT_ASKED, 'field_key' => 'quantity']],
            $fit['needs'],
        );
        self::assertSame([], $fit['unused']);
    }

    public function testAnOwnFieldIsFilledBySameKeyOrBySameWordingInEitherLanguage(): void
    {
        $fields = [
            self::question('supplier', ['en' => 'Supplier']),
            self::question('question_2', ['en' => 'Delivery date', 'ar' => 'تاريخ التسليم']),
            self::question('question_3', ['ar' => 'الكمية']),
        ];
        $content = self::content(
            ['template.supplier' => 'x', 'template.when' => 'Delivery  DATE ', 'template.amount' => 'الكمية'],
            [['key' => 'when', 'label' => 'Delivery date']],
        );

        self::assertSame(
            ['template.supplier' => 'supplier', 'template.when' => 'question_2', 'template.amount' => 'question_3'],
            FormTemplateFit::ownFieldAnswers($fields, $content),
        );
        $fit = FormTemplateFit::check($fields, $content, null, [], []);
        self::assertSame(3, $fit['filled']);
        self::assertSame([], $fit['needs']);
        self::assertSame([], $fit['unused']);
    }

    public function testFieldsEveryDocumentHasAreNotGaps(): void
    {
        $fit = FormTemplateFit::check(
            [],
            self::content(['company.name' => 'Company', 'submission.reference' => 'Ref', 'issued_document.number' => 'No']),
            null,
            ['company.name' => true],
            [],
        );

        self::assertSame(3, $fit['filled']);
        self::assertSame([], $fit['needs']);
    }

    public function testAQuestionOnlyTheOfficeAnswersCannotFillTheDocument(): void
    {
        $fit = FormTemplateFit::check(
            [self::question('office_note', ['en' => 'Office note'], 'staff')],
            self::content(['submission.office_note' => 'Office note']),
            null,
            [],
            [],
        );

        self::assertSame(FormTemplateFit::STAFF_ONLY, $fit['needs'][0]['reason']);
        self::assertNull($fit['needs'][0]['field_key'], 'adding it again would collide with the question that exists');
        self::assertSame([], $fit['unused'], 'and an office question is never offered for the document');
    }

    public function testAFieldFromAnotherKindOfRecordCannotBeAddedToTheForm(): void
    {
        $fit = FormTemplateFit::check([], self::content(['patient.name' => 'Patient']), null, [], []);

        self::assertSame(FormTemplateFit::OTHER_RECORD, $fit['needs'][0]['reason']);
        self::assertNull($fit['needs'][0]['field_key']);
    }

    public function testATemplateFieldKeyBecomesAValidQuestionKey(): void
    {
        $fit = FormTemplateFit::check([], self::content(['template.2nd_copy' => 'Second copy']), null, [], []);

        self::assertSame('question_2nd_copy', $fit['needs'][0]['field_key']);
    }

    public function testAQuestionWithNoPlaceIsReportedWithWhatTheDraftAndThePersonSaid(): void
    {
        $fields = [
            self::question('supplier', ['en' => 'Supplier']),
            self::question('notes', ['en' => 'Notes']),
            self::question('quantity', ['en' => 'Quantity']),
        ];
        $printed = self::content(['submission.supplier' => 'Supplier']);
        $draft = self::content(['submission.supplier' => 'Supplier', 'submission.notes' => 'Notes']);

        $unused = FormTemplateFit::check($fields, $printed, $draft, [], ['quantity'])['unused'];

        self::assertSame(
            [
                ['field_key' => 'notes', 'label' => ['en' => 'Notes'], 'in_draft' => true, 'left_out' => false],
                ['field_key' => 'quantity', 'label' => ['en' => 'Quantity'], 'in_draft' => false, 'left_out' => true],
            ],
            $unused,
        );
    }

    public function testAFieldInThePageHeaderCounts(): void
    {
        $content = ['blocks' => [], 'page' => ['header' => [['type' => 'field', 'path' => 'submission.supplier', 'label' => 'Supplier']]]];

        $fit = FormTemplateFit::check([self::question('supplier', ['en' => 'Supplier'])], $content, null, [], []);

        self::assertSame(1, $fit['filled']);
        self::assertSame([], $fit['unused']);
    }
}
