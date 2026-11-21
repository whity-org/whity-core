<?php

declare(strict_types=1);

namespace Whity\Core\Workspace\Templates;

use Whity\Core\Money\Currency;
use Whity\Sdk\Setup\WorkspaceSetup;

/**
 * Applies a workspace template (SDK 1.116, WHIT-1194) as the person who chose
 * it, through core's own routes ({@see WorkspaceSetup}, plus the template-only
 * routes of {@see \Whity\Core\Plugin\Workspace\HostWorkspaceSetup::forTemplates()}).
 *
 * The rule is the Appointments pack's adopt mode: add what is missing, never
 * overwrite what the workspace changed, never remove anything. Applying the
 * same template again (or a newer version of it) adds only what the last run
 * did not make. Each part fails alone: what could not be done is reported and
 * the rest still happens.
 *
 * A role is granted only the permissions the person applying holds
 * themselves, so a template can never hand out more than they have.
 */
final class TemplateApplier
{
    /** @var list<array{part: string, key: string, reason: string}> */
    private array $skipped = [];

    /** @var array<string, int> */
    private array $made = [];

    /**
     * @param \Closure(int, string, int): bool $holds does the person (profile, permission, tenant) hold it
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly WorkspaceSetup $api,
        private readonly TemplateRuns $runs,
        private readonly \Closure $holds,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * @param array<string, mixed> $template a validated manifest, with `plugin` when a plugin gave it
     * @param string|null $theme the template's theme when the person kept "Use its look" on and it is installed (SDK 1.131)
     * @return array{run: int, made: array<string, int>, skipped: list<array{part: string, key: string, reason: string}>}
     */
    public function apply(int $tenantId, int $profileId, array $template, bool $withExamples, ?string $theme = null): array
    {
        $this->skipped = [];
        $this->made = [];
        $key = (string) $template['key'];
        $firstTime = $this->runs->find($tenantId, $key) === null;
        $plugin = is_string($template['plugin'] ?? null) ? $template['plugin'] : null;
        $runId = $this->runs->start($tenantId, $key, $plugin, (int) $template['version'], $profileId);
        $done = $this->runs->items($tenantId, $runId);

        $this->settings(is_array($template['settings'] ?? null) ? $template['settings'] : []);
        if ($theme !== null) {
            $this->theme($theme);
        }
        $effective = $this->effectiveSettings();
        $language = ($effective['locale'] ?? 'en') === 'ar' ? 'ar' : 'en';
        $currency = is_string($effective['billing.default_currency'] ?? null) ? $effective['billing.default_currency'] : 'USD';

        $calendar = is_array($template['calendar'] ?? null) ? $template['calendar'] : [];
        if ($firstTime) {
            $this->calendarSettings(is_array($calendar['settings'] ?? null) ? $calendar['settings'] : []);
        }
        if (is_array($calendar['hours'] ?? null)) {
            $this->hours($profileId, $calendar['hours']);
        }

        $services = $this->services($tenantId, $runId, $done, $profileId, is_array($template['services'] ?? null) ? $template['services'] : [], $currency);
        $this->roles($tenantId, $runId, $done, $profileId, is_array($template['roles'] ?? null) ? $template['roles'] : [], $language);
        $this->forms($tenantId, $runId, $done, is_array($template['forms'] ?? null) ? $template['forms'] : []);
        $this->documents($tenantId, $runId, $done, is_array($template['documents'] ?? null) ? $template['documents'] : [], $language);
        if ($withExamples) {
            $examples = is_array($template['examples'] ?? null) ? $template['examples'] : [];
            $timezone = is_string($effective['timezone'] ?? null) ? $effective['timezone'] : 'UTC';
            $this->examples($tenantId, $runId, $done, $profileId, $examples, $services, $timezone);
        }

        return ['run' => $runId, 'made' => $this->made, 'skipped' => $this->skipped];
    }

    /**
     * Archive or cancel the examples still in the workspace, as the person.
     *
     * @return array{removed: int, failed: int}
     */
    public function removeExamples(int $tenantId): array
    {
        $removed = 0;
        $failed = 0;
        foreach ($this->runs->liveExamples($tenantId) as $item) {
            $result = match ($item['kind']) {
                'booking' => $this->api->call('POST', "/api/calendar/bookings/{$item['ref_id']}/cancel", ['reason' => 'Example removed']),
                'contact' => $this->api->call('POST', "/api/contacts/{$item['ref_id']}/archive"),
                default => ['status' => 200, 'body' => null],
            };
            // Already cancelled or archived by hand counts as removed.
            if ($result['status'] < 300 || in_array($result['status'], [404, 409, 422], true)) {
                $this->runs->markRemoved($tenantId, $item['id']);
                $removed++;
            } else {
                $failed++;
            }
        }

        return ['removed' => $removed, 'failed' => $failed];
    }

