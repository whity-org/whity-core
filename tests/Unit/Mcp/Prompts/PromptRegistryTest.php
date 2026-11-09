<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Prompts;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\Prompts\Prompt;
use Whity\Mcp\Prompts\PromptRegistry;

/**
 * TDD tests for PromptRegistry (WC-7755fc38).
 */
final class PromptRegistryTest extends TestCase
{
    private PromptRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new PromptRegistry();
    }

    public function testAll_returnsEmpty_whenNothingRegistered(): void
    {
        self::assertSame([], $this->registry->all());
    }

    public function testRegister_addsPromptToList(): void
    {
        $prompt = new Prompt('test-prompt', 'A test prompt');
        $this->registry->register($prompt);

        self::assertCount(1, $this->registry->all());
        self::assertSame($prompt, $this->registry->all()[0]);
    }

    public function testAll_returnsAllRegisteredPrompts(): void
    {
        $a = new Prompt('prompt-a', 'A');
        $b = new Prompt('prompt-b', 'B');
        $this->registry->register($a);
        $this->registry->register($b);

        self::assertCount(2, $this->registry->all());
    }

    public function testFind_returnsPrompt_whenNameMatches(): void
    {
        $prompt = new Prompt('my-prompt', 'My prompt');
        $this->registry->register($prompt);

        self::assertSame($prompt, $this->registry->find('my-prompt'));
    }

    public function testFind_returnsNull_whenNameNotFound(): void
    {
        $this->registry->register(new Prompt('real-prompt', 'Real'));

        self::assertNull($this->registry->find('nonexistent'));
    }

    public function testFind_returnsFirstMatch_whenDuplicateNamesRegistered(): void
    {
        $first  = new Prompt('dup', 'First');
        $second = new Prompt('dup', 'Second');
        $this->registry->register($first);
        $this->registry->register($second);

        self::assertSame($first, $this->registry->find('dup'));
    }

    public function testAPluginGateHidesThatPluginsPromptsAtEveryCall(): void
    {
        $this->registry->register(new Prompt(name: 'core-one', description: 'core'));
        $this->registry->register(new Prompt(name: 'crm-one', description: 'crm', plugin: 'Crm'));
        $this->registry->register(new Prompt(name: 'hand-one', description: 'hand', plugin: 'Hand'));

        $verified = ['Crm' => true];
        $this->registry->setPluginGate(static function (string $plugin) use (&$verified): bool {
            return $verified[$plugin] ?? false;
        });

        self::assertSame(['core-one', 'crm-one'], array_map(static fn (Prompt $p): string => $p->name, $this->registry->all()));
        self::assertNull($this->registry->find('hand-one'));
        self::assertTrue($this->registry->has('hand-one'), 'a hidden prompt keeps its name');

        // A revoked mark hides the prompts at the next call, with no reload.
        $verified['Crm'] = false;
        self::assertSame(['core-one'], array_map(static fn (Prompt $p): string => $p->name, $this->registry->all()));
        self::assertNull($this->registry->find('crm-one'));
        self::assertNotNull($this->registry->find('core-one'), 'core prompts are never gated');
    }

    public function testAGateThatThrowsHidesThePlugin(): void
    {
        $this->registry->register(new Prompt(name: 'crm-one', description: 'crm', plugin: 'Crm'));
        $this->registry->setPluginGate(static function (string $plugin): bool {
            throw new \RuntimeException('settings unavailable');
        });

        self::assertSame([], $this->registry->all());
        self::assertNull($this->registry->find('crm-one'));

        $this->registry->setPluginGate(null);
        self::assertNotNull($this->registry->find('crm-one'), 'no gate serves everything, as before');
    }
}
