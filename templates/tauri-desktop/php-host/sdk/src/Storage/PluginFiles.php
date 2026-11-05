<?php

declare(strict_types=1);

namespace Whity\Sdk\Storage;

use Whity\Sdk\PluginInterface;

/**
 * Where a plugin keeps its files: uploads, generated exports, attachments
 * (SDK 1.63, WHIT-886). Resolve it with
 * `\Whity\app(\Whity\Sdk\Storage\PluginFiles::class)` in a route handler, an MCP
 * tool handler or a job, then take the plugin's own store:
 *
 *     $files = \Whity\app(PluginFiles::class)->for($this);
 *     $files->put('exports/march.csv', $csv, 'text/csv');
 *
 * The store is the plugin's own, and it is confined in two directions. It is
 * scoped to the plugin that asked for it, so one plugin never reaches another's
 * files. It is scoped to the workspace the request or job is running in, so one
 * workspace never reaches another's. Neither is a value the caller supplies.
 * The host implements this; a plugin never does.
 */
interface PluginFiles
{
    /** The store for $plugin's own files in the current workspace. */
    public function for(PluginInterface $plugin): PluginFileStore;
}
