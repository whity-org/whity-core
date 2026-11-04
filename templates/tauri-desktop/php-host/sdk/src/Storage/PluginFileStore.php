<?php

declare(strict_types=1);

namespace Whity\Sdk\Storage;

/**
 * One plugin's files in the current workspace (SDK 1.63, WHIT-886). Get one
 * from {@see PluginFiles::for()}.
 *
 * Paths are relative to the plugin's own space: `exports/march.csv`, never a
 * full storage key. Each segment is a plain name (a letter or digit first, then
 * letters, digits, dot, dash, underscore or space). A path with `..`, a leading
 * slash, a backslash or an empty segment is refused with
 * {@see \InvalidArgumentException} rather than quietly rewritten.
 *
 * Calling with no workspace in scope throws {@see \LogicException}.
 */
interface PluginFileStore
{
    /**
     * Store $contents at $path, replacing any file already there.
     *
     * @throws \InvalidArgumentException for a path that is not a plain relative path
     * @throws \LogicException           when there is no workspace in scope
     */
    public function put(string $path, string $contents, string $contentType = 'application/octet-stream'): void;

    /**
     * The file at $path, or null when there is none.
     *
     * @throws \InvalidArgumentException for a path that is not a plain relative path
     */
    public function get(string $path): ?PluginFile;

    /**
     * Whether a file exists at $path.
     *
     * @throws \InvalidArgumentException for a path that is not a plain relative path
     */
    public function exists(string $path): bool;

    /**
     * Every file path under $prefix (empty for all), relative to the plugin's
     * space, in ascending order. Only this plugin's files in this workspace are
     * ever listed.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException for a prefix that is not a plain relative path
     */
    public function list(string $prefix = ''): array;

    /**
     * Remove the file at $path; nothing happens when there is none.
     *
     * @throws \InvalidArgumentException for a path that is not a plain relative path
     */
    public function delete(string $path): void;
}
