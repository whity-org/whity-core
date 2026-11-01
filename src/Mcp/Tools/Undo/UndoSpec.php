<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools\Undo;

/**
 * How an operation is undone (WHIT-812; register 7.5c; MCP-TOOL-SURFACE §5.11).
 *
 * Declared with the `undo` key, on a route schema's `mcp` options or an
 * authored tool's descriptor:
 *
 *     'undo' => [
 *         'windowSeconds' => 1800,
 *         // The operation that reverses it, and its arguments.
 *         'operation' => 'patch_api_tags_id',
 *         'arguments' => ['id' => '/arguments/id', 'name' => '/before/data/name'],
 *         // Optional: a read of what it changes, taken before and after the
 *         // call. `/before/...` pointers resolve against the first; undo is
 *         // refused when the values they point at changed since.
 *         'read' => ['operation' => 'get_api_tags_id', 'arguments' => ['id' => '/arguments/id']],
 *     ]
 *
 * An argument value that is a string starting with `/` is a JSON Pointer
 * (RFC 6901) into `{arguments, result, before}`: what the agent sent, what the
 * operation answered (its JSON body), and the read before it ran. Anything
 * else is used as it is. An authored tool may give `'handler' => callable`
 * instead of `operation`: it is called with (result, arguments, tenantId).
 *
 * Immutable and stateless: worker-safe.
 */
final class UndoSpec
{
    /**
     * @param array<string, mixed> $arguments     the inverse's argument template
     * @param array<string, mixed> $readArguments the read's argument template
     */
    private function __construct(
        public readonly int $windowSeconds,
        public readonly ?string $operation,
        public readonly array $arguments,
        public readonly ?string $readOperation,
        public readonly array $readArguments,
        public readonly bool $hasHandler,
    ) {
    }

    /**
     * The undo a route schema declares, or null when it declares none or a
     * malformed one (the catalogue test refuses those, so production never
     * sees one).
     *
     * @param array<string, mixed> $schema
     */
    public static function fromRouteSchema(array $schema): ?self
    {
        $mcp = $schema['mcp'] ?? null;
        return is_array($mcp) ? self::parse($mcp['undo'] ?? null, false) : null;
    }

    /**
     * The undo an authored tool's descriptor declares, as the registry kept it.
     *
     * @param array<string, mixed>|null $undo
     */
    public static function fromAuthored(?array $undo): ?self
    {
        return self::parse($undo, true);
    }

    /** Why a declared undo is malformed, or null when it is fine (or absent). */
    public static function problem(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        return self::parse($raw, false) === null
            ? "use ['windowSeconds' => int > 0, 'operation' => string, 'arguments' => array, 'read' => ['operation' => string, 'arguments' => array]?]"
            : null;
    }

    private static function parse(mixed $raw, bool $authored): ?self
    {
        if (!is_array($raw) || !is_int($raw['windowSeconds'] ?? null) || $raw['windowSeconds'] <= 0) {
            return null;
        }
        $operation = is_string($raw['operation'] ?? null) && $raw['operation'] !== '' ? $raw['operation'] : null;
        $handler   = $authored && is_callable($raw['handler'] ?? null);
        if ($operation === null && !$handler) {
            return null;
        }
        $arguments = $raw['arguments'] ?? [];
        if (!is_array($arguments)) {
            return null;
        }
        $read = $raw['read'] ?? null;
        if ($read !== null && (!is_array($read) || !is_string($read['operation'] ?? null) || !is_array($read['arguments'] ?? []))) {
            return null;
        }

        return new self(
            $raw['windowSeconds'],
            $operation,
            $arguments,
            is_array($read) ? (string) $read['operation'] : null,
            is_array($read) && is_array($read['arguments'] ?? null) ? $read['arguments'] : [],
            $handler,
        );
    }

    /**
     * $template with every pointer resolved against $document. Null when a
     * pointer names something that is not there: an inverse that can't be
     * built is not offered.
     *
     * @param array<string, mixed> $template
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    public static function resolve(array $template, array $document): ?array
    {
        $out = [];
        foreach ($template as $key => $value) {
            if (is_string($value) && str_starts_with($value, '/')) {
                [$found, $resolved] = self::pointer($document, $value);
                if (!$found) {
                    return null;
                }
                $out[$key] = $resolved;
            } elseif (is_array($value)) {
                $nested = self::resolve($value, $document);
                if ($nested === null) {
                    return null;
                }
                $out[$key] = $nested;
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * The value at an RFC 6901 pointer, and whether it was there at all (a
     * null value is there).
     *
     * @param array<string, mixed> $document
     * @return array{0: bool, 1: mixed}
     */
    public static function pointer(array $document, string $pointer): array
    {
        $current = $document;
        foreach (array_slice(explode('/', $pointer), 1) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return [false, null];
            }
            $current = $current[$segment];
        }
        return [true, $current];
    }

    /**
     * The places in the read the inverse restores from (`/before/...`
     * pointers, as paths into the read). Undo compares them now with what the
     * call left, to refuse undoing over someone else's later change.
     *
     * @return list<string>
     */
    public function watchedPaths(): array
    {
        $paths     = [];
        $arguments = $this->arguments;
        array_walk_recursive($arguments, static function (mixed $value) use (&$paths): void {
            if (is_string($value) && str_starts_with($value, '/before/')) {
                $paths[] = substr($value, strlen('/before'));
            }
        });
        return array_values(array_unique($paths));
    }
}
