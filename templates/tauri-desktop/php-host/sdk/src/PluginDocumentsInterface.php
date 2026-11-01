<?php

declare(strict_types=1);

namespace Whity\Sdk;

use Whity\Sdk\Documents\DocumentFieldProvider;
use Whity\Sdk\Documents\TemplateSlot;

/**
 * A plugin's part in documents (SDK 1.56, WHIT-742, DOCUMENT-AUTOMATION.md
 * 3.9): the slots it needs templates for, and its record types as merge
 * fields. Optional: a plugin that issues no documents implements nothing.
 *
 * Declarations, read once at load. A bad slot or provider is dropped with a
 * logged reason and the plugin keeps loading. Issue through
 * {@see Documents\Documents}.
 */
interface PluginDocumentsInterface
{
    /** @return list<TemplateSlot> */
    public function getTemplateSlots(): array;

    /** @return list<DocumentFieldProvider> */
    public function getDocumentFieldProviders(): array;

    /**
     * Whether the plugin issues documents with no person to act for, from its
     * jobs (SDK 1.58). Off in every workspace until a holder of
     * documents:publish allows it; then only from the plugin's own slots,
     * with only the permissions the plugin declares.
     */
    public function issuesInBackground(): bool;
}
