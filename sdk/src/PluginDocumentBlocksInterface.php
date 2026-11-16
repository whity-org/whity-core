<?php

declare(strict_types=1);

namespace Whity\Sdk;

use Whity\Sdk\Documents\DocumentBlock;

/**
 * Blocks a code plugin adds to the document editor (SDK 1.103; editor M7,
 * E.17–E.20). Optional. Each is a declaration Whity's own renderer draws
 * with Whity's components (register E.17: publishers' JavaScript never runs
 * in the app), offered in the / menu under the plugin's name.
 *
 * Declarations, read once at load. A bad one can't be built (the
 * {@see DocumentBlock} constructor throws); a block whose name another of the
 * plugin's blocks already uses is dropped with a logged reason, and the
 * plugin keeps loading. Workspace plugins declare the same shape under
 * `documents.blocks` in their manifest.
 */
interface PluginDocumentBlocksInterface
{
    /** @return list<DocumentBlock> */
    public function getDocumentBlocks(): array;
}