    /** @param array<string, mixed> $settings */
    private function settings(array $settings): void
    {
        if ($settings === []) {
            return;
        }
        $current = $this->api->call('GET', '/api/settings');
        $overridden = self::data($current)['overridden'] ?? [];
        $overridden = is_array($overridden) ? $overridden : [];
        // Only what the workspace still keeps at the default: a choice made at
        // creation (language, currency) or since is never overwritten.
        $values = array_filter($settings, static fn (string $key): bool => !in_array($key, $overridden, true), ARRAY_FILTER_USE_KEY);
        if ($values === []) {
            return;
        }
        $result = $this->api->call('PATCH', '/api/settings', ['settings' => $values]);
        $this->outcome($result, 'settings', implode(',', array_keys($values)));
    }

    /**
     * The template's theme (WHIT-1225, TH.23), only while the workspace still
     * has the installation's theme: a theme someone already chose stays. Its
     * own PATCH, so a refusal never costs the template's other settings.
     */
    private function theme(string $theme): void
    {
        $overridden = self::data($this->api->call('GET', '/api/settings'))['overridden'] ?? [];
        if (is_array($overridden) && in_array('branding_theme', $overridden, true)) {
            return;
        }
        $this->outcome($this->api->call('PATCH', '/api/settings', ['settings' => ['branding_theme' => $theme]]), 'theme', $theme);
    }

    /** @return array<string, mixed> */
    private function effectiveSettings(): array
    {
        $effective = self::data($this->api->call('GET', '/api/settings'))['effective'] ?? [];

        return is_array($effective) ? $effective : [];
    }

    /** @param array<string, mixed> $settings */
    private function calendarSettings(array $settings): void
    {
        if ($settings === []) {
            return;
        }
        $this->outcome($this->api->call('PUT', '/api/calendar/settings', $settings), 'calendar', 'settings');
    }

    /** @param array<mixed> $hours */
    private function hours(int $profileId, array $hours): void
    {
        if (self::data($this->api->call('GET', "/api/calendar/staff/{$profileId}/hours")) !== []) {
            return;
        }
        $this->api->call('PUT', "/api/calendar/staff/{$profileId}", ['bookable' => true]);
        $this->outcome($this->api->call('PUT', "/api/calendar/staff/{$profileId}/hours", ['hours' => $hours]), 'calendar', 'hours');
    }

