<?php

declare(strict_types=1);

namespace Tests\Core\Workspace\Templates;

use Whity\Sdk\Setup\WorkspaceSetup;

/** Core's routes as a template sees them: records each call, answers like core. */
final class FakeSetup implements WorkspaceSetup
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $services = [];

    /** @var list<array<string, mixed>> */
    public array $hours = [];

    public ?string $refuse = null;

    /** @var list<string> */
    public array $overridden = ['billing.default_currency', 'locale'];

    /** @var array<string, mixed> */
    public array $effective = ['billing.default_currency' => 'JOD', 'timezone' => 'Asia/Amman', 'locale' => 'en'];

    public function call(string $method, string $path, array $body = []): array
    {
        $route = "{$method} {$path}";
        $this->calls[] = [$route, $body];
        if ($route === $this->refuse) {
            return ['status' => 403, 'body' => ['error' => 'Forbidden']];
        }
        if ($route === 'PATCH /api/settings') {
            $this->overridden = [...$this->overridden, ...array_keys((array) $body['settings'])];
        }
        if ($method === 'PUT' && str_ends_with($path, '/hours')) {
            $hours = $body['hours'] ?? [];
            $this->hours = is_array($hours) ? array_values(array_filter($hours, 'is_array')) : [];
        }
        $ids = [
            'POST /api/calendar/services' => 10,
            'POST /api/roles' => 30,
            'POST /api/forms' => 40,
            'POST /api/document-sources' => 45,
            'POST /api/contacts' => 50,
            'POST /api/calendar/bookings' => 60,
        ];

        return match (true) {
            $route === 'GET /api/settings' => ['status' => 200, 'body' => ['data' => [
                'effective' => $this->effective,
                'overridden' => $this->overridden,
            ]]],
            $route === 'GET /api/calendar/services' => ['status' => 200, 'body' => ['data' => $this->services]],
            str_ends_with($route, '/hours') && $method === 'GET' => ['status' => 200, 'body' => ['data' => $this->hours]],
            isset($ids[$route]) => ['status' => 201, 'body' => ['data' => ['id' => $ids[$route]]]],
            default => ['status' => 200, 'body' => ['data' => []]],
        };
    }

    /** @return array<string, mixed> the body of the first call to this route */
    public function body(string $route): array
    {
        foreach ($this->calls as [$called, $body]) {
            if ($called === $route) {
                return $body;
            }
        }
        throw new \RuntimeException("No call to {$route}");
    }

    public function count(string $route): int
    {
        return count(array_filter($this->calls, static fn (array $call): bool => $call[0] === $route));
    }

    /** @return list<string> */
    public function writes(): array
    {
        return array_values(array_filter(array_column($this->calls, 0), static fn (string $route): bool => !str_starts_with($route, 'GET ')));
    }
}
