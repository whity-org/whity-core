<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Agent;

use PHPUnit\Framework\TestCase;
use Whity\Core\Agent\AgentDefinition;

final class AgentDefinitionTest extends TestCase
{
    public function testAFullDefinitionIsNormalised(): void
    {
        [$fields, $errors] = AgentDefinition::validate([
            'name'     => '  Finance clerk ',
            'name_ar'  => 'موظف المالية',
            'tone'     => 'formal',
            'toolsets' => ['core.billing', 'core.documents.*', 'core.billing', 'elmak.collections'],
            'grant'    => ['documents:issue' => 'ask', 'billing:view' => 'allow'],
        ]);

        self::assertSame([], $errors);
        self::assertSame('Finance clerk', $fields['name']);
        self::assertSame(['core.billing', 'core.documents.*', 'elmak.collections'], $fields['toolsets']);
        self::assertSame(['billing:view' => 'allow', 'documents:issue' => 'ask'], $fields['grant']);
    }

    public function testANameIsRequiredUnlessPatching(): void
    {
        self::assertContains('name is required', AgentDefinition::validate([])[1]);
        self::assertSame([], AgentDefinition::validate(['tone' => 'brief'], partial: true)[1]);
        self::assertContains('name is required', AgentDefinition::validate(['name' => ' '], partial: true)[1]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'long name' => [['name' => str_repeat('a', 61)]];
        yield 'long Arabic instructions' => [['name' => 'x', 'instructions_ar' => str_repeat('ب', 4001)]];
        yield 'tone' => [['name' => 'x', 'tone' => 'cheerful']];
        yield 'language' => [['name' => 'x', 'language' => 'fr']];
        yield 'slug' => [['name' => 'x', 'slug' => 'Finance Clerk']];
        yield 'unknown core toolset' => [['name' => 'x', 'toolsets' => ['core.nowhere']]];
        yield 'bare core' => [['name' => 'x', 'toolsets' => ['core']]];
        yield 'toolsets not a list' => [['name' => 'x', 'toolsets' => ['a' => 'core.me']]];
        yield 'grant mode' => [['name' => 'x', 'grant' => ['documents:issue' => 'always']]];
        yield 'grant key' => [['name' => 'x', 'grant' => ['issue documents' => 'ask']]];
        yield 'grant as list' => [['name' => 'x', 'grant' => ['ask']]];
    }

    /**
     * @dataProvider invalid
     * @param array<string, mixed> $input
     */
    public function testInvalidInputIsRefused(array $input): void
    {
        self::assertNotSame([], AgentDefinition::validate($input)[1]);
    }

    public function testCoreWildcardsAreAccepted(): void
    {
        self::assertSame([], AgentDefinition::validate(['name' => 'x', 'toolsets' => ['core.*', 'core.documents.*']])[1]);
    }

    public function testSlugsComeFromLatinNamesOnly(): void
    {
        self::assertSame('finance-clerk', AgentDefinition::slugFrom('Finance clerk'));
        self::assertSame('hr-2', AgentDefinition::slugFrom('  HR #2! '));
        self::assertSame('', AgentDefinition::slugFrom('موظف المالية'));
        self::assertTrue(AgentDefinition::isSlug(AgentDefinition::slugFrom(str_repeat('Long name ', 10))));
    }
}
