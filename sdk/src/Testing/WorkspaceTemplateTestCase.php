<?php

declare(strict_types=1);

namespace Whity\Sdk\Testing;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Manifest\WorkspaceTemplateValidator;
use Whity\Sdk\Templates\MemoryLedger;
use Whity\Sdk\Templates\TemplateComposer;
use Whity\Sdk\Templates\TemplateEngine;

/**
 * The test kit for workspace template authors (SDK 1.169, WHIT-1409).
 * Extend it in a plugin's test suite (needs phpunit/phpunit):
 *
 * ```php
 * final class ClinicTemplateTest extends WorkspaceTemplateTestCase
 * {
 *     public function testTheTemplate(): void
 *     {
 *         $clinic = json_decode(file_get_contents(__DIR__ . '/../templates/clinic.json'), true);
 *         $this->assertTemplateValid($clinic);
 *         $this->assertIdempotent($clinic);
 *         $this->assertUpgradeKeepsData($this->previous, $clinic);
 *     }
 * }
 * ```
 *
 * Every helper applies the template through {@see TemplateEngine}, the same
 * engine core runs, against a {@see FakeWorkspace} and a {@see MemoryLedger}.
 */
abstract class WorkspaceTemplateTestCase extends TestCase
{
    /** The person applying, in every helper. */
    protected const PROFILE = 1;

    /** The workspace, in every helper. */
    protected const WORKSPACE = 1;

