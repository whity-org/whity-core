<?php

declare(strict_types=1);

namespace Tests\Sdk\Manifest;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Manifest\AgentDeclarationValidator;
use Whity\Sdk\Manifest\AgentFeatures;

/**
 * SDK 1.64: plugin agents are scoped to the declaring plugin and to the
 * workspace that uses them. One plugin cannot change another plugin's agents,
 * core's, or a tenant's.
 */
final class AgentDeclarationValidatorTest extends TestCase
{
    private const ROOT = 'acme.collections';

    /** @return array<string, mixed> */
    private function agent(string $key, array $overrides = []): array
    {
        return array_merge([
            'key'          => $key,
            'name'         => ['en' => 'Clerk', 'ar' => 'موظف'],
            'role'         => ['en' => 'Follows up invoices.', 'ar' => 'يتابع الفواتير.'],
            'instructions' => ['en' => 'Be brief.', 'ar' => 'كن موجزا.'],
        ], $overrides);
    }

    /** @param list<string> $permissions */
    private function check(array $declared, array $permissions = ['documents:read', 'documents:issue']): array
    {
        return AgentDeclarationValidator::check(
            $declared,
            self::ROOT,
            static fn (string $p): bool => in_array($p, $permissions, true),
        );
    }

    public function testAValidDeclarationIsAcceptedUnderThePluginRoot(): void
    {
        [$accepted, $refused] = $this->check([
            $this->agent('clerk', [
                'toolsets'  => [self::ROOT, 'core.documents'],
                'grant'     => ['documents:read' => 'allow', 'documents:issue' => 'ask'],
                'features'  => [AgentFeatures::SUGGESTIONS => true, AgentFeatures::PROPOSALS => false],
            ]),
        ]);

        $this->assertSame([], $refused);
        $this->assertArrayHasKey(self::ROOT . '.clerk', $accepted);
    }

    public function testADeclarationCannotBeAddressedOutsideItsRoot(): void
    {
        [$accepted] = $this->check([$this->agent('clerk')]);

        foreach (array_keys($accepted) as $id) {
            $this->assertStringStartsWith(self::ROOT . '.', $id);
        }
    }

    public function testAnotherPluginsToolsetIsRefused(): void
    {
        [$accepted, $refused] = $this->check([
            $this->agent('clerk', ['toolsets' => ['other.plugin']]),
        ]);

        $this->assertSame([], $accepted);
        $this->assertNotEmpty($refused);
        $this->assertStringContainsString("another plugin's", implode(' ', $refused));
    }

    public function testAToolsetThatOnlyLooksLikeOwnRootIsRefused(): void
    {
        [$accepted, $refused] = $this->check([
            $this->agent('clerk', ['toolsets' => ['acme.collectionsevil']]),
        ]);

        $this->assertSame([], $accepted);
        $this->assertNotEmpty($refused);
    }

    public function testAWriteCannotStartAtAllowed(): void
    {
        [$accepted, $refused] = $this->check([
            $this->agent('clerk', ['grant' => ['documents:issue' => 'allow']]),
        ]);

        $this->assertSame([], $accepted);
        $this->assertStringContainsString('Allowed', implode(' ', $refused));
    }

    public function testAnUnknownPermissionIsRefused(): void
    {
        [$accepted, $refused] = $this->check([
            $this->agent('clerk', ['grant' => ['billing:refund' => 'ask']]),
        ]);

        $this->assertSame([], $accepted);
        $this->assertStringContainsString('does not exist', implode(' ', $refused));
    }

    public function testAnUnexposedFeatureIsRefused(): void
    {
        [$accepted, $refused] = $this->check([
            $this->agent('clerk', ['features' => ['tenant_wide_memory' => true]]),
        ]);

        $this->assertSame([], $accepted);
        $this->assertStringContainsString('not a feature the host exposes', implode(' ', $refused));
    }

    public function testAFeatureFlagMustBeABoolean(): void
    {
        [$accepted, $refused] = $this->check([
            $this->agent('clerk', ['features' => [AgentFeatures::PROPOSALS => 'yes']]),
        ]);

        $this->assertSame([], $accepted);
        $this->assertNotEmpty($refused);
    }

    public function testAKeyThatReachesPastThePluginIsRefused(): void
    {
        [$accepted, $refused] = $this->check([
            $this->agent('clerk', ['customizes' => 'core.whity']),
            $this->agent('clerk2', ['tenant' => 1]),
        ]);

        $this->assertSame([], $accepted);
        $this->assertCount(2, $refused);
    }

    public function testAnInvalidKeyIsRefusedWithoutTouchingOthers(): void
    {
        [$accepted, $refused] = $this->check([
            $this->agent('Bad.Key'),
            $this->agent('good'),
        ]);

        $this->assertArrayHasKey(self::ROOT . '.good', $accepted);
        $this->assertCount(1, $accepted);
        $this->assertCount(1, $refused);
    }

    public function testTwoPluginsWithTheSameLocalKeyDoNotCollide(): void
    {
        [$mine] = AgentDeclarationValidator::check(
            [$this->agent('clerk')],
            'acme.collections',
            static fn (): bool => true,
        );
        [$theirs] = AgentDeclarationValidator::check(
            [$this->agent('clerk')],
            'other.plugin',
            static fn (): bool => true,
        );

        $this->assertNotSame(array_keys($mine), array_keys($theirs));
        $this->assertSame(['acme.collections.clerk'], array_keys($mine));
        $this->assertSame(['other.plugin.clerk'], array_keys($theirs));
    }

    public function testATenantsCopyIsNotChangedByAnotherTenantsDeclaration(): void
    {
        // The validator keeps no state: a second call for a different plugin
        // or tenant sees none of the first call's agents.
        $first = $this->check([$this->agent('clerk')]);
        $second = AgentDeclarationValidator::check(
            [],
            'other.plugin',
            static fn (): bool => true,
        );

        $this->assertArrayHasKey(self::ROOT . '.clerk', $first[0]);
        $this->assertSame([], $second[0]);
    }

    public function testFeatureNamesAreTheHostsFixedList(): void
    {
        $this->assertSame(
            ['suggestions', 'proposals', 'reference_documents'],
            AgentFeatures::ALL,
        );
    }
}
