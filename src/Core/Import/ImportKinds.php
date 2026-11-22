<?php

declare(strict_types=1);

namespace Whity\Core\Import;

/**
 * Every kind the shared import screen offers (WHIT-1326, design section 34
 * §6.5). Core registers its own at boot ({@see CoreImportKinds}); the
 * accounting lane adds its kinds here the same way. Built once at boot and
 * only read after; worker-safe.
 */
final class ImportKinds
{
    /** @var array<string, ImportKind> */
    private array $kinds = [];

    public function register(ImportKind $kind): void
    {
        if (isset($this->kinds[$kind->key])) {
            throw new \LogicException('Import kind ' . $kind->key . ' is registered twice');
        }
        $this->kinds[$kind->key] = $kind;
    }

    public function get(string $key): ?ImportKind
    {
        return $this->kinds[$key] ?? null;
    }

    /** @return list<ImportKind> in the order they were registered */
    public function all(): array
    {
        return array_values($this->kinds);
    }

    /**
     * The kinds someone may use, in the API shape.
     *
     * @param \Closure(string): bool $may whether the person holds a permission
     * @return list<array<string, mixed>>
     */
    public function forPerson(int $tenantId, \Closure $may, ?string $area = null): array
    {
        $out = [];
        foreach ($this->kinds as $kind) {
            if ($area !== null && $kind->area !== $area) {
                continue;
            }
            foreach ($kind->permissions as $permission) {
                if (!$may($permission)) {
                    continue 2;
                }
            }
            $out[] = $kind->toApi($tenantId);
        }

        return $out;
    }
}
