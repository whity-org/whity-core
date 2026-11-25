<?php

declare(strict_types=1);

namespace Whity\Sdk\Templates;

/**
 * What applying workspace templates made, per workspace (SDK 1.169,
 * WHIT-1409): the record {@see TemplateEngine} reads to add only what is
 * missing, and writes as it goes.
 *
 * Core keeps it in the database (migrations 305 and 358). {@see MemoryLedger}
 * keeps it in memory, for a dry run (on top of the real one, so nothing is
 * saved) and for a template author's tests.
 *
 * Items are keyed by kind (`service`, `role`, `form`, `document`, `contact`,
 * `booking`) and the template's key for them. An item stays in the ledger
 * when the person later deletes what it made, which is how an upgrade knows
 * not to make it again.
 */
interface TemplateLedger
{
    /**
     * The workspace's run of this template, or null when it never applied it.
     *
     * @return array{id: int, version: int}|null at least these two keys
     */
    public function find(int $tenantId, string $templateKey): ?array;

    /** The run for this template, made now or brought up to this version; its id. */
    public function start(int $tenantId, string $templateKey, ?string $pluginName, int $version, int $profileId): int;

    /**
     * What a run made, by `kind:key`.
     *
     * @return array<string, array{ref_id: string, removed: bool}>
     */
    public function items(int $tenantId, int $runId): array;

    public function record(int $tenantId, int $runId, string $kind, string $itemKey, string $refId, bool $isExample): void;

    /**
     * The examples still in the workspace, newest first.
     *
     * @return list<array{id: int, kind: string, ref_id: string}>
     */
    public function liveExamples(int $tenantId): array;

    public function markRemoved(int $tenantId, int $itemId): void;

    /**
     * Record that a run applied one part at a version: the template itself is
     * `template:{key}`; later minors add the templates it builds on and the
     * extensions applied with it.
     */
    public function recordPart(int $tenantId, int $runId, string $partKey, ?string $pluginName, int $version): void;

    /**
     * The parts a run applied and the version of each it applied last.
     *
     * @return array<string, int> part key => version
     */
    public function parts(int $tenantId, int $runId): array;
}
