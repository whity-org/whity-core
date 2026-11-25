<?php

declare(strict_types=1);

namespace Whity\Sdk\Testing;

use Whity\Sdk\Setup\WorkspaceSetup;

/**
 * A workspace in memory that answers the routes a workspace template uses,
 * the way core answers them (SDK 1.169, WHIT-1409). For template authors'
 * tests, through {@see WorkspaceTemplateTestCase}; it needs no database and
 * no core.
 *
 * It keeps what core's checks that matter to a template keep: a service with
 * the same name is the same service, a role name and a form key are unique
 * (409 and 422, as core), settings remember which were changed from the
 * default, and a person may be refused a route (`refuse()`) or not hold a
 * permission (`$permissions`). It does not check bodies as strictly as core;
 * core's own real-engine test runs every official template against Postgres.
 *
 * Rows are public arrays by kind (`services`, `roles`, `forms`, `documents`,
 * `routes` (review routes in Flows, 1.172), `contacts`, `bookings`), keyed by id, so a test can act as the person:
 * delete one, rename one, then apply again.
 */
final class FakeWorkspace implements WorkspaceSetup
{
    /** @var array<string, mixed> */
    public array $settings;

    /** @var list<string> settings changed from the default */
    public array $overridden;

    /** @var array<string, mixed> */
    public array $calendarSettings = [];

    /** @var array<int, list<array<string, mixed>>> hours by profile id */
    public array $hours = [];

    /** @var array<int, array<string, mixed>> */
    public array $services = [];

    /** @var array<int, array<string, mixed>> */
    public array $roles = [];

    /** @var array<int, array<string, mixed>> */
    public array $forms = [];

    /** @var array<int, array<string, mixed>> */
    public array $documents = [];

    /** @var array<int, array<string, mixed>> review routes in Flows, with their `steps` (SDK 1.172) */
    public array $routes = [];

    /** @var array<int, array<string, mixed>> */
    public array $contacts = [];

    /** @var array<int, array<string, mixed>> */
    public array $bookings = [];

    /** @var list<array{method: string, path: string, body: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<string> routes refused with 403, as `METHOD /path` with `{id}` for any id */
    private array $refused = [];

    private int $nextId = 1;

    /**
     * @param array<string, mixed> $settings the workspace's settings
     * @param list<string> $overridden which of them were chosen (a template never changes those)
     * @param list<string>|null $permissions the slugs the person applying holds; null for all
     */
    public function __construct(
        array $settings = ['billing.default_currency' => 'USD', 'timezone' => 'UTC', 'locale' => 'en'],
        array $overridden = ['billing.default_currency', 'locale'],
        public ?array $permissions = null,
    ) {
        $this->settings = $settings;
        $this->overridden = $overridden;
    }

    /** Refuse a route with 403, as core does for a person without its permission. */
    public function refuse(string $route): self
    {
        $this->refused[] = $route;

        return $this;
    }

    /** Does the person hold this permission. */
    public function holds(string $permission): bool
    {
        return $this->permissions === null || in_array($permission, $this->permissions, true);
    }

    /**
     * Everything the workspace holds, to compare before and after.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'settings' => $this->settings,
            'calendarSettings' => $this->calendarSettings,
            'hours' => $this->hours,
            'services' => $this->services,
            'roles' => $this->roles,
            'forms' => $this->forms,
            'documents' => $this->documents,
            'routes' => $this->routes,
            'contacts' => $this->contacts,
            'bookings' => $this->bookings,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: mixed}
     */
    public function call(string $method, string $path, array $body = []): array
    {
        $method = strtoupper($method);
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];
        $route = $method . ' ' . preg_replace('#/\d+(?=/|$)#', '/{id}', $path);
        if (in_array($route, $this->refused, true)) {
            return self::answer(403, ['error' => 'Forbidden']);
        }
        preg_match_all('#/(\d+)(?=/|$)#', $path, $m);
        $id = (int) ($m[1][0] ?? 0);
        $second = (int) ($m[1][1] ?? 0);

