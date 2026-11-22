<?php

declare(strict_types=1);

namespace Whity\Core\Import;

/**
 * One thing a workspace can import (register IM.3, design section 34 §6.2),
 * as the shared import screen needs it (§6.5, WHIT-1326): its name, who may
 * use it, where its endpoints are, what its columns are called in both
 * languages, which must be matched, and the choices it asks.
 *
 * Every kind's endpoints follow the one engine's shape under {@see $path}:
 * `GET` recent, `POST` upload, `GET /{id}`, `POST /{id}/check`,
 * `POST /{id}/run`, `POST /{id}/undo` and `GET /{id}/skipped.csv`. The
 * screen calls them by that shape and knows nothing else about a kind.
 */
final class ImportKind
{
    /**
     * @param array{en: string, ar: string} $label "Items"
     * @param array{en: string, ar: string} $description one sentence under the title
     * @param list<string> $permissions every one is needed (IM.7)
     * @param string $path the endpoints' base, versioned (`/api/v1/...`)
     * @param string $area the list page it is opened from (`contacts`, `inventory`)
     * @param array{href: string, label: array{en: string, ar: string}} $back where Done goes
     * @param array<string, array{en: string, ar: string}> $targets target key => its name
     * @param list<string> $required targets the matching must include
     * @param list<ImportOption> $options
     * @param array{title: array{en: string, ar: string}, create: array{en: string, ar: string}, update: array{en: string, ar: string}, run: array{en: string, ar: string}} $words
     *        "Import items", "New items", "Updated", "Import {count} items"
     * @param array<string, array{en: string, ar: string}> $targetHints target key => a short note the screen shows when it is chosen
     */
    public function __construct(
        public readonly string $key,
        public readonly array $label,
        public readonly array $description,
        public readonly array $permissions,
        public readonly string $path,
        public readonly string $area,
        public readonly array $back,
        public readonly array $targets,
        public readonly array $required,
        public readonly array $options,
        public readonly array $words,
        public readonly array $targetHints = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/', $key) !== 1) {
            throw new \LogicException('An import kind\'s key is lower case words joined by dots');
        }
        if (!str_starts_with($path, '/api/v1/')) {
            throw new \LogicException('An import kind\'s path starts with /api/v1/');
        }
        foreach ($required as $target) {
            if (!isset($targets[$target])) {
                throw new \LogicException('Import kind ' . $key . ' requires ' . $target . ', which is not one of its targets');
            }
        }
    }

    /** @return array<string, mixed> the API shape for this workspace */
    public function toApi(int $tenantId): array
    {
        $targets = [];
        foreach ($this->targets as $key => $label) {
            $targets[] = ['key' => $key, 'label' => $label, 'hint' => $this->targetHints[$key] ?? null];
        }

        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'path' => $this->path,
            'area' => $this->area,
            'back' => $this->back,
            'targets' => $targets,
            'required' => $this->required,
            'options' => array_map(static fn (ImportOption $o): array => $o->toApi($tenantId), $this->options),
            'words' => $this->words,
        ];
    }
}
