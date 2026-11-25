<?php

declare(strict_types=1);

namespace Whity\Sdk\Templates;

use Whity\Sdk\Manifest\WorkspaceTemplateValidator;

/**
 * Builds the templates a workspace is offered from the manifests and
 * extensions of its plugins (SDK 1.170, WHIT-1413, design section 40 §2.2).
 *
 * **Extends.** A template with `extends` builds on the template with that
 * key, at most three levels deep:
 * - `services`, `roles`, `forms`, `documents`, `examples.contacts`,
 *   `examples.bookings` and `setup.steps` merge by key (steps by `id`): the
 *   parent's first, a child's item with the same key in its place, the
 *   child's new ones after. `remove` drops parent items by key.
 * - `settings` and `calendar.settings` merge key by key, the child winning.
 * - `name`, `summary`, `icon`, `setsUp`, `version` and `changes` are the
 *   child's own; `calendar.hours`, `setup.title`, `setup.description`,
 *   `offer` and `theme` are the child's when it has them, else the parent's.
 * - `hidesParent` hides the parent's card where the child is offered.
 * A child whose parent is missing, or that makes a loop or goes deeper, is
 * dropped with the reason.
 *
 * **Extensions.** Each applies to its target and to every template built on
 * it, in the order given. It only adds: an item whose key the template
 * already has is left out (and said so). An extension that would make the
 * template invalid is left out whole; the template stays.
 *
 * Every composed template is checked again with
 * {@see WorkspaceTemplateValidator::errors()} and carries `parts`: the
 * template itself (`template:{key}`), each one it builds on
 * (`extends:{key}`) and each extension applied (`extension:{plugin}.{key}`),
 * with its plugin, version and `changes`. Core records the version of each
 * part a run applied and offers an update when one is newer.
 */
final class TemplateComposer
{
    public const MAX_DEPTH = 3;

    private const BY_KEY = ['services', 'roles', 'forms', 'documents', 'pipelines'];

    private function __construct()
    {
    }

    /**
     * @param list<array<string, mixed>> $templates valid manifests, each with `plugin` when a plugin gave it, in the order offered
     * @param list<array<string, mixed>> $extensions valid extensions, each with `plugin`
     * @return array{templates: list<array<string, mixed>>, notes: list<string>} the composed templates in the same order, and what was left out and why
     */
    public static function compose(array $templates, array $extensions = []): array
    {
        $byKey = [];
        foreach ($templates as $template) {
            $byKey[(string) $template['key']] ??= $template;
        }
        $notes = [];
        $resolved = [];
        foreach ($byKey as $key => $template) {
            $result = self::resolve($byKey, $key, []);
            if (is_string($result)) {
                $notes[] = "template {$key} left out: {$result}";
                continue;
            }
            $resolved[$key] = $result;
        }

        $hidden = [];
        $out = [];
        foreach ($resolved as $key => $template) {
            foreach ($extensions as $extension) {
                if (!in_array((string) $extension['target'], self::lineage($template), true)) {
                    continue;
                }
                [$template, $note] = self::extend($template, $extension);
                array_push($notes, ...$note);
            }
            $errors = WorkspaceTemplateValidator::errors(self::bare($template));
            if ($errors !== []) {
                $notes[] = "template {$key} left out: " . implode('; ', array_slice($errors, 0, 5));
                continue;
            }
            if (($template['hidesParent'] ?? false) === true && is_string($template['extends'] ?? null)) {
                $hidden[$template['extends']] = true;
            }
            $out[$key] = $template;
        }

        $list = [];
        foreach ($out as $key => $template) {
            if (!isset($hidden[$key])) {
                unset($template['remove'], $template['hidesParent']);
                $list[] = $template;
            }
        }

        return ['templates' => $list, 'notes' => $notes];
    }