        return match ($route) {
            'GET /api/settings' => self::answer(200, ['data' => ['effective' => $this->settings, 'overridden' => $this->overridden]]),
            'PATCH /api/settings' => $this->patchSettings($body),
            'GET /api/calendar/settings' => self::answer(200, ['data' => $this->calendarSettings]),
            'PUT /api/calendar/settings' => $this->putCalendarSettings($body),
            'PUT /api/calendar/staff/{id}' => self::answer(200, ['data' => []]),
            'GET /api/calendar/staff/{id}/hours' => self::answer(200, ['data' => $this->hours[$id] ?? []]),
            'PUT /api/calendar/staff/{id}/hours' => $this->putHours($id, $body),
            'GET /api/calendar/services' => self::answer(200, ['data' => array_values($this->services)]),
            'POST /api/calendar/services' => $this->create('services', $body),
            'PATCH /api/calendar/services/{id}' => $this->update('services', $id, $body),
            'GET /api/roles' => self::answer(200, ['data' => array_values($this->roles)]),
            'POST /api/roles' => $this->createRole($body),
            // Granting adds, as core's route does; it never removes.
            'POST /api/roles/{id}/permissions' => $this->update('roles', $id, ['permissions' => array_values(array_unique([...($this->roles[$id]['permissions'] ?? []), ...(is_array($body['permissions'] ?? null) ? $body['permissions'] : [])]))]),
            'GET /api/forms' => self::answer(200, ['data' => array_values($this->forms)]),
            'POST /api/forms' => $this->createForm($body),
            'PUT /api/forms/{id}/fields' => $this->update('forms', $id, ['fields' => $this->withIds(is_array($body['fields'] ?? null) ? $body['fields'] : [])]),
            'GET /api/forms/{id}/fields' => isset($this->forms[$id]) ? self::answer(200, ['data' => $this->forms[$id]['fields'] ?? []]) : self::answer(404, ['error' => 'Not found']),
            'POST /api/forms/{id}/fields' => $this->addField($id, $body),
            'PATCH /api/forms/{id}/fields/{id}' => $this->patchField($id, $second, $body),
            'POST /api/forms/{id}/publish' => $this->update('forms', $id, ['published' => true]),
            'GET /api/forms/{id}' => isset($this->forms[$id]) ? self::answer(200, ['data' => $this->forms[$id]]) : self::answer(404, ['error' => 'Not found']),
            // Only a form's route is changed through here by a template.
            'PATCH /api/forms/{id}' => $this->update('forms', $id, array_intersect_key($body, ['route_template_id' => true])),
            'GET /api/document-route-templates' => self::answer(200, ['data' => array_values($this->routes)]),
            'POST /api/document-route-templates' => $this->createRoute($body),
            'PUT /api/document-route-templates/{id}/graph' => $this->update('routes', $id, ['steps' => is_array($body['steps'] ?? null) ? $body['steps'] : [], 'edges' => is_array($body['edges'] ?? null) ? $body['edges'] : []]),
            'POST /api/document-sources' => $this->create('documents', $body),
            'GET /api/contacts' => self::answer(200, ['data' => array_values($this->contacts)]),
            'POST /api/contacts' => $this->create('contacts', $body),
            'POST /api/contacts/{id}/archive' => $this->update('contacts', $id, ['archived' => true]),
            'GET /api/calendar/bookings' => self::answer(200, ['data' => array_values($this->bookings)]),
            'POST /api/calendar/bookings' => $this->create('bookings', $body),
            'POST /api/calendar/bookings/{id}/cancel' => $this->update('bookings', $id, ['cancelled' => true]),
            default => self::answer(404, ['error' => 'Not found']),
        };
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: mixed}
     */
    private function patchSettings(array $body): array
    {
        $values = is_array($body['settings'] ?? null) ? $body['settings'] : [];
        foreach ($values as $name => $value) {
            $this->settings[$name] = $value;
            if (!in_array($name, $this->overridden, true)) {
                $this->overridden[] = (string) $name;
            }
        }

        return self::answer(200, ['data' => ['effective' => $this->settings]]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: mixed}
     */
    private function putCalendarSettings(array $body): array
    {
        $this->calendarSettings = array_merge($this->calendarSettings, $body);

        return self::answer(200, ['data' => $this->calendarSettings]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: mixed}
     */
    private function putHours(int $profileId, array $body): array
    {
        $this->hours[$profileId] = array_values(array_filter(is_array($body['hours'] ?? null) ? $body['hours'] : [], 'is_array'));

        return self::answer(200, ['data' => $this->hours[$profileId]]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: mixed}
     */
    private function createRole(array $body): array
    {
        foreach ($this->roles as $role) {
            if (mb_strtolower((string) ($role['name'] ?? '')) === mb_strtolower((string) ($body['name'] ?? ''))) {
                return self::answer(409, ['error' => 'A role with that name already exists.']);
            }
        }

        return $this->create('roles', $body + ['permissions' => []]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: mixed}
     */
    private function createForm(array $body): array
    {
        foreach ($this->forms as $form) {
            if (($form['form_key'] ?? null) === ($body['form_key'] ?? null)) {
                return self::answer(422, ['error' => 'A form with that key already exists.']);
            }
        }

        return $this->create('forms', $body + ['fields' => [], 'published' => false, 'route_template_id' => null]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: mixed}
     */
    private function createRoute(array $body): array
    {
        foreach ($this->routes as $route) {
            if (($route['name'] ?? null) === ($body['name'] ?? null)) {
                return self::answer(409, ['error' => 'A route template with that name already exists.']);
            }
        }

        return $this->create('routes', $body + ['steps' => [], 'edges' => []]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: mixed}
     */
    private function create(string $kind, array $body): array
    {
        $id = $this->nextId++;
        $this->{$kind}[$id] = ['id' => $id] + $body;

        return self::answer(201, ['data' => ['id' => $id]]);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array{status: int, body: mixed}
     */
    private function update(string $kind, int $id, array $changes): array
    {
        if (!isset($this->{$kind}[$id])) {
            return self::answer(404, ['error' => 'Not found']);
        }
        $this->{$kind}[$id] = array_merge($this->{$kind}[$id], $changes);

        return self::answer(200, ['data' => $this->{$kind}[$id]]);
    }

    /**
     * @param array<mixed> $fields
     * @return list<array<string, mixed>>
     */
    private function withIds(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (is_array($field)) {
                $out[] = ['id' => $this->nextId++] + $field;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: mixed}
     */
    private function addField(int $formId, array $body): array
    {
        if (!isset($this->forms[$formId])) {
            return self::answer(404, ['error' => 'Not found']);
        }
        $field = $this->withIds([$body])[0];
        $this->forms[$formId]['fields'][] = $field;

        return self::answer(201, ['data' => ['id' => $field['id']]]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: mixed}
     */
    private function patchField(int $formId, int $fieldId, array $body): array
    {
        foreach ($this->forms[$formId]['fields'] ?? [] as $i => $field) {
            if (is_array($field) && ($field['id'] ?? null) === $fieldId) {
                $this->forms[$formId]['fields'][$i] = array_merge($field, $body);

                return self::answer(200, ['data' => $this->forms[$formId]['fields'][$i]]);
            }
        }

        return self::answer(404, ['error' => 'Not found']);
    }

    /** @return array{status: int, body: mixed} */
    private static function answer(int $status, mixed $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
