<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

use Whity\Mcp\Auth\AgentScopeGrant;

/**
 * An MCP tool in plain words (WHIT-867, design 2.9 S6; AI.4,
 * docs/design/components/TplWhatAICanDo): its name in English and Arabic, the
 * area it belongs to, how agents may use it, and how long Undo lasts.
 *
 * Names are the operation's own summary. `database/mcp-tool-names.json` holds
 * the Arabic for core's, and an English wording where the summary isn't plain.
 * A tool with no Arabic there, such as a plugin's, shows its English name:
 * better than a guess, and the catalogue test keeps core's list from drifting.
 *
 * Read-only per worker: the file doesn't change while a worker lives.
 */
final class ToolCatalogue
{
    public const CAN      = 'can';
    public const PROPOSES = 'proposes';
    public const SUGGESTS = 'suggests';
    public const NEVER    = 'never';

    /** @var array<string, array{en?: string, ar?: string}>|null */
    private static ?array $names = null;

    /**
     * @param array<string, mixed> $tool the tool object, with its `_meta` and `annotations`
     * @return array{plainName: string, plainNameAr: ?string, area: array{id: string, title: string, titleAr: ?string}, agentAccess: string, readOnly: bool, undoWindowSeconds: ?int}
     */
    public static function describe(array $tool, ?string $permission = null): array
    {
        $meta        = is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [];
        $name        = (string) ($tool['name'] ?? '');
        $window      = $meta[ToolSurface::META_UNDO_WINDOW] ?? null;
        $window      = is_int($window) && $window > 0 ? $window : null;
        $readOnly    = ToolSurface::readsOnly($tool);
        $policy      = $meta[ToolSurface::META_AGENT_POLICY] ?? null;
        $names       = self::names()[$name] ?? [];

        return [
            'plainName'         => $names['en'] ?? self::firstSentence((string) ($tool['description'] ?? $name)),
            'plainNameAr'       => $names['ar'] ?? null,
            'area'              => self::area((string) ($meta[ToolSurface::META_TOOLSET] ?? '')),
            'agentAccess'       => self::agentAccess($readOnly, is_string($policy) ? $policy : ToolSurface::AGENT_ALLOW, $window !== null, $permission),
            'readOnly'          => $readOnly,
            'undoWindowSeconds' => $window,
        ];
    }

    /**
     * The most an agent can ever do with an operation, whatever a person grants:
     * the same ceilings {@see AgentScopeGrant::effectiveMode()} applies on every
     * call, asked for the highest grant, so this page and the server cannot
     * disagree. A write that can't be undone reads as proposing, not as doing.
     */
    public static function agentAccess(bool $readOnly, string $agentPolicy, bool $undoable, ?string $permission = null): string
    {
        $permission = $permission !== null && $permission !== '' ? $permission : 'any:action';
        $mode       = AgentScopeGrant::fromScope([AgentScopeGrant::PREFIX . $permission . '=' . AgentScopeGrant::ALLOW])
            ->effectiveMode($permission, $readOnly, $agentPolicy, $undoable);

        return match ($mode) {
            AgentScopeGrant::ALLOW => self::CAN,
            AgentScopeGrant::OFF   => self::NEVER,
            default                => $agentPolicy === ToolSurface::AGENT_SUGGEST_ONLY ? self::SUGGESTS : self::PROPOSES,
        };
    }

    /**
     * A core toolset's title, or a plugin's name made readable from its id.
     *
     * @return array{id: string, title: string, titleAr: ?string}
     */
    public static function area(string $toolset): array
    {
        $core = CoreToolsets::TOOLSETS[$toolset] ?? null;
        if ($core !== null) {
            return ['id' => $toolset, 'title' => $core['title'], 'titleAr' => $core['titleAr']];
        }
        $root = explode('.', $toolset)[0];

        return ['id' => $toolset, 'title' => ucfirst(str_replace(['-', '_'], ' ', $root)), 'titleAr' => null];
    }

    private static function firstSentence(string $text): string
    {
        $text = trim($text);
        $cut  = preg_match('/^(.+?[.!?])\s/u', $text, $m) === 1 ? $m[1] : $text;

        return rtrim($cut, '.');
    }

    /** @return array<string, array{en?: string, ar?: string}> */
    private static function names(): array
    {
        if (self::$names === null) {
            $raw         = @file_get_contents(dirname(__DIR__, 3) . '/database/mcp-tool-names.json');
            $decoded     = is_string($raw) ? json_decode($raw, true) : null;
            self::$names = is_array($decoded) ? $decoded : [];
        }

        return self::$names;
    }
}
