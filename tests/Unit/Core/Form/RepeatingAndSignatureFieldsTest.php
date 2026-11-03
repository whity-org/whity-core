<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Whity\Core\Form\FieldCondition;
use Whity\Core\Form\FieldType;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\SubmissionValidator;

/**
 * A table the person adds rows to, and a typed signature. The fixtures are an order
 * for Example Bakery.
 */
final class RepeatingAndSignatureFieldsTest extends TestCase
{
    /**
     * @param array<string, mixed> $validation
     * @param array<string, mixed> $overrides
     * @return list<array<string, mixed>>
     */
    private static function table(array $validation = [], array $overrides = []): array
    {
        return [$overrides + [
            'field_key' => 'suppliers',
            'field_type' => FieldType::REPEATING,
            'label' => ['en' => 'Suppliers'],
            'is_required' => false,
            'options' => [],
            'validation' => $validation + ['columns' => [
                ['key' => 'name', 'type' => 'text', 'label' => ['en' => 'Name'], 'required' => true],
                ['key' => 'crates', 'type' => 'number', 'label' => ['en' => 'Crates'], 'required' => false],
                [
                    'key' => 'kind',
                    'type' => 'select',
                    'label' => ['en' => 'Kind'],
                    'required' => false,
                    'options' => [['value' => 'flour', 'label' => ['en' => 'Flour']]],
                ],
            ]],
        ]];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return list<array<string, mixed>>
     */
    private static function signature(array $overrides = []): array
    {
        return [$overrides + [
            'field_key' => 'signed',
            'field_type' => FieldType::SIGNATURE,
            'label' => ['en' => 'Signature'],
            'is_required' => true,
            'options' => [],
            'validation' => [],
        ]];
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $data
     */
    private static function refusal(array $fields, array $data): FormRejectedException
    {
        try {
            SubmissionValidator::validate($fields, $data);
        } catch (FormRejectedException $e) {
            return $e;
        }
        self::fail('expected a refusal');
    }

    public function testRowsAreNormalizedCellByCell(): void
    {
        $result = SubmissionValidator::validate(self::table(), ['suppliers' => [
            ['name' => '  Example Mill ', 'crates' => '4', 'kind' => 'flour', 'unknown' => 'dropped'],
            ['name' => 'Example Dairy'],
        ]]);

        self::assertSame([
            ['name' => 'Example Mill', 'crates' => 4, 'kind' => 'flour'],
            ['name' => 'Example Dairy'],
        ], $result['values']['suppliers']);
    }

    public function testABadCellNamesTheQuestionRowAndColumn(): void
    {
        $e = self::refusal(self::table(), ['suppliers' => [['name' => 'A'], ['crates' => 2]]]);

        self::assertSame('Suppliers, row 2: Name is required', $e->clientMessage);
        self::assertSame(
            ['reason' => 'required', 'field_key' => 'suppliers', 'params' => ['row' => 2, 'column' => 'name']],
            $e->details(),
        );
    }

    public function testACompletelyBlankRowIsDroppedRatherThanRefused(): void
    {
        $result = SubmissionValidator::validate(self::table(), ['suppliers' => [
            ['name' => '', 'crates' => '   '],
            ['name' => 'Example Mill'],
        ]]);

        self::assertSame([['name' => 'Example Mill']], $result['values']['suppliers']);
    }

    public function testARequiredTableWithOnlyBlankRowsIsRefused(): void
    {
        $e = self::refusal(self::table(overrides: ['is_required' => true]), ['suppliers' => [['name' => '']]]);

        self::assertSame('required', $e->reason);
    }

    public function testAnOptionalTableWithOnlyBlankRowsIsAbsent(): void
    {
        self::assertSame([], SubmissionValidator::validate(self::table(), ['suppliers' => [['name' => '']]])['values']);
    }

    public function testRowLimitsAreEnforced(): void
    {
        $few = self::refusal(self::table(['minRows' => 2]), ['suppliers' => [['name' => 'A']]]);
        self::assertSame(['reason' => 'too_few_rows', 'field_key' => 'suppliers', 'params' => ['min' => 2]], $few->details());

        $many = self::refusal(self::table(['maxRows' => 1]), ['suppliers' => [['name' => 'A'], ['name' => 'B']]]);
        self::assertSame(['reason' => 'too_many_rows', 'field_key' => 'suppliers', 'params' => ['max' => 1]], $many->details());
    }

    public function testTheCeilingHoldsWhenAFieldAsksForMore(): void
    {
        $rows = array_fill(0, SubmissionValidator::ROWS_MAX + 1, ['name' => 'A']);

        $e = self::refusal(self::table(['maxRows' => SubmissionValidator::ROWS_MAX + 500]), ['suppliers' => $rows]);

        self::assertSame('too_many_rows', $e->reason);
    }

    public function testAnswersOfTheWrongShapeAreRefused(): void
    {
        self::assertSame('bad_rows', self::refusal(self::table(), ['suppliers' => 'text'])->reason);
        self::assertSame('bad_rows', self::refusal(self::table(), ['suppliers' => ['k' => ['name' => 'A']]])->reason);
        self::assertSame('bad_rows', self::refusal(self::table(), ['suppliers' => ['not a row']])->reason);
    }

    public function testASignatureStoresTheNameAndAServerTime(): void
    {
        $before = gmdate('Y-m-d\TH:i');
        $values = SubmissionValidator::validate(self::signature(), ['signed' => [
            'name' => '  Rana Example ',
            'signed_at' => '2001-01-01T00:00:00Z',
        ]])['values'];

        self::assertSame('Rana Example', $values['signed']['name']);
        // The clock is the server's. Nobody gets to backdate what they signed.
        self::assertStringStartsNotWith('2001', $values['signed']['signed_at']);
        self::assertGreaterThanOrEqual($before, $values['signed']['signed_at']);
    }

    public function testASignatureMayBeSentAsPlainText(): void
    {
        $values = SubmissionValidator::validate(self::signature(), ['signed' => 'Rana Example'])['values'];

        self::assertSame('Rana Example', $values['signed']['name']);
    }

    public function testABlankSignatureIsNotASignature(): void
    {
        foreach ([[], ['name' => '   '], '   ', ['signed_at' => 'x']] as $blank) {
            self::assertSame('required', self::refusal(self::signature(), ['signed' => $blank])->reason);
        }
    }

    public function testAnOptionalBlankSignatureIsAbsent(): void
    {
        $values = SubmissionValidator::validate(self::signature(['is_required' => false]), ['signed' => ['name' => ' ']])['values'];

        self::assertSame([], $values);
    }

    public function testASignatureOfTheWrongShapeOrLengthIsRefused(): void
    {
        self::assertSame('bad_signature', self::refusal(self::signature(), ['signed' => 5])->reason);
        self::assertSame('bad_signature', self::refusal(self::signature(), ['signed' => ['name' => 5]])->reason);
        self::assertSame('bad_signature', self::refusal(self::signature(), ['signed' => str_repeat('x', 201)])->reason);
    }

    public function testNeitherCanDecideWhetherAnotherQuestionIsAsked(): void
    {
        foreach ([FieldType::REPEATING, FieldType::SIGNATURE] as $kind) {
            try {
                FieldCondition::assertAuthorable([
                    ['field_key' => 'a', 'field_type' => $kind, 'visible_when' => null],
                    ['field_key' => 'b', 'field_type' => FieldType::TEXT, 'visible_when' => ['field' => 'a', 'equals' => 'x']],
                ]);
                self::fail($kind . ' must not be a controller');
            } catch (FormRejectedException $e) {
                self::assertStringContainsString('cannot depend on', $e->clientMessage);
            }
        }
    }
}
