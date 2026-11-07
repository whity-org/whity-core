<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * Read the workspace's published templates and their merge fields (SDK 1.82,
 * WHIT-996). Resolve it with `\Whity\app(\Whity\Sdk\Documents\Templates::class)`.
 *
 * The host implements it; a plugin never does. It answers as {@see Documents}
 * acts: in a request, as the person, so a plugin sees exactly the templates
 * that person can see in the editor; in a job of a plugin that issues in the
 * background (SDK 1.58), only the templates bound to that plugin's own slots,
 * in workspaces that allowed it. With nobody to answer for, {@see canRead()}
 * is false, `list()` is empty and `find()` is null.
 *
 * Only published templates are listed, and what is described is the published
 * version, which is what an issue prints. A draft never shows.
 *
 *     $templates = \Whity\app(\Whity\Sdk\Documents\Templates::class);
 *     foreach ($templates->list(recordType: 'Invoicing.invoice') as $t) {
 *         $needed = array_filter($t->fields, fn ($f) => $f->source === TemplateField::SOURCE_TEMPLATE);
 *     }
 *
 * Then issue with `TemplateRef::source($t->id)` and values for the
 * `template.*` fields, or with the slot the template is bound to.
 */
interface Templates
{
    /** Whether there is someone to read for: a person, or an allowed background plugin. */
    public function canRead(): bool;

    /**
     * The published templates this caller can see, newest first, at most 200.
     *
     * @param string|null $kind       `template` or `label`
     * @param string|null $recordType Only templates for this record type, e.g. `Invoicing.invoice`
     * @return list<TemplateInfo>
     */
    public function list(?string $kind = null, ?string $recordType = null): array;

    /** A published template by id, or null when there is none this caller can see. */
    public function find(int $id): ?TemplateInfo;

    /** The template bound to a slot (`<plugin>.<slot>`), or null when unbound or not visible. */
    public function forSlot(string $slotKey): ?TemplateInfo;
}