    /** The clock every helper applies at, so example bookings land on fixed days. */
    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-12-01T09:00:00+00:00');
    }

    /** A fresh workspace: USD, English, UTC; override for another start. */
    protected function workspace(): FakeWorkspace
    {
        return new FakeWorkspace();
    }

    /**
     * The manifest passes core's check.
     *
     * @param array<string, mixed> $template
     */
    protected function assertTemplateValid(array $template): void
    {
        self::assertSame([], WorkspaceTemplateValidator::errors($template), 'Template ' . (string) ($template['key'] ?? '?') . ' fails core\'s check');
    }

    /**
     * Apply a template as core would.
     *
     * @param array<string, mixed> $template
     * @return array{run: int, made: array<string, int>, skipped: list<array{part: string, key: string, reason: string}>, items: list<array{part: string, key: string, action: string, reason?: string}>}
     */
    protected function applyTemplate(array $template, ?FakeWorkspace $workspace = null, ?MemoryLedger $ledger = null, bool $examples = true): array
    {
        $workspace ??= $this->workspace();
        $engine = new TemplateEngine(
            $workspace,
            $ledger ?? new MemoryLedger(),
            static fn (int $profileId, string $permission, int $tenantId): bool => $workspace->holds($permission),
            fn (): \DateTimeImmutable => $this->now(),
        );

        return $engine->apply(self::WORKSPACE, self::PROFILE, $template, $examples);
    }

    /**
     * Applying the template twice makes nothing the second time and leaves
     * the workspace as the first time did.
     *
     * @param array<string, mixed> $template
     */
    protected function assertIdempotent(array $template): void
    {
        $workspace = $this->workspace();
        $ledger = new MemoryLedger();
        $first = $this->applyTemplate($template, $workspace, $ledger);
        self::assertSame([], self::failed($first), 'The first apply could not do everything');
        $after = $workspace->snapshot();

        $second = $this->applyTemplate($template, $workspace, $ledger);
        self::assertSame([], $second['made'], 'Applying again made more');
        self::assertEquals($after, $workspace->snapshot(), 'Applying again changed the workspace');
    }

    /**
     * Upgrading from `$old` to `$new` after the person changed things deletes
     * nothing, overwrites nothing and brings nothing back.
     *
     * The person's changes are `$edit`; by default the first service the old
     * version made is deleted and every other one renamed.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @param (callable(FakeWorkspace): void)|null $edit
     */
    protected function assertUpgradeKeepsData(array $old, array $new, ?callable $edit = null): void
    {
        self::assertSame($old['key'], $new['key'], 'An upgrade keeps the template key');
        self::assertGreaterThan((int) $old['version'], (int) $new['version'], 'A new version has a higher number');

        $workspace = $this->workspace();
        $ledger = new MemoryLedger();
        $this->applyTemplate($old, $workspace, $ledger);
        $edit ??= static function (FakeWorkspace $w): void {
            $first = true;
            foreach (array_keys($w->services) as $id) {
                if ($first) {
                    unset($w->services[$id]);
                    $first = false;
                } else {
                    $w->services[$id]['name'] = ['en' => 'Renamed by the owner ' . $id, 'ar' => 'غيّره المالك ' . $id];
                }
            }
        };
        $edit($workspace);
        $before = $workspace->snapshot();
        $overridden = $workspace->overridden;
        $deletedServices = count($this->madeServiceKeys($old)) - count($before['services']);

        $this->applyTemplate($new, $workspace, $ledger);
        $after = $workspace->snapshot();

        foreach (['services', 'roles', 'forms', 'documents', 'routes', 'contacts', 'bookings'] as $kind) {
            foreach ($before[$kind] as $id => $row) {
                self::assertArrayHasKey($id, $after[$kind], "The upgrade deleted {$kind} #{$id}");
                self::assertEquals($row, $after[$kind][$id], "The upgrade overwrote {$kind} #{$id}");
            }
        }
        foreach ($before['settings'] as $name => $value) {
            if (in_array($name, $overridden, true)) {
                self::assertEquals($value, $after['settings'][$name], "The upgrade overwrote the setting {$name}");
            }
        }
        $newServices = count($after['services']) - count($before['services']);
        $expected = count(array_diff($this->madeServiceKeys($new), $this->madeServiceKeys($old)));
        self::assertSame($expected, $newServices, $deletedServices > 0
            ? 'The upgrade should add only the services new in this version, not bring back deleted ones'
            : 'The upgrade should add exactly the services new in this version');
    }

    /**
     * Compose templates and extensions as core does before offering them
     * (SDK 1.170), and return the one with this key.
     *
     * @param list<array<string, mixed>> $templates
     * @param list<array<string, mixed>> $extensions each with `plugin`
     * @return array<string, mixed>
     */
    protected function composed(string $key, array $templates, array $extensions = []): array
    {
        $result = TemplateComposer::compose($templates, $extensions);
        foreach ($result['templates'] as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }
        self::fail("Template {$key} is not offered: " . implode('; ', $result['notes']));
    }

    /**
     * A template built on another (`extends`) composes into a valid
     * template that applies cleanly and twice safely.
     *
     * @param array<string, mixed> $child
     * @param array<string, mixed> $parent
     */
    protected function assertExtends(array $child, array $parent): void
    {
        self::assertSame($parent['key'], $child['extends'] ?? null, 'The child names its parent in extends');
        $this->assertTemplateValid($child);
        $this->assertTemplateValid($parent);
        $composed = $this->composed((string) $child['key'], [$parent, $child]);
        self::assertContains('extends:' . $parent['key'], array_column(TemplateComposer::parts($composed), 'key'));
        $this->assertIdempotent($composed);
    }

    /**
     * An extension is valid, is added to its target whole (nothing of it left
     * out), and the result applies cleanly and twice safely.
     *
     * @param array<string, mixed> $extension
     * @param array<string, mixed> $target
     */
    protected function assertExtension(array $extension, array $target, string $plugin = 'MyPlugin'): void
    {
        self::assertSame([], WorkspaceTemplateValidator::extensionErrors($extension), 'The extension fails core\'s check');
        self::assertSame($target['key'], $extension['target'] ?? null, 'The extension names its target');
        $result = TemplateComposer::compose([$target], [$extension + ['plugin' => $plugin]]);
        self::assertSame([], $result['notes'], 'Part of the extension was left out');
        $composed = $this->composed((string) $target['key'], [$target], [$extension + ['plugin' => $plugin]]);
        self::assertContains("extension:{$plugin}.{$extension['key']}", array_column(TemplateComposer::parts($composed), 'key'));
        $this->assertIdempotent($composed);
    }

    /**
     * The services a version makes when applied (ticked ones).
     *
     * @param array<string, mixed> $template
     * @return list<string>
     */
    private function madeServiceKeys(array $template): array
    {
        $out = [];
        foreach (is_array($template['services'] ?? null) ? $template['services'] : [] as $service) {
            if (is_array($service) && ($service['ticked'] ?? true) !== false) {
                $out[] = (string) $service['key'];
            }
        }

        return $out;
    }

    /**
     * @param array{items: list<array{part: string, key: string, action: string, reason?: string}>} $result
     * @return list<string>
     */
    private static function failed(array $result): array
    {
        $out = [];
        foreach ($result['items'] as $item) {
            if ($item['action'] === TemplateEngine::SKIP) {
                $out[] = "{$item['part']} {$item['key']}: " . ($item['reason'] ?? '');
            }
        }

        return $out;
    }
}
