<?php

declare(strict_types=1);

namespace Whity\Core\Agent;

use Whity\Mcp\Tools\CoreToolsets;
use Whity\Mcp\Tools\ToolSurface;

/**
 * The fields of an agent definition and their rules (WHIT-815;
 * docs/design/AGENT-PERSONAS-AND-WHITY-AGENT.md §4.2 and §4.5), shared by the
 * workspace agents API and the preset manifests so both accept exactly the
 * same shape.
 *
 * A persona can't loosen anything: these are words for a model and a starting
 * point for a grant. Access is decided on every call by the person's RBAC, the
 * grant, `agentPolicy` and Ask first, whatever the persona says.
 */
final class AgentDefinition
{
    public const TONES     = ['plain', 'formal', 'brief'];
    public const LANGUAGES = ['auto', 'en', 'ar'];
    public const MODES     = ['off', 'ask', 'allow'];

    public const NAME_MAX         = 60;
    public const ROLE_MAX         = 200;
    public const INSTRUCTIONS_MAX = 4000;
    public const SLUG_MAX         = 48;

    /** The persona's text fields, each with its Arabic twin. */
    private const TEXT_LIMITS = [
        'name'         => self::NAME_MAX,
        'role'         => self::ROLE_MAX,
        'instructions' => self::INSTRUCTIONS_MAX,
    ];

    /**
     * Check and normalise a definition. With `$partial`, only the keys present
     * are checked (a PATCH); otherwise `name` is required and the rest default.
     *
     * @param array<mixed> $input
     * @return array{0: array<string, mixed>, 1: list<string>} the normalised fields and the errors
     */
    public static function validate(array $input, bool $partial = false): array
    {
        $out    = [];
        $errors = [];

        foreach (self::TEXT_LIMITS as $field => $max) {
            foreach ([$field, $field . '_ar'] as $key) {
                if (!array_key_exists($key, $input)) {
                    continue;
                }
                $value = $input[$key];
                if (!is_string($value)) {
                    $errors[] = "{$key} must be a string";
                    continue;
                }
                $value = trim($value);
                if (mb_strlen($value) > $max) {
                    $errors[] = "{$key} must be at most {$max} characters";
                    continue;
                }
                $out[$key] = $value;
            }
        }
        if ((!$partial || array_key_exists('name', $input)) && ($out['name'] ?? '') === '') {
            $errors[] = 'name is required';
        }

        if (array_key_exists('slug', $input)) {
            $slug = $input['slug'];
            if (!is_string($slug) || !self::isSlug($slug)) {
                $errors[] = 'slug must be lower-case letters, digits and dashes, at most ' . self::SLUG_MAX . ' characters';
            } else {
                $out['slug'] = $slug;
            }
        }

        foreach (['tone' => self::TONES, 'language' => self::LANGUAGES] as $key => $allowed) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            if (!is_string($input[$key]) || !in_array($input[$key], $allowed, true)) {
                $errors[] = "{$key} must be one of " . implode(', ', $allowed);
                continue;
            }
            $out[$key] = $input[$key];
        }

        if (array_key_exists('toolsets', $input)) {
            $toolsets = $input['toolsets'];
            if (!is_array($toolsets) || !array_is_list($toolsets)) {
                $errors[] = 'toolsets must be a list';
            } else {
                $clean = [];
                foreach ($toolsets as $toolset) {
                    $error = is_string($toolset) ? self::toolsetError($toolset) : 'toolsets must be strings';
                    if ($error !== null) {
                        $errors[] = $error;
                        continue;
                    }
                    $clean[$toolset] = true;
                }
                $out['toolsets'] = array_keys($clean);
            }
        }

        if (array_key_exists('grant', $input)) {
            $grant = $input['grant'];
            if (!is_array($grant) || ($grant !== [] && array_is_list($grant))) {
                $errors[] = 'grant must be an object of permission => off, ask or allow';
            } else {
                $clean = [];
                foreach ($grant as $permission => $mode) {
                    if (!is_string($permission) || preg_match('/^[a-z][a-z0-9_]*(:[a-z][a-z0-9_]*)+$/', $permission) !== 1) {
                        $errors[] = 'grant keys must be permissions such as documents:read';
                        continue;
                    }
                    if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
                        $errors[] = "grant for {$permission} must be off, ask or allow";
                        continue;
                    }
                    if ($mode === 'allow' && !self::isReadPermission($permission)) {
                        $errors[] = "grant for {$permission} can't start Allowed: only reads can; the person sets a write to Allowed themselves";
                        continue;
                    }
                    $clean[$permission] = $mode;
                }
                ksort($clean);
                $out['grant'] = $clean;
            }
        }

        return [$out, $errors];
    }

    /**
     * Whether a permission only reads: its action is `read` or `view`
     * (`documents:read`, `billing:view`, `documents:read:all`).
     *
     * Owner decision 2026-11-01 (Q6): a template, whether a core preset, a
     * plugin's or Whity Builder's preset, or a workspace agent, may start a
     * grant at Allowed for reads only. Writes start Ask first or Off, and only
     * the person sets one to Allowed. Judged by name, deliberately strict: a
     * read named otherwise simply starts Ask first.
     */
    public static function isReadPermission(string $permission): bool
    {
        return preg_match('/:(read|view)(:all)?$/', $permission) === 1;
    }

    public static function isSlug(string $slug): bool
    {
        return strlen($slug) <= self::SLUG_MAX && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) === 1;
    }

    /**
     * A slug from a name: lower-case ASCII letters and digits joined by dashes.
     * Arabic-only names give an empty string, and the caller falls back.
     */
    public static function slugFrom(string $name): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name));
        $slug = trim(substr(trim($slug, '-'), 0, self::SLUG_MAX), '-');

        return $slug;
    }

    /**
     * Toolsets are named the way token scope names them (MCP-TOOL-SURFACE.md
     * §5.4): an id or `prefix.*`. A core id must exist; a plugin's can't be
     * checked here, since plugins come and go, and simply reaches nothing
     * while its plugin is absent.
     */
    private static function toolsetError(string $toolset): ?string
    {
        $id = str_ends_with($toolset, '.*') ? substr($toolset, 0, -2) : $toolset;
        if (!ToolSurface::isToolsetId($id)) {
            return "toolset {$toolset} is not a toolset id";
        }
        if ($id === 'core' || str_starts_with($id, 'core.')) {
            $wildcard = $id !== $toolset;
            $known    = isset(CoreToolsets::TOOLSETS[$id])
                || ($wildcard && ($id === 'core' || array_filter(array_keys(CoreToolsets::TOOLSETS), static fn (string $k): bool => ToolSurface::isWithin($k, $id)) !== []));
            if (!$known) {
                return "toolset {$toolset} is not a core toolset";
            }
        }

        return null;
    }
}