    /**
     * @param array<string, array{ref_id: string, removed: bool}> $done
     * @param array<mixed> $services
     * @return array<string, int> template key => service id
     */
    private function services(int $tenantId, int $runId, array $done, int $profileId, array $services, string $currency): array
    {
        $ids = [];
        $existing = [];
        foreach (self::listData($this->api->call('GET', '/api/calendar/services')) as $row) {
            foreach (self::names($row['name'] ?? null) as $name) {
                $existing[$name] = (int) ($row['id'] ?? 0);
            }
        }
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }
            $key = (string) $service['key'];
            if (isset($done["service:{$key}"])) {
                $ids[$key] = (int) $done["service:{$key}"]['ref_id'];
                continue;
            }
            if (($service['ticked'] ?? true) === false) {
                continue;
            }
            $same = null;
            foreach (self::names($service['name']) as $name) {
                $same ??= $existing[$name] ?? null;
            }
            if ($same !== null) {
                $ids[$key] = $same;
                $this->skip('services', $key, 'A service with that name is already there.');
                continue;
            }
            $price = null;
            $amount = is_array($service['prices'] ?? null) ? ($service['prices'][$currency] ?? null) : null;
            if (is_string($amount)) {
                try {
                    $price = Currency::parse(self::trimZeros($amount), $currency);
                } catch (\Throwable) {
                    $price = null;
                }
            }
            $result = $this->api->call('POST', '/api/calendar/services', array_filter([
                'name' => $service['name'],
                'description' => $service['description'] ?? null,
                'duration_minutes' => $service['durationMinutes'],
                'buffer_after_minutes' => $service['bufferAfterMinutes'] ?? 0,
                'price_minor' => $price,
                'currency' => $price === null ? null : $currency,
                'staff_profile_ids' => [$profileId],
            ], static fn (mixed $v): bool => $v !== null));
            $id = $this->created($result, 'services', $key);
            if ($id !== null) {
                $this->runs->record($tenantId, $runId, 'service', $key, (string) $id, false);
                $ids[$key] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, array{ref_id: string, removed: bool}> $done
     * @param array<mixed> $roles
     */
    private function roles(int $tenantId, int $runId, array $done, int $profileId, array $roles, string $language): void
    {
        foreach ($roles as $role) {
            if (!is_array($role) || isset($done['role:' . $role['key']])) {
                continue;
            }
            $key = (string) $role['key'];
            $name = (string) ($role['name'][$language] ?? $role['name']['en']);
            $permissions = [];
            foreach ((array) $role['permissions'] as $slug) {
                if (is_string($slug) && ($this->holds)($profileId, $slug, $tenantId)) {
                    $permissions[] = $slug;
                }
            }
            $result = $this->api->call('POST', '/api/roles', array_filter([
                'name' => $name,
                'description' => is_array($role['description'] ?? null) ? ($role['description'][$language] ?? $role['description']['en']) : null,
            ], static fn (mixed $v): bool => $v !== null));
            if ($result['status'] === 409) {
                $this->skip('roles', $key, 'A role with that name is already there.');
                continue;
            }
            $id = $this->created($result, 'roles', $key);
            if ($id === null) {
                continue;
            }
            $this->runs->record($tenantId, $runId, 'role', $key, (string) $id, false);
            if ($permissions !== []) {
                $this->outcome($this->api->call('POST', "/api/roles/{$id}/permissions", ['permissions' => $permissions]), 'roles', $key);
            }
            if (count($permissions) < count((array) $role['permissions'])) {
                $this->skip('roles', $key, 'Some permissions were left out: you do not hold them yourself.');
            }
        }
    }

    /**
     * @param array<string, array{ref_id: string, removed: bool}> $done
     * @param array<mixed> $forms
     */
    private function forms(int $tenantId, int $runId, array $done, array $forms): void
    {
        foreach ($forms as $form) {
            if (!is_array($form) || isset($done['form:' . $form['key']])) {
                continue;
            }
            $key = (string) $form['key'];
            $result = $this->api->call('POST', '/api/forms', array_filter([
                'form_key' => $key,
                'name' => $form['name'],
                'description' => $form['description'] ?? null,
            ], static fn (mixed $v): bool => $v !== null));
            if ($result['status'] === 422 && str_contains((string) json_encode($result['body']), 'already exists')) {
                $this->skip('forms', $key, 'A form with that key is already there.');
                continue;
            }
            $id = $this->created($result, 'forms', $key);
            if ($id === null) {
                continue;
            }
            $this->runs->record($tenantId, $runId, 'form', $key, (string) $id, false);
            $fields = [];
            foreach ((array) $form['fields'] as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $fields[] = array_filter([
                    'field_key' => $field['key'],
                    'field_type' => $field['type'],
                    'label' => $field['label'],
                    'help_text' => $field['help'] ?? null,
                    'is_required' => $field['required'] ?? false,
                    'options' => $field['options'] ?? null,
                ], static fn (mixed $v): bool => $v !== null);
            }
            $saved = $this->api->call('PUT', "/api/forms/{$id}/fields", ['fields' => $fields]);
            $this->outcome($saved, 'forms', $key);
            if ($saved['status'] < 300 && ($form['publish'] ?? false) === true) {
                $this->outcome($this->api->call('POST', "/api/forms/{$id}/publish"), 'forms', $key);
            }
        }
    }

    /**
     * Documents are made in the workspace's language, shared with the
     * workspace; someone who may not publish documents gets them skipped.
     *
     * @param array<string, array{ref_id: string, removed: bool}> $done
     * @param array<mixed> $documents
     */
    private function documents(int $tenantId, int $runId, array $done, array $documents, string $language): void
    {
        foreach ($documents as $document) {
            if (!is_array($document) || isset($done['document:' . $document['key']])) {
                continue;
            }
            $key = (string) $document['key'];
            $result = $this->api->call('POST', '/api/document-sources', [
                'kind' => $document['kind'],
                'title' => $document['title'][$language] ?? $document['title']['en'],
                'lang' => $language,
                'blocks' => $document['blocks'][$language] ?? $document['blocks']['en'],
                'scope' => 'tenant',
            ]);
            $id = $this->created($result, 'documents', $key);
            if ($id !== null) {
                $this->runs->record($tenantId, $runId, 'document', $key, (string) $id, false);
            }
        }
    }

    /**
     * Example contacts and bookings, recorded as examples so they come out in
     * one click. Bookings go to the person applying, on days ahead.
     *
     * @param array<string, array{ref_id: string, removed: bool}> $done
     * @param array<string, mixed> $examples
     * @param array<string, int> $services
     */
    private function examples(int $tenantId, int $runId, array $done, int $profileId, array $examples, array $services, string $timezone): void
    {
        $contacts = [];
        foreach (is_array($examples['contacts'] ?? null) ? $examples['contacts'] : [] as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $key = (string) $contact['key'];
            if (isset($done["contact:{$key}"])) {
                $contacts[$key] = $contact;
                continue;
            }
            $result = $this->api->call('POST', '/api/contacts', array_filter([
                'name' => $contact['name'],
                'email' => $contact['email'] ?? null,
                'mobile' => $contact['phone'] ?? null,
                'notes' => $contact['notes'] ?? null,
                'is_customer' => true,
            ], static fn (mixed $v): bool => $v !== null));
            $id = $this->created($result, 'examples', $key);
            if ($id !== null) {
                $this->runs->record($tenantId, $runId, 'contact', $key, (string) $id, true);
                $contacts[$key] = $contact;
            }
        }

        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $zone = new \DateTimeZone('UTC');
        }
        $today = ($this->clock !== null ? ($this->clock)() : new \DateTimeImmutable('now'))->setTimezone($zone);
        foreach (is_array($examples['bookings'] ?? null) ? $examples['bookings'] : [] as $booking) {
            if (!is_array($booking) || isset($done['booking:' . $booking['key']])) {
                continue;
            }
            $key = (string) $booking['key'];
            $serviceId = $services[$booking['service']] ?? null;
            $contact = $contacts[$booking['contact']] ?? null;
            if ($serviceId === null || $contact === null) {
                $this->skip('examples', $key, 'Its service or contact was not made.');
                continue;
            }
            [$hour, $minute] = array_map('intval', explode(':', (string) $booking['time']));
            $startsAt = $today->modify('+' . (int) $booking['inDays'] . ' days')->setTime($hour, $minute);
            $result = $this->api->call('POST', '/api/calendar/bookings', [
                'service_id' => $serviceId,
                'starts_at' => $startsAt->format(\DateTimeInterface::ATOM),
                'profile_id' => $profileId,
                'customer' => array_filter(['name' => $contact['name'], 'email' => $contact['email'] ?? null], static fn (mixed $v): bool => $v !== null),
                'notify' => false,
                'book_anyway' => true,
            ]);
            $id = $this->created($result, 'examples', $key);
            if ($id !== null) {
                $this->runs->record($tenantId, $runId, 'booking', $key, (string) $id, true);
            }
        }
    }

