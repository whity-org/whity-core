<?php

declare(strict_types=1);

namespace Whity\Core\Workspace\Templates;

use Whity\Sdk\Setup\WorkspaceSetup;
use Whity\Sdk\Templates\DryRunWorkspaceSetup;
use Whity\Sdk\Templates\MemoryLedger;
use Whity\Sdk\Templates\TemplateEngine;

/**
 * Applies a workspace template (SDK 1.116, WHIT-1194) as the person who chose
 * it, through core's own routes ({@see WorkspaceSetup}, plus the template-only
 * routes of {@see \Whity\Core\Plugin\Workspace\HostWorkspaceSetup::forTemplates()}).
 *
 * Since SDK 1.169 (WHIT-1409) the rules live in the SDK's
 * {@see TemplateEngine}, so a template author's tests and the preview run the
 * same code: add what is missing, never overwrite, never delete, never bring
 * back, no more than the person holds, each part fails alone. This class
 * gives it core's routes and the database ledger ({@see TemplateRuns}), and
 * for {@see self::preview()} a dry run over both.
 */
final class TemplateApplier
{
    /**
     * @param \Closure(int, string, int): bool $holds does the person (profile, permission, tenant) hold it
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     * @param (\Closure(string, string): bool)|null $mayWrite may the person call this route (METHOD, path), for the preview
     */
    public function __construct(
        private readonly WorkspaceSetup $api,
        private readonly TemplateRuns $runs,
        private readonly \Closure $holds,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $mayWrite = null,
    ) {
    }

    /**
     * @param array<string, mixed> $template a validated manifest, with `plugin` when a plugin gave it
     * @param string|null $theme the template's theme when the person kept "Use its look" on and it is installed (SDK 1.131)
     * @return array{run: int, made: array<string, int>, skipped: list<array{part: string, key: string, reason: string}>, items: list<array{part: string, key: string, action: string, reason?: string}>}
     */
    public function apply(int $tenantId, int $profileId, array $template, bool $withExamples, ?string $theme = null): array
    {
        return (new TemplateEngine($this->api, $this->runs, $this->holds, $this->clock))
            ->apply($tenantId, $profileId, $template, $withExamples, $theme);
    }

    /**
     * What applying would do, changing nothing: reads go to the real routes
     * and ledger, writes are only recorded (WHIT-1409).
     *
     * @param array<string, mixed> $template
     * @return array{run: int, made: array<string, int>, skipped: list<array{part: string, key: string, reason: string}>, items: list<array{part: string, key: string, action: string, reason?: string}>}
     */
    public function preview(int $tenantId, int $profileId, array $template, bool $withExamples, ?string $theme = null): array
    {
        $engine = new TemplateEngine(new DryRunWorkspaceSetup($this->api, $this->mayWrite), new MemoryLedger($this->runs), $this->holds, $this->clock);

        return $engine->apply($tenantId, $profileId, $template, $withExamples, $theme);
    }

    /**
     * "Use the template's version" of one item an earlier run made (SDK 1.170,
     * WHIT-1413).
     *
     * @param array<string, mixed> $template
     * @return array{action: string, reason?: string}
     */
    public function adopt(int $tenantId, int $profileId, array $template, string $part, string $itemKey): array
    {
        return (new TemplateEngine($this->api, $this->runs, $this->holds, $this->clock))->adopt($tenantId, $profileId, $template, $part, $itemKey);
    }

    /**
     * Archive or cancel the examples still in the workspace, as the person.
     *
     * @return array{removed: int, failed: int}
     */
    public function removeExamples(int $tenantId): array
    {
        return (new TemplateEngine($this->api, $this->runs, $this->holds, $this->clock))->removeExamples($tenantId);
    }
}
