<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Form\FieldCondition;
use Whity\Core\Form\FieldType;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\SubmissionValidator;

/**
 * Who gets asked what. The vectors are shared with
 * `web/__tests__/form-conditions.test.ts`, so the server and the browser are
 * tested against the same answers rather than each against its own idea of them.
 */
final class FieldConditionTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function field(string $key, string $type, array $overrides = []): array
    {
        return $overrides + [
            'field_key' => $key,
            'field_type' => $type,
            'label' => ['en' => ucfirst(str_replace('_', ' ', $key))],
            'is_required' => false,
            'options' => [],
            'validation' => [],
            'visible_when' => null,
        ];
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: array<string, mixed>, 2: list<string>}>
     */
    public static function sharedVectors(): array
    {
        /** @var array{fields: list<array<string, mixed>>, cases: list<array{name: string, data: array<string, mixed>, hidden: list<string>}>} $file */
        $file = json_decode((string) file_get_contents(__DIR__ . '/field-condition-vectors.json'), true);

        $out = [];
        foreach ($file['cases'] as $case) {
            $out[$case['name']] = [$file['fields'], $case['data'], $case['hidden']];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $data
     * @param list<string>               $hidden
     */
    #[DataProvider('sharedVectors')]
    public function testTheSharedVectors(array $fields, array $data, array $hidden): void
    {
        $actual = array_keys(array_filter(
            FieldCondition::visibility($fields, $data),
            static fn (bool $asked): bool => !$asked,
        ));

        sort($actual);
        sort($hidden);
        self::assertSame($hidden, $actual);
    }

    public function testAHiddenRequiredQuestionDoesNotBlockTheSubmission(): void
    {
        $fields = [
            self::field('has_minors', FieldType::CHECKBOX),
            self::field('minor_count', FieldType::NUMBER, [
                'is_required' => true,
                'visible_when' => ['field' => 'has_minors', 'equals' => true],
            ]),
        ];

        $result = SubmissionValidator::validate($fields, ['has_minors' => false]);

        self::assertSame(['has_minors' => false], $result['values']);
    }

    public function testAShownRequiredQuestionIsStillRequired(): void
    {
        $fields = [
            self::field('has_minors', FieldType::CHECKBOX),
            self::field('minor_count', FieldType::NUMBER, [
                'is_required' => true,
                'visible_when' => ['field' => 'has_minors', 'equals' => true],
            ]),
        ];

        $this->expectException(FormRejectedException::class);
        $this->expectExceptionMessage('Minor count is required');

        SubmissionValidator::validate($fields, ['has_minors' => true]);
    }

    public function testAnAnswerToAHiddenQuestionIsNotStoredAndIsReported(): void
    {
        $fields = [
            self::field('has_minors', FieldType::CHECKBOX),
            self::field('minor_count', FieldType::NUMBER, [
                'visible_when' => ['field' => 'has_minors', 'equals' => true],
            ]),
        ];

        $result = SubmissionValidator::validate($fields, ['has_minors' => false, 'minor_count' => 'not a number']);

        // Not validated either: nobody asked, so nothing about it can be wrong.
        self::assertSame(['has_minors' => false], $result['values']);
        self::assertSame(['minor_count'], $result['ignored']);
    }

    public function testARuleThatCannotBeEvaluatedAsksTheQuestion(): void
    {
        $dangling = [self::field('notes', FieldType::TEXT, ['visible_when' => ['field' => 'gone', 'equals' => 'x']])];
        self::assertSame(['notes' => true], FieldCondition::visibility($dangling, []));

        $loop = [
            self::field('a', FieldType::TEXT, ['visible_when' => ['field' => 'b', 'equals' => 'x']]),
            self::field('b', FieldType::TEXT, ['visible_when' => ['field' => 'a', 'equals' => 'x']]),
        ];
        // Terminates, which is the property: a loop must not hang a submission.
        self::assertCount(2, FieldCondition::visibility($loop, []));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedRules(): array
    {
        return [
            'a string' => ['yes'],
            'no field' => [['equals' => true]],
            'a bad key' => [['field' => 'Not A Key', 'equals' => true]],
            'neither operand' => [['field' => 'a']],
            'both operands' => [['field' => 'a', 'equals' => 'x', 'in' => ['x']]],
            'an empty equals' => [['field' => 'a', 'equals' => '']],
            'a list as equals' => [['field' => 'a', 'equals' => ['x']]],
            'an empty in' => [['field' => 'a', 'in' => []]],
            'in that is not a list' => [['field' => 'a', 'in' => ['k' => 'x']]],
            'in holding a non-string' => [['field' => 'a', 'in' => [1]]],
        ];
    }

    #[DataProvider('malformedRules')]
    public function testAMalformedRuleIsRefused(mixed $rule): void
    {
        $this->expectException(FormRejectedException::class);
        FieldCondition::parse($rule);
    }

    public function testNullMeansAlwaysShownAndAGoodRuleSurvives(): void
    {
        self::assertNull(FieldCondition::parse(null));
        self::assertSame(['field' => 'a', 'equals' => true], FieldCondition::parse(['field' => 'a', 'equals' => true, 'x' => 1]));
        self::assertSame(['field' => 'a', 'in' => ['x', 'y']], FieldCondition::parse(['field' => 'a', 'in' => ['x', 'y']]));
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: string}>
     */
    public static function unauthorableSets(): array
    {
        return [
            'itself' => [
                [self::field('a', FieldType::TEXT, ['visible_when' => ['field' => 'a', 'equals' => 'x']])],
                'its own answer',
            ],
            'missing' => [
                [self::field('a', FieldType::TEXT, ['visible_when' => ['field' => 'gone', 'equals' => 'x']])],
                'not on this form',
            ],
            'a file' => [
                [
                    self::field('paper', FieldType::FILE),
                    self::field('a', FieldType::TEXT, ['visible_when' => ['field' => 'paper', 'equals' => 'x']]),
                ],
                'file question',
            ],
            'a loop' => [
                [
                    self::field('a', FieldType::TEXT, ['visible_when' => ['field' => 'b', 'equals' => 'x']]),
                    self::field('b', FieldType::TEXT, ['visible_when' => ['field' => 'c', 'equals' => 'x']]),
                    self::field('c', FieldType::TEXT, ['visible_when' => ['field' => 'a', 'equals' => 'x']]),
                ],
                'loop',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    #[DataProvider('unauthorableSets')]
    public function testAnUnworkableRuleIsRefusedWhenItIsWritten(array $fields, string $message): void
    {
        $this->expectException(FormRejectedException::class);
        $this->expectExceptionMessage($message);

        FieldCondition::assertAuthorable($fields);
    }

    public function testAChainThatEndsIsAuthorable(): void
    {
        $this->expectNotToPerformAssertions();

        FieldCondition::assertAuthorable([
            self::field('a', FieldType::CHECKBOX),
            self::field('b', FieldType::TEXT, ['visible_when' => ['field' => 'a', 'equals' => true]]),
            self::field('c', FieldType::TEXT, ['visible_when' => ['field' => 'b', 'equals' => 'x']]),
        ]);
    }

    public function testDependantsAreNamed(): void
    {
        $fields = [
            self::field('a', FieldType::CHECKBOX),
            self::field('b', FieldType::TEXT, ['visible_when' => ['field' => 'a', 'equals' => true]]),
            self::field('c', FieldType::TEXT),
        ];

        self::assertSame(['b'], FieldCondition::dependantsOf($fields, 'a'));
        self::assertSame([], FieldCondition::dependantsOf($fields, 'c'));
    }
}
