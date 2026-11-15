<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\Routing;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Document\RouteTemplate\RouteTemplateGraph;
use Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation;
use Whity\Core\Document\RouteTemplate\RouteTemplateRejectedException;
use Whity\Core\Document\Routing\RouteSatisfaction;
use Whity\Core\Document\Routing\RouteStepInputs;
use Whity\Core\Document\Routing\RouteStepKind;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Sdk\Routing\RoutingRuleContext;
use Whity\Sdk\Routing\RoutingRuleResolverInterface;

/**
 * WHIT-1016: what a step may ask the person acting on it to fill in, and the
 * check of what they sent.
 */
final class RouteStepInputsTest extends TestCase
{
    public function testADeclarationIsShapedWithItsDefaults(): void
    {
        $inputs = RouteStepInputs::parse([
            ['key' => 'meeting_no', 'label' => '  Committee meeting number ', 'label_ar' => 'رقم جلسة اللجنة'],
            ['key' => 'file_no', 'label' => 'File number', 'required' => false, 'max_length' => 20],
        ]);

        self::assertSame([
            ['key' => 'meeting_no', 'label' => 'Committee meeting number', 'label_ar' => 'رقم جلسة اللجنة', 'required' => true, 'max_length' => 100],
            ['key' => 'file_no', 'label' => 'File number', 'label_ar' => null, 'required' => false, 'max_length' => 20],
        ], $inputs);
        self::assertSame([], RouteStepInputs::parse(null));
        self::assertSame([], RouteStepInputs::parse([]));
        self::assertSame($inputs, RouteStepInputs::decode(RouteStepInputs::encode($inputs)));
        self::assertNull(RouteStepInputs::encode([]));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function badDeclarations(): iterable
    {
        yield 'an object, not a list' => [['meeting_no' => ['label' => 'Meeting']]];
        yield 'a list, not an object' => [[['meeting_no', 'Meeting']]];
        yield 'a capital in the key' => [[['key' => 'Meeting', 'label' => 'Meeting']]];
        yield 'a space in the key' => [[['key' => 'meeting no', 'label' => 'Meeting']]];
        yield 'a key starting with a digit' => [[['key' => '1meeting', 'label' => 'Meeting']]];
        yield 'a key of 41 characters' => [[['key' => str_repeat('a', 41), 'label' => 'Meeting']]];
        yield 'no label' => [[['key' => 'meeting_no']]];
        yield 'a blank label' => [[['key' => 'meeting_no', 'label' => '  ']]];
        yield 'a label of two lines' => [[['key' => 'meeting_no', 'label' => "Meeting\nnumber"]]];
        yield 'a label of 81 characters' => [[['key' => 'meeting_no', 'label' => str_repeat('a', 81)]]];
        yield 'an Arabic label that is not text' => [[['key' => 'meeting_no', 'label' => 'Meeting', 'label_ar' => 7]]];
        yield 'required as text' => [[['key' => 'meeting_no', 'label' => 'Meeting', 'required' => 'yes']]];
        yield 'a length of 0' => [[['key' => 'meeting_no', 'label' => 'Meeting', 'max_length' => 0]]];
        yield 'a length of 201' => [[['key' => 'meeting_no', 'label' => 'Meeting', 'max_length' => 201]]];
        yield 'the same key twice' => [[['key' => 'a', 'label' => 'A'], ['key' => 'a', 'label' => 'B']]];
        yield 'six values' => [array_map(
            static fn (int $i): array => ['key' => 'v' . $i, 'label' => 'Value ' . $i],
            range(1, 6),
        )];
    }

    #[DataProvider('badDeclarations')]
    public function testABadDeclarationIsRefused(mixed $raw): void
    {
        $this->expectException(InvalidArgumentException::class);
        RouteStepInputs::parse($raw);
    }

    public function testAStoredValueThatCannotBeReadAsksForNothing(): void
    {
        self::assertSame([], RouteStepInputs::decode('not json'));
        self::assertSame([], RouteStepInputs::decode('[{"key":"Bad Key","label":"x"}]'));
        self::assertSame([], RouteStepInputs::decode(null));
    }

    public function testOnlyStagesPeopleActOnMayAskForValues(): void
    {
        $inputs = RouteStepInputs::parse([['key' => 'meeting_no', 'label' => 'Meeting']]);

        RouteStepInputs::assertAllowed($inputs, RouteSatisfaction::ACT, null);
        RouteStepInputs::assertAllowed([], RouteSatisfaction::DELIVERY, RouteStepKind::FINALISE);
        $this->addToAssertionCount(2);

        foreach ([[RouteSatisfaction::DELIVERY, null], [RouteSatisfaction::ACT, RouteStepKind::SIGN], [RouteSatisfaction::ACT, RouteStepKind::FINALISE]] as [$by, $kind]) {
            try {
                RouteStepInputs::assertAllowed($inputs, $by, $kind);
                self::fail("Inputs must be refused on {$by}/" . ($kind ?? 'none') . '.');
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testValuesSentWithAnActAreCheckedAgainstTheStep(): void
    {
        $inputs = RouteStepInputs::parse([
            ['key' => 'meeting_no', 'label' => 'Committee meeting number', 'max_length' => 5],
            ['key' => 'file_no', 'label' => 'File number', 'required' => false],
        ]);

        self::assertSame(['meeting_no' => '14'], RouteStepInputs::accept($inputs, ['meeting_no' => ' 14 ', 'file_no' => ' ']));
        self::assertSame(['meeting_no' => '14', 'file_no' => 'F-2'], RouteStepInputs::accept($inputs, ['meeting_no' => '14', 'file_no' => 'F-2']));

        foreach ([[], ['meeting_no' => '   '], ['meeting_no' => '123456'], ['meeting_no' => '1', 'extra' => 'x']] as $values) {
            try {
                RouteStepInputs::accept($inputs, $values);
                self::fail('These values must be refused: ' . json_encode($values));
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testTheValuesObjectIsShapedAtTheBoundary(): void
    {
        self::assertSame([], RouteStepInputs::parseValues(null));
        self::assertSame([], RouteStepInputs::parseValues([]));
        self::assertSame(['meeting_no' => '14'], RouteStepInputs::parseValues(['meeting_no' => '14']));

        foreach (['14', ['14'], ['meeting_no' => 14], ['meeting_no' => "14\n15"]] as $raw) {
            try {
                RouteStepInputs::parseValues($raw);
                self::fail('This must be refused: ' . json_encode($raw));
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testADesignCarriesItsInputsOntoTheRouteAndRefusesThemWhereNobodyActs(): void
    {
        $registry = new RoutingRuleRegistry();
        $registry->register('acme', ['committee' => new InputsTestResolver()]);
        $graph = new RouteTemplateGraph($registry, static fn (int $tenantId): bool => true);
        $input = ['key' => 'meeting_no', 'label' => 'Committee meeting number'];

        $valid = $graph->validate([
            ['position' => 1, 'rule_kind' => 'acme:committee', 'rule_config' => [], 'decision' => true, 'inputs' => [$input]],
            ['position' => 2, 'rule_kind' => 'acme:committee', 'rule_config' => []],
        ], [], 20);
        self::assertSame('meeting_no', $valid['steps'][0]['inputs'][0]['key']);
        self::assertSame([], $valid['steps'][1]['inputs']);

        $route = RouteTemplateInstantiation::toRouteSteps($valid['steps'], []);
        self::assertSame('meeting_no', $route[0]['inputs'][0]['key'], 'The route carries what the design asks for.');

        foreach ([
            ['position' => 1, 'rule_kind' => 'acme:committee', 'rule_config' => [], 'satisfied_by' => 'delivery', 'inputs' => [$input]],
            ['position' => 1, 'rule_kind' => 'acme:committee', 'rule_config' => [], 'inputs' => [['key' => 'Bad', 'label' => 'x']]],
            ['position' => 1, 'kind' => 'finalise', 'inputs' => [$input]],
        ] as $stage) {
            try {
                $graph->validate([$stage], [], 20, 1);
                self::fail('This stage must be refused: ' . json_encode($stage));
            } catch (RouteTemplateRejectedException $e) {
                self::assertStringStartsWith('Step 1', $e->getMessage());
            }
        }
    }
}

/** A plugin-contributed kind that accepts any config. */
final class InputsTestResolver implements RoutingRuleResolverInterface
{
    public function label(): string
    {
        return 'Everyone on a committee';
    }

    public function validate(array $config): void
    {
    }

    public function resolve(RoutingRuleContext $context): array
    {
        return [];
    }
}