    /**
     * A part's key and version for each piece of a composed template.
     *
     * @param array<string, mixed> $template
     * @return array<string, int>
     */
    public static function partVersions(array $template): array
    {
        $out = [];
        foreach (self::parts($template) as $part) {
            $out[$part['key']] = $part['version'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $template
     * @return list<array{key: string, plugin: ?string, version: int, changes: list<array<string, mixed>>}>
     */
    public static function parts(array $template): array
    {
        if (is_array($template['parts'] ?? null)) {
            /** @var list<array{key: string, plugin: ?string, version: int, changes: list<array<string, mixed>>}> */
            return $template['parts'];
        }

        return [self::part('template:' . $template['key'], $template)];
    }

    /**
     * @param array<string, array<string, mixed>> $byKey
     * @param list<string> $seen
     * @return array<string, mixed>|string the composed template, or why it can't be
     */
    private static function resolve(array $byKey, string $key, array $seen): array|string
    {
        $template = $byKey[$key];
        if (!is_string($template['extends'] ?? null)) {
            $template['parts'] = [self::part("template:{$key}", $template)];

            return $template;
        }
        $parentKey = $template['extends'];
        if (in_array($parentKey, [...$seen, $key], true)) {
            return "it builds on {$parentKey}, which builds on it";
        }
        if (count($seen) + 2 > self::MAX_DEPTH) {
            return 'templates build on each other at most ' . self::MAX_DEPTH . ' levels deep';
        }
        if (!isset($byKey[$parentKey])) {
            return "it builds on {$parentKey}, which is not offered here";
        }
        $parent = self::resolve($byKey, $parentKey, [...$seen, $key]);
        if (is_string($parent)) {
            return $parent;
        }

        $merged = self::merge($parent, $template);
        $parentParts = array_map(
            static fn (array $part): array => str_starts_with($part['key'], 'template:') ? ['key' => 'extends:' . substr($part['key'], 9)] + $part : $part,
            self::parts($parent),
        );
        $merged['parts'] = [self::part("template:{$key}", $template), ...$parentParts];

        return $merged;
    }

    /**
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $child
     * @return array<string, mixed>
     */
    private static function merge(array $parent, array $child): array
    {
        $remove = is_array($child['remove'] ?? null) ? $child['remove'] : [];
        $out = $child;
        foreach (['settings'] as $object) {
            $merged = array_merge(is_array($parent[$object] ?? null) ? $parent[$object] : [], is_array($child[$object] ?? null) ? $child[$object] : []);
            if ($merged !== []) {
                $out[$object] = $merged;
            }
        }
        $parentCalendar = is_array($parent['calendar'] ?? null) ? $parent['calendar'] : [];
        $childCalendar = is_array($child['calendar'] ?? null) ? $child['calendar'] : [];
        $calendar = [];
        $settings = array_merge(is_array($parentCalendar['settings'] ?? null) ? $parentCalendar['settings'] : [], is_array($childCalendar['settings'] ?? null) ? $childCalendar['settings'] : []);
        if ($settings !== []) {
            $calendar['settings'] = $settings;
        }
        $hours = $childCalendar['hours'] ?? $parentCalendar['hours'] ?? null;
        if ($hours !== null) {
            $calendar['hours'] = $hours;
        }
        if ($calendar !== []) {
            $out['calendar'] = $calendar;
        }
        foreach (self::BY_KEY as $list) {
            $merged = self::mergeList(self::listOf($parent, $list), self::listOf($child, $list), 'key', self::keys($remove[$list] ?? []));
            if ($merged !== []) {
                $out[$list] = $merged;
            } else {
                unset($out[$list]);
            }
        }
        $examples = [];
        foreach (['contacts', 'bookings'] as $list) {
            $merged = self::mergeList(self::listOf($parent['examples'] ?? [], $list), self::listOf($child['examples'] ?? [], $list), 'key', []);
            if ($merged !== []) {
                $examples[$list] = $merged;
            }
        }
        if ($examples !== []) {
            $out['examples'] = $examples;
        }
        $parentSetup = is_array($parent['setup'] ?? null) ? $parent['setup'] : [];
        $childSetup = is_array($child['setup'] ?? null) ? $child['setup'] : [];
        $steps = self::mergeList(self::listOf($parentSetup, 'steps'), self::listOf($childSetup, 'steps'), 'id', self::keys($remove['steps'] ?? []));
        if ($steps !== []) {
            $out['setup'] = array_filter([
                'title' => $childSetup['title'] ?? $parentSetup['title'] ?? null,
                'description' => $childSetup['description'] ?? $parentSetup['description'] ?? null,
            ], static fn (mixed $v): bool => $v !== null) + ['steps' => $steps];
        }
        foreach (['offer', 'theme'] as $key) {
            if (!array_key_exists($key, $child) && array_key_exists($key, $parent)) {
                $out[$key] = $parent[$key];
            }
        }

        return $out;
    }

    /**
     * Add an extension's pieces to a composed template.
     *
     * @param array<string, mixed> $template
     * @param array<string, mixed> $extension
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function extend(array $template, array $extension): array
    {
        $name = (string) ($extension['plugin'] ?? '') . '.' . (string) $extension['key'];
        $notes = [];
        $out = $template;
        foreach (self::BY_KEY as $list) {
            $out[$list] = self::addOnly(self::listOf($template, $list), self::listOf($extension, $list), 'key', "{$name} {$list}", $notes);
            if ($out[$list] === []) {
                unset($out[$list]);
            }
        }
        foreach (['contacts', 'bookings'] as $list) {
            $added = self::addOnly(self::listOf($template['examples'] ?? [], $list), self::listOf($extension['examples'] ?? [], $list), 'key', "{$name} examples", $notes);
            if ($added !== []) {
                $out['examples'][$list] = $added;
            }
        }
        $steps = self::listOf($extension['setup'] ?? [], 'steps');
        if ($steps !== []) {
            $setup = is_array($template['setup'] ?? null) ? $template['setup'] : [];
            $setup['steps'] = self::addOnly(self::listOf($setup, 'steps'), $steps, 'id', "{$name} steps", $notes);
            $out['setup'] = $setup;
        }
        $setsUp = self::listOf($extension, 'setsUp');
        if ($setsUp !== []) {
            $out['setsUp'] = [...self::listOf($template, 'setsUp'), ...$setsUp];
        }
        $out['parts'] = [...self::parts($template), self::part("extension:{$name}", $extension)];

        $errors = WorkspaceTemplateValidator::errors(self::bare($out));
        if ($errors !== []) {
            return [$template, ["extension {$name} left out of {$template['key']}: " . implode('; ', array_slice($errors, 0, 3))]];
        }

        return [$out, $notes];
    }

    /**
     * The template as the validator reads it: without what core adds.
     *
     * @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    private static function bare(array $template): array
    {
        unset($template['parts'], $template['plugin'], $template['extends'], $template['remove'], $template['hidesParent']);

        return $template;
    }

    /**
     * The template's key and every key it builds on.
     *
     * @param array<string, mixed> $template
     * @return list<string>
     */
    private static function lineage(array $template): array
    {
        $out = [];
        foreach (self::parts($template) as $part) {
            if (str_starts_with($part['key'], 'template:') || str_starts_with($part['key'], 'extends:')) {
                $out[] = substr($part['key'], strpos($part['key'], ':') + 1);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $from
     * @return array{key: string, plugin: ?string, version: int, changes: list<array<string, mixed>>}
     */
    private static function part(string $key, array $from): array
    {
        $changes = is_array($from['changes'] ?? null) ? array_values(array_filter($from['changes'], 'is_array')) : [];

        return [
            'key' => $key,
            'plugin' => is_string($from['plugin'] ?? null) ? $from['plugin'] : null,
            'version' => (int) ($from['version'] ?? 1),
            'changes' => $changes,
        ];
    }

    /**
     * @param list<array<string, mixed>> $parent
     * @param list<array<string, mixed>> $child
     * @param list<string> $remove
     * @return list<array<string, mixed>>
     */
    private static function mergeList(array $parent, array $child, string $field, array $remove): array
    {
        $childByKey = [];
        foreach ($child as $item) {
            $childByKey[(string) ($item[$field] ?? '')] = $item;
        }
        $out = [];
        foreach ($parent as $item) {
            $key = (string) ($item[$field] ?? '');
            if (in_array($key, $remove, true)) {
                continue;
            }
            $out[] = $childByKey[$key] ?? $item;
            unset($childByKey[$key]);
        }
        foreach ($child as $item) {
            if (isset($childByKey[(string) ($item[$field] ?? '')])) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $have
     * @param list<array<string, mixed>> $add
     * @param list<string> $notes
     * @return list<array<string, mixed>>
     */
    private static function addOnly(array $have, array $add, string $field, string $what, array &$notes): array
    {
        $keys = array_map(static fn (array $item): string => (string) ($item[$field] ?? ''), $have);
        foreach ($add as $item) {
            $key = (string) ($item[$field] ?? '');
            if (in_array($key, $keys, true)) {
                $notes[] = "{$what}: {$key} left out, the template already has it";
                continue;
            }
            $have[] = $item;
            $keys[] = $key;
        }

        return $have;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $from, string $key): array
    {
        $list = is_array($from) ? ($from[$key] ?? []) : [];

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** @return list<string> */
    private static function keys(mixed $list): array
    {
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
