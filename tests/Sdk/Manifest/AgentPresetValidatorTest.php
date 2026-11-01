<?php

declare(strict_types=1);

namespace Tests\Sdk\Manifest;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Manifest\AgentPresetValidator;
use Whity\Sdk\Manifest\ManifestValidator;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * `agentPresets` in plugin.json (SDK 1.61, WHIT-818), including owner decision
 * Q6: a preset may start a read at Allowed, never a write.
 */
final class AgentPresetValidatorTest extends TestCase
{
    private const LABEL = ['en' => 'Collections clerk', 'ar' => 'موظف التحصيل'];

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function preset(array $overrides = []): array
    {
        return $overrides + ['name' => self::LABEL, 'role' => self::LABEL, 'instructions' => self::LABEL];
    }

    /**
     * @param array<string, mixed> $preset
     * @return list<string>
     */
    private static function errors(array $preset): array
    {
        $errors = [];
        AgentPresetValidator::check(['collections-clerk' => $preset], $errors);

        return $errors;
    }

    public function testAFullPresetIsAccepted(): void
    {
        self::assertSame([], self::errors(self::preset([
            'version'  => 2,
            'tone'     => 'formal',
            'language' => 'ar',
            'toolsets' => ['core.documents', 'elmak.*'],
            'grant'    => ['documents:read' => 'allow', 'billing:view' => 'allow', 'documents:issue' => 'ask', 'users:write' => 'off'],
        ])));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function writesStartingAllowed(): iterable
    {
        yield 'a core write' => [['documents:issue' => 'allow'], "documents:issue: can't start Allowed"];
        yield 'a plugin write' => [['elmak_invoices:send' => 'allow'], "elmak_invoices:send: can't start Allowed"];
        yield 'read in the resource only' => [['readers:manage' => 'allow'], "readers:manage: can't start Allowed"];
    }

    /**
     * @dataProvider writesStartingAllowed
     * @param array<string, string> $grant
     */
    public function testAWriteCanNeverStartAllowed(array $grant, string $message): void
    {
        self::assertStringContainsString($message, implode("\n", self::errors(self::preset(['grant' => $grant]))));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'no name' => [['role' => self::LABEL, 'instructions' => self::LABEL]];
        yield 'no Arabic' => [self::preset(['name' => ['en' => 'Clerk']])];
        yield 'long name' => [self::preset(['name' => ['en' => str_repeat('a', 61), 'ar' => 'ب']])];
        yield 'long instructions' => [self::preset(['instructions' => ['en' => str_repeat('a', 4001), 'ar' => 'ب']])];
        yield 'unknown key' => [self::preset(['persona' => 'x'])];
        yield 'tone' => [self::preset(['tone' => 'cheerful'])];
        yield 'language' => [self::preset(['language' => 'fr'])];
        yield 'version' => [self::preset(['version' => 0])];
        yield 'toolset' => [self::preset(['toolsets' => ['Not A Toolset']])];
        yield 'grant mode' => [self::preset(['grant' => ['documents:read' => 'always']])];
        yield 'grant key' => [self::preset(['grant' => ['read documents' => 'ask']])];
    }

    /**
     * @dataProvider invalid
     * @param array<string, mixed> $preset
     */
    public function testInvalidPresetsAreRefused(array $preset): void
    {
        self::assertNotSame([], self::errors($preset));
    }

    public function testKeysAndSectionShape(): void
    {
        $errors = [];
        AgentPresetValidator::check(['Bad Key' => self::preset()], $errors);
        self::assertStringContainsString('preset keys', $errors[0]);

        $errors = [];
        AgentPresetValidator::check([self::preset()], $errors);
        self::assertStringContainsString('must be an object', $errors[0]);
    }

    public function testTheManifestValidatorChecksThemAndTheManifestReadsThem(): void
    {
        $manifest = [
            'manifest' => ManifestValidator::FORMAT,
            'plugin'   => ['name' => 'Elmak', 'version' => '1.0.0', 'label' => self::LABEL],
            'requires' => ['sdk' => '^1.61'],
        ];

        $good = $manifest + ['agentPresets' => ['collections-clerk' => self::preset()]];
        self::assertSame(['ok' => true, 'errors' => []], ManifestValidator::validate($good));
        self::assertSame(['collections-clerk'], array_keys(PluginManifest::fromArray($good)->agentPresets()));

        $bad = $manifest + ['agentPresets' => ['collections-clerk' => self::preset(['grant' => ['documents:issue' => 'allow']])]];
        $result = ManifestValidator::validate($bad);
        self::assertFalse($result['ok']);
        self::assertStringContainsString("can't start Allowed", implode("\n", $result['errors']));
    }
}