    /** @param array{status: int, body: mixed} $result */
    private function created(array $result, string $part, string $key): ?int
    {
        $id = self::data($result)['id'] ?? null;
        if ($result['status'] >= 300 || !is_numeric($id)) {
            $this->skip($part, $key, self::reason($result));

            return null;
        }
        $this->made[$part] = ($this->made[$part] ?? 0) + 1;

        return (int) $id;
    }

    /** @param array{status: int, body: mixed} $result */
    private function outcome(array $result, string $part, string $key): void
    {
        if ($result['status'] >= 300) {
            $this->skip($part, $key, self::reason($result));
        }
    }

    private function skip(string $part, string $key, string $reason): void
    {
        $this->skipped[] = ['part' => $part, 'key' => $key, 'reason' => $reason];
    }

    /** @param array{status: int, body: mixed} $result */
    private static function reason(array $result): string
    {
        $body = $result['body'];
        $message = is_array($body) ? ($body['error'] ?? $body['message'] ?? null) : null;
        if (is_array($message)) {
            $message = $message['message'] ?? null;
        }

        return is_string($message) && $message !== '' ? $message : 'Not done (' . $result['status'] . ').';
    }

    /**
     * @param array{status: int, body: mixed} $result
     * @return array<mixed>
     */
    private static function data(array $result): array
    {
        $body = $result['body'];
        if (!is_array($body) || $result['status'] < 200 || $result['status'] >= 300) {
            return [];
        }
        $data = $body['data'] ?? $body;

        return is_array($data) ? $data : [];
    }

    /**
     * @param array{status: int, body: mixed} $result
     * @return list<array<mixed>>
     */
    private static function listData(array $result): array
    {
        $data = self::data($result);
        $rows = array_is_list($data) ? $data : ($data['items'] ?? $data['services'] ?? []);

        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    /** @return list<string> a label's names, lower-cased, in each language it has */
    private static function names(mixed $label): array
    {
        $values = is_array($label) ? array_values($label) : [$label];
        $out = [];
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $out[] = mb_strtolower(trim($value));
            }
        }

        return $out;
    }

    /** "10.000" in a two-decimal currency reads as "10.00"; anything real is kept. */
    private static function trimZeros(string $amount): string
    {
        return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
    }
}
