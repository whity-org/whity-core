<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

/**
 * The MCP splits: one slice of the server at its own URL, `/mcp/<split>`
 * (WHIT-765, docs/design/MCP-TOOL-SURFACE.md §5.12).
 *
 * A split is a named toolset selection served by the same server, with the same
 * auth, RBAC, audit and rate limits. Unlike `?toolsets=`, it is a hard boundary:
 * what is outside it doesn't exist there. The single `/mcp` stays the recommended
 * connection; splits are for narrow clients and hard boundaries.
 *
 * Three sources, in order of precedence: core's areas; composite splits an
 * operator names in MCP_SPLITS (`sales=core.forms,acme.*;ops=core.time`); and
 * plugins' toolsets, read each time through $pluginToolsets, since plugins load
 * after this is built. A plugin whose id collides with a reserved name is
 * served at `x-<id>`.
 */
final class McpSplits
{
    /** Core's areas: split name => the toolsets it serves. */
    public const CORE = [
        'documents' => ['core.documents.*'],
        'forms'     => ['core.forms.*'],
        'convening' => ['core.convening.*'],
        'people'    => ['core.people.*'],
        'inbox'     => ['core.inbox.*'],
        'admin'     => ['core.settings.*', 'core.taxonomy.*', 'core.time.*', 'core.plugins.*', 'core.agents.*'],
        'billing'   => ['core.billing.*'],
        'security'  => ['core.security.*'],
        'platform'  => ['core.platform.*'],
    ];

    /** `/mcp/agents/<slug>` belongs to workspace agents (WHIT-819). */
    private const RESERVED = ['agents'];

    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

    /** @var array<string, list<string>> */
    private array $composite = [];

    /**
     * @param (\Closure(): list<string>)|null $pluginToolsets the toolset ids plugins serve as splits
     */
    public function __construct(
        private readonly ?\Closure $pluginToolsets = null,
        ?string $composite = null,
    ) {
        foreach (explode(';', (string) $composite) as $entry) {
            [$name, $toolsets] = array_pad(explode('=', $entry, 2), 2, '');
            $name     = trim($name);
            $toolsets = array_values(array_filter(array_map('trim', explode(',', $toolsets)), static function (string $toolset): bool {
                return $toolset !== '' && ToolSurface::isToolsetId(str_ends_with($toolset, '.*') ? substr($toolset, 0, -2) : $toolset);
            }));
            if ($toolsets !== [] && preg_match(self::NAME_PATTERN, $name) === 1 && !isset(self::CORE[$name]) && !in_array($name, self::RESERVED, true)) {
                $this->composite[$name] = $toolsets;
            }
        }
    }

    /** @return list<string>|null the toolsets a split serves, or null when there is no such split */
    public function toolsetsOf(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    /** @return array<string, list<string>> every split, name => toolsets */
    public function all(): array
    {
        $splits = self::CORE + $this->composite;
        foreach ($this->pluginToolsets !== null ? ($this->pluginToolsets)() : [] as $id) {
            if (preg_match(self::NAME_PATTERN, $id) !== 1) {
                continue;
            }
            $name = isset($splits[$id]) || in_array($id, self::RESERVED, true) ? 'x-' . $id : $id;
            $splits[$name] ??= [$id . '.*'];
        }

        return $splits;
    }
}
