<?php

declare(strict_types=1);

namespace Whity\Sdk\Templates;

use Whity\Sdk\Setup\WorkspaceSetup;

/**
 * Applies a workspace template as the person who chose it (SDK 1.169,
 * WHIT-1409; the engine core has run since 1.116, moved here so that a
 * template author's tests and the preview run the same code as the real
 * thing).
 *
 * It works only through two things it is given:
 * - {@see WorkspaceSetup}: core's own routes, as that person. Core's applier
 *   also lets it make roles and documents and remove examples.
 * - {@see TemplateLedger}: what earlier runs made.
 *
 * Give it {@see DryRunWorkspaceSetup} and a {@see MemoryLedger} over the real
 * ledger and it plans without changing anything: that is the preview.
 *
 * The rules, which every run keeps (design section 40 §2.4):
 * 1. **Adds, never overwrites.** A setting is set only while the workspace
 *    keeps the default; booking rules the first time only; hours only for a
 *    person who has none; an item already there by name or key is kept.
 * 2. **Never deletes.** Nothing a run does removes anything, except
 *    {@see self::removeExamples()}, which only archives or cancels examples.
 * 3. **Never brings back.** An item an earlier run made is not made again,
 *    even when the person has since deleted it.
 * 4. **No more than the person has.** A role gets only the permissions the
 *    person applying holds.
 * 5. **Each part fails alone.** What could not be done is reported, the rest
 *    still happens.
 *
 * Since SDK 1.170 (WHIT-1413) a run records the version of every part of a
 * composed template ({@see TemplateComposer}), and each item remembers a
 * fingerprint of the manifest entry it was made from. An item an earlier run
 * made whose entry has since changed is reported `changed` and left alone;
 * {@see self::adopt()} applies the template's version to that one item when
 * the person asks (decision WT.2, WT.3). An update adds no examples once the
 * person removed them.
 *
 * Since SDK 1.172 (WHIT-1419) a template's `pipelines` become review routes
 * in Flows: one route template per pipeline, a decision stage per stage for
 * the people holding its role, attached to its form when the form has no
 * route yet. It needs `route_templates:write`; without it the pipeline is
 * skipped with the reason.
 *
 * The manifest must already be valid ({@see \Whity\Sdk\Manifest\WorkspaceTemplateValidator}).
 */
final class TemplateEngine
{
    /** Made now. */
    public const ADD = 'add';

    /** Already in the workspace by name or key: left as the workspace has it. */
    public const KEEP = 'keep';

    /** Made by an earlier run of this template: not made again, even if since deleted. */
    public const DONE = 'done';

    /** Could not be done; `reason` says why. */
    public const SKIP = 'skip';

    /** A service the template offers but does not tick: left for the guided setup. */
    public const LEFT = 'left';

    /** Made by an earlier run, and the template has changed it since: left as it is, "Use the template's version" offered (SDK 1.170). */
    public const CHANGED = 'changed';

    /** The kinds an item can be adopted for, by the manifest list they come from. */
    public const ADOPTABLE = ['services' => 'service', 'roles' => 'role', 'forms' => 'form', 'documents' => 'document', 'pipelines' => 'pipeline'];

    /** What making a review route takes (SDK 1.172). */
    public const ROUTE_PERMISSION = 'route_templates:write';

    /** @var array<string, true> form keys this run made, so their route is attached without asking the form first */
    private array $newForms = [];

    private int $tenantId = 0;

    private int $runId = 0;

    /** Did this workspace apply every part at the version on offer (so items without a fingerprint can take today's). */
    private bool $sameVersions = false;

    /** @var list<array{part: string, key: string, reason: string}> */
    private array $skipped = [];

    /** @var array<string, int> */
    private array $made = [];

    /** @var list<array{part: string, key: string, action: string, reason?: string}> */
    private array $items = [];

    /**
     * @param \Closure(int, string, int): bool $holds does the person (profile, permission, workspace) hold it
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly WorkspaceSetup $api,
        private readonly TemplateLedger $ledger,
        private readonly \Closure $holds,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * @param array<string, mixed> $template a valid manifest, with `plugin` when a plugin gave it
     * @param string|null $theme the template's theme to set, when the person kept "Use its look" on (SDK 1.131)
     * @return array{run: int, made: array<string, int>, skipped: list<array{part: string, key: string, reason: string}>, items: list<array{part: string, key: string, action: string, reason?: string}>}
     */
    public function apply(int $tenantId, int $profileId, array $template, bool $withExamples, ?string $theme = null): array
    {
        $this->skipped = [];
        $this->made = [];
        $this->items = [];
        $this->newForms = [];
        $key = (string) $template['key'];
        $existing = $this->ledger->find($tenantId, $key);
        $firstTime = $existing === null;
        $recorded = $existing === null ? [] : $this->ledger->parts($tenantId, (int) $existing['id']);
        $parts = TemplateComposer::parts($template);
        $this->sameVersions = $recorded !== [];
        foreach ($parts as $part) {
            if (($recorded[$part['key']] ?? null) !== $part['version']) {
                $this->sameVersions = false;
            }
        }
        $plugin = is_string($template['plugin'] ?? null) ? $template['plugin'] : null;
        $version = (int) $template['version'];
        $runId = $this->ledger->start($tenantId, $key, $plugin, $version, $profileId);
        foreach ($parts as $part) {
            $this->ledger->recordPart($tenantId, $runId, $part['key'], $part['plugin'] ?? $plugin, $part['version']);
        }
        $this->tenantId = $tenantId;
        $this->runId = $runId;
        $done = $this->ledger->items($tenantId, $runId);

        $this->settings(is_array($template['settings'] ?? null) ? $template['settings'] : []);
        if ($theme !== null) {
            $this->theme($theme);
        }
        $effective = $this->effectiveSettings();
        $language = ($effective['locale'] ?? 'en') === 'ar' ? 'ar' : 'en';
        $currency = is_string($effective['billing.default_currency'] ?? null) ? $effective['billing.default_currency'] : 'USD';

        $calendar = is_array($template['calendar'] ?? null) ? $template['calendar'] : [];
        $calendarSettings = is_array($calendar['settings'] ?? null) ? $calendar['settings'] : [];
        if ($firstTime) {
            $this->calendarSettings($calendarSettings);
        } elseif ($calendarSettings !== []) {
            $this->note('calendar', 'settings', self::DONE);
        }
        if (is_array($calendar['hours'] ?? null)) {
            $this->hours($profileId, $calendar['hours']);
        }

        $services = $this->services($tenantId, $runId, $done, $profileId, is_array($template['services'] ?? null) ? $template['services'] : [], $currency);
        $this->roles($tenantId, $runId, $done, $profileId, is_array($template['roles'] ?? null) ? $template['roles'] : [], $language);
        $this->forms($tenantId, $runId, $done, is_array($template['forms'] ?? null) ? $template['forms'] : []);
        $this->documents($tenantId, $runId, $done, is_array($template['documents'] ?? null) ? $template['documents'] : [], $language);
        $pipelines = is_array($template['pipelines'] ?? null) ? $template['pipelines'] : [];
        if ($pipelines !== []) {
            $this->pipelines($tenantId, $runId, $done, $profileId, $template, $pipelines, $language);
        }
        $examplesRemoved = false;
        foreach ($done as $kindKey => $item) {
            if ($item['removed'] && (str_starts_with($kindKey, 'contact:') || str_starts_with($kindKey, 'booking:'))) {
                $examplesRemoved = true;
            }
        }
        if ($withExamples && $examplesRemoved) {
            $this->note('examples', 'all', self::KEEP, 'You removed the examples, so they are not added again.');
        } elseif ($withExamples) {
            $examples = is_array($template['examples'] ?? null) ? $template['examples'] : [];
            $timezone = is_string($effective['timezone'] ?? null) ? $effective['timezone'] : 'UTC';
            $this->examples($tenantId, $runId, $done, $profileId, $examples, $services, $timezone);
        }

        return ['run' => $runId, 'made' => $this->made, 'skipped' => $this->skipped, 'items' => $this->items];
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
        foreach ($this->ledger->liveExamples($tenantId) as $item) {
            $result = match ($item['kind']) {
                'booking' => $this->api->call('POST', "/api/calendar/bookings/{$item['ref_id']}/cancel", ['reason' => 'Example removed']),
                'contact' => $this->api->call('POST', "/api/contacts/{$item['ref_id']}/archive"),
                default => ['status' => 200, 'body' => null],
            };
            // Already cancelled or archived by hand counts as removed.
            if ($result['status'] < 300 || in_array($result['status'], [404, 409, 422], true)) {
                $this->ledger->markRemoved($tenantId, $item['id']);
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
        $values = [];
        foreach ($settings as $name => $value) {
            if (in_array($name, $overridden, true)) {
                $this->note('settings', (string) $name, self::KEEP);
            } else {
                $values[$name] = $value;
            }
        }
        if ($values === []) {
            return;
        }
        $result = $this->api->call('PATCH', '/api/settings', ['settings' => $values]);
        $this->outcome($result, 'settings', implode(',', array_keys($values)));
        foreach (array_keys($values) as $name) {
            $this->note('settings', (string) $name, $result['status'] < 300 ? self::ADD : self::SKIP, $result['status'] < 300 ? null : self::reason($result));
        }
    }

    /**
     * The template's theme (TH.23), only while the workspace still has the
     * installation's theme: a theme someone already chose stays. Its own
     * PATCH, so a refusal never costs the template's other settings.
     */
    private function theme(string $theme): void
    {
        $overridden = self::data($this->api->call('GET', '/api/settings'))['overridden'] ?? [];
        if (is_array($overridden) && in_array('branding_theme', $overridden, true)) {
            $this->note('theme', $theme, self::KEEP);

            return;
        }
        $result = $this->api->call('PATCH', '/api/settings', ['settings' => ['branding_theme' => $theme]]);
        $this->outcome($result, 'theme', $theme);
        $this->note('theme', $theme, $result['status'] < 300 ? self::ADD : self::SKIP, $result['status'] < 300 ? null : self::reason($result));
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
        $result = $this->api->call('PUT', '/api/calendar/settings', $settings);
        $this->outcome($result, 'calendar', 'settings');
        $this->note('calendar', 'settings', $result['status'] < 300 ? self::ADD : self::SKIP, $result['status'] < 300 ? null : self::reason($result));
    }

    /** @param array<mixed> $hours */
    private function hours(int $profileId, array $hours): void
    {
        if (self::data($this->api->call('GET', "/api/calendar/staff/{$profileId}/hours")) !== []) {
            $this->note('calendar', 'hours', self::KEEP);

            return;
        }
        $this->api->call('PUT', "/api/calendar/staff/{$profileId}", ['bookable' => true]);
        $result = $this->api->call('PUT', "/api/calendar/staff/{$profileId}/hours", ['hours' => $hours]);
        $this->outcome($result, 'calendar', 'hours');
        $this->note('calendar', 'hours', $result['status'] < 300 ? self::ADD : self::SKIP, $result['status'] < 300 ? null : self::reason($result));
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
        $there = [];
        foreach (self::listData($this->api->call('GET', '/api/calendar/services')) as $row) {
            $there[(int) ($row['id'] ?? 0)] = true;
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
                $gone = !isset($there[$ids[$key]]);
                $this->note('services', $key, $gone ? self::DONE : $this->doneOrChanged('service', $key, $service, $done));
                continue;
            }
            if (($service['ticked'] ?? true) === false) {
                $this->note('services', $key, self::LEFT);
                continue;
            }
            $same = null;
            foreach (self::names($service['name']) as $name) {
                $same ??= $existing[$name] ?? null;
            }
            if ($same !== null) {
                $ids[$key] = $same;
                $this->skip('services', $key, 'A service with that name is already there.');
                $this->note('services', $key, self::KEEP);
                continue;
            }
            $price = null;
            $amount = is_array($service['prices'] ?? null) ? ($service['prices'][$currency] ?? null) : null;
            if (is_string($amount)) {
                $price = TemplateMoney::toMinor($amount, $currency);
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
                $this->ledger->record($tenantId, $runId, 'service', $key, (string) $id, false);
                $this->ledger->setFingerprint($tenantId, $runId, 'service', $key, self::fingerprint($service));
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
            if (!is_array($role)) {
                continue;
            }
            $key = (string) $role['key'];
            if (isset($done['role:' . $key])) {
                $this->note('roles', $key, $this->doneOrChanged('role', $key, $role, $done));
                continue;
            }
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
                $this->note('roles', $key, self::KEEP);
                continue;
            }
            $id = $this->created($result, 'roles', $key);
            if ($id === null) {
                continue;
            }
            $this->ledger->record($tenantId, $runId, 'role', $key, (string) $id, false);
            $this->ledger->setFingerprint($tenantId, $runId, 'role', $key, self::fingerprint($role));
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
            if (!is_array($form)) {
                continue;
            }
            $key = (string) $form['key'];
            if (isset($done['form:' . $key])) {
                $this->note('forms', $key, $this->doneOrChanged('form', $key, $form, $done));
                continue;
            }
            $result = $this->api->call('POST', '/api/forms', array_filter([
                'form_key' => $key,
                'name' => $form['name'],
                'description' => $form['description'] ?? null,
            ], static fn (mixed $v): bool => $v !== null));
            if ($result['status'] === 422 && str_contains((string) json_encode($result['body']), 'already exists')) {
                $this->skip('forms', $key, 'A form with that key is already there.');
                $this->note('forms', $key, self::KEEP);
                continue;
            }
            $id = $this->created($result, 'forms', $key);
            if ($id === null) {
                continue;
            }
            $this->ledger->record($tenantId, $runId, 'form', $key, (string) $id, false);
            $this->ledger->setFingerprint($tenantId, $runId, 'form', $key, self::fingerprint($form));
            $this->newForms[$key] = true;
            $fields = [];
            foreach ((array) $form['fields'] as $field) {
                if (is_array($field)) {
                    $fields[] = self::field($field);
                }
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
            if (!is_array($document)) {
                continue;
            }
            $key = (string) $document['key'];
            if (isset($done['document:' . $key])) {
                $this->note('documents', $key, $this->doneOrChanged('document', $key, $document, $done));
                continue;
            }
            $result = $this->api->call('POST', '/api/document-sources', [
                'kind' => $document['kind'],
                'title' => $document['title'][$language] ?? $document['title']['en'],
                'lang' => $language,
                'blocks' => $document['blocks'][$language] ?? $document['blocks']['en'],
                'scope' => 'tenant',
            ]);
            $id = $this->created($result, 'documents', $key);
            if ($id !== null) {
                $this->ledger->record($tenantId, $runId, 'document', $key, (string) $id, false);
                $this->ledger->setFingerprint($tenantId, $runId, 'document', $key, self::fingerprint($document));
            }
        }
    }

    /**
     * Review routes in Flows (SDK 1.172): each pipeline becomes a route
     * template with one decision stage per stage, for the people holding the
     * stage's role, and is attached to its form when the form has none yet.
     *
     * @param array<string, array{ref_id: string, removed: bool}> $done
     * @param array<string, mixed> $template
     * @param array<mixed> $pipelines
     */
    private function pipelines(int $tenantId, int $runId, array $done, int $profileId, array $template, array $pipelines, string $language): void
    {
        $items = $this->ledger->items($tenantId, $runId);
        $roleIds = null;
        $formIds = null;
        foreach ($pipelines as $pipeline) {
            if (!is_array($pipeline)) {
                continue;
            }
            $key = (string) $pipeline['key'];
            if (isset($done['pipeline:' . $key])) {
                $this->note('pipelines', $key, $this->doneOrChanged('pipeline', $key, $pipeline, $done));
                continue;
            }
            if (!($this->holds)($profileId, self::ROUTE_PERMISSION, $tenantId)) {
                $reason = 'You may not design review routes, so this one was left out.';
                $this->skip('pipelines', $key, $reason);
                $this->note('pipelines', $key, self::SKIP, $reason);
                continue;
            }
            $roleIds ??= $this->roleIds($items, is_array($template['roles'] ?? null) ? $template['roles'] : []);
            $formIds ??= $this->formIds($items, is_array($template['forms'] ?? null) ? $template['forms'] : []);
            $formKey = (string) $pipeline['for']['form'];
            $formId = $formIds[$formKey] ?? null;
            if ($formId === null) {
                $reason = 'Its form is not in your workspace, so it was left out.';
                $this->skip('pipelines', $key, $reason);
                $this->note('pipelines', $key, self::SKIP, $reason);
                continue;
            }
            $name = (string) ($pipeline['name'][$language] ?? $pipeline['name']['en']);
            $result = $this->makeRoute($pipeline, $language, $roleIds, $name, $key);
            if ($result['status'] === 409) {
                $this->skip('pipelines', $key, 'A review route with that name is already there.');
                $this->note('pipelines', $key, self::KEEP);
                continue;
            }
            $id = $this->created($result, 'pipelines', $key);
            if ($id === null) {
                continue;
            }
            $this->ledger->record($tenantId, $runId, 'pipeline', $key, (string) $id, false);
            $this->ledger->setFingerprint($tenantId, $runId, 'pipeline', $key, self::fingerprint($pipeline));
            $this->attach($formId, $id, isset($this->newForms[$formKey]), null, $key);
        }
    }

    /**
     * Make one route template and draw its stages. A refusal of either call
     * comes back as that call's answer; once the template exists its id comes
     * back even when drawing failed, which is then reported for `$key`.
     *
     * @param array<string, mixed> $pipeline
     * @param array<string, int> $roleIds
     * @return array{status: int, body: mixed}
     */
    private function makeRoute(array $pipeline, string $language, array $roleIds, string $name, string $key): array
    {
        $steps = [];
        foreach ((array) $pipeline['stages'] as $stage) {
            if (!is_array($stage)) {
                continue;
            }
            $label = (string) ($stage['name'][$language] ?? $stage['name']['en']);
            $roleId = $roleIds[(string) $stage['role']] ?? null;
            if ($roleId === null) {
                return ['status' => 422, 'body' => ['error' => "The role for \"{$label}\" is not in your workspace, so it was left out."]];
            }
            $steps[] = [
                'position' => count($steps) + 1,
                'rule_kind' => 'role',
                'rule_config' => ['role_id' => $roleId],
                'label' => $label,
                'decision' => true,
                'allow_send_back' => ($stage['sendBack'] ?? true) !== false,
                'canvas_x' => 0,
                'canvas_y' => count($steps) * 160,
            ];
        }
        $description = is_array($pipeline['description'] ?? null) ? ($pipeline['description'][$language] ?? $pipeline['description']['en']) : null;
        $created = $this->api->call('POST', '/api/document-route-templates', array_filter([
            'name' => $name,
            'description' => $description,
        ], static fn (mixed $v): bool => $v !== null));
        $id = self::data($created)['id'] ?? null;
        if ($created['status'] >= 300 || !is_numeric($id)) {
            return $created;
        }
        $this->outcome($this->api->call('PUT', "/api/document-route-templates/{$id}/graph", ['steps' => $steps, 'edges' => []]), 'pipelines', $key);

        return ['status' => 201, 'body' => ['data' => ['id' => (int) $id]]];
    }

    /**
     * Send a form's answers through a route, only while the form has none
     * (or, for "Use the template's version", still has `$replacing`): a route
     * the person chose stays.
     */
    private function attach(int $formId, int $routeId, bool $formIsNew, ?int $replacing, string $key): bool
    {
        if (!$formIsNew) {
            $current = self::data($this->api->call('GET', "/api/forms/{$formId}"))['route_template_id'] ?? null;
            if ($current !== null && (int) $current !== $replacing) {
                $this->skip('pipelines', $key, 'Its form already sends its answers through another review route, so that was not changed.');

                return false;
            }
        }
        $result = $this->api->call('PATCH', "/api/forms/{$formId}", ['route_template_id' => $routeId]);
        $this->outcome($result, 'pipelines', $key);

        return $result['status'] < 300;
    }

    /**
     * The workspace's ids of the template's roles: the ones a run made, else
     * one already there with the same name.
     *
     * @param array<string, array{ref_id: string, removed: bool}> $items
     * @param array<mixed> $roles
     * @return array<string, int>
     */
    private function roleIds(array $items, array $roles): array
    {
        $ids = [];
        $byName = null;
        foreach ($roles as $role) {
            if (!is_array($role)) {
                continue;
            }
            $key = (string) $role['key'];
            $item = $items['role:' . $key] ?? null;
            if ($item !== null && !$item['removed']) {
                $ids[$key] = (int) $item['ref_id'];
                continue;
            }
            if ($byName === null) {
                $byName = [];
                foreach (self::listData($this->api->call('GET', '/api/roles')) as $row) {
                    foreach (self::names($row['name'] ?? null) as $name) {
                        $byName[$name] = (int) ($row['id'] ?? 0);
                    }
                }
            }
            foreach (self::names($role['name']) as $name) {
                if (isset($byName[$name])) {
                    $ids[$key] = $byName[$name];
                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * The workspace's ids of the template's forms: the ones a run made, else
     * one already there with the same key.
     *
     * @param array<string, array{ref_id: string, removed: bool}> $items
     * @param array<mixed> $forms
     * @return array<string, int>
     */
    private function formIds(array $items, array $forms): array
    {
        $ids = [];
        $byKey = null;
        foreach ($forms as $form) {
            if (!is_array($form)) {
                continue;
            }
            $key = (string) $form['key'];
            $item = $items['form:' . $key] ?? null;
            if ($item !== null && !$item['removed']) {
                $ids[$key] = (int) $item['ref_id'];
                continue;
            }
            if ($byKey === null) {
                $byKey = [];
                foreach (self::listData($this->api->call('GET', '/api/forms')) as $row) {
                    if (is_string($row['form_key'] ?? null)) {
                        $byKey[$row['form_key']] = (int) ($row['id'] ?? 0);
                    }
                }
            }
            if (isset($byKey[$key])) {
                $ids[$key] = $byKey[$key];
            }
        }

        return $ids;
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
                $this->note('examples', $key, self::DONE);
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
                $this->ledger->record($tenantId, $runId, 'contact', $key, (string) $id, true);
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
            if (!is_array($booking)) {
                continue;
            }
            $key = (string) $booking['key'];
            if (isset($done['booking:' . $key])) {
                $this->note('examples', $key, self::DONE);
                continue;
            }
            $serviceId = $services[$booking['service']] ?? null;
            $contact = $contacts[$booking['contact']] ?? null;
            if ($serviceId === null || $contact === null) {
                $this->skip('examples', $key, 'Its service or contact was not made.');
                $this->note('examples', $key, self::SKIP, 'Its service or contact was not made.');
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
                $this->ledger->record($tenantId, $runId, 'booking', $key, (string) $id, true);
            }
        }
    }

    /**
     * Use the template's version of one item an earlier run made, as the
     * person (SDK 1.170; decision WT.2, WT.3). It changes only what the
     * template sets on the item and deletes nothing:
     * - a service: its names, description, duration, buffer and its price in
     *   the workspace's currency;
     * - a form: each template question's label and help, and the questions
     *   the template has that the form lacks (the person's own stay);
     * - a role: the template's permissions it lacks, only those the person
     *   holds (none removed);
     * - a document: a new copy beside the person's;
     * - a pipeline (1.172): a new review route beside the person's, named with
     *   the template's version; its form moves to it only while the form still
     *   uses the route the template made.
     *
     * @param array<string, mixed> $template a valid, composed manifest
     * @param string $part `services`, `roles`, `forms`, `documents` or `pipelines`
     * @return array{action: string, reason?: string} `add` when done, `keep` when it already matches, `skip` with why
     */
    public function adopt(int $tenantId, int $profileId, array $template, string $part, string $itemKey): array
    {
        $kind = self::ADOPTABLE[$part] ?? null;
        $entry = null;
        foreach (is_array($template[$part] ?? null) ? $template[$part] : [] as $candidate) {
            if (is_array($candidate) && ($candidate['key'] ?? null) === $itemKey) {
                $entry = $candidate;
            }
        }
        $run = $this->ledger->find($tenantId, (string) $template['key']);
        if ($kind === null || $entry === null || $run === null) {
            return ['action' => self::SKIP, 'reason' => 'The template has no such item for this workspace.'];
        }
        $item = $this->ledger->items($tenantId, (int) $run['id'])["{$kind}:{$itemKey}"] ?? null;
        if ($item === null) {
            return ['action' => self::SKIP, 'reason' => 'This template did not make that item here, so it is yours to change.'];
        }
        $fingerprint = self::fingerprint($entry);
        if (($item['fingerprint'] ?? null) === $fingerprint) {
            return ['action' => self::KEEP];
        }
        $id = $item['ref_id'];
        $result = match ($kind) {
            'service' => $this->adoptService($id, $entry),
            'role' => $this->adoptRole($tenantId, $profileId, $id, $entry),
            'form' => $this->adoptForm($id, $entry),
            'pipeline' => $this->adoptPipeline($tenantId, $profileId, (int) $run['id'], $template, $itemKey, $id, $entry),
            default => $this->adoptDocument($entry),
        };
        if ($result['status'] === 404) {
            return ['action' => self::SKIP, 'reason' => 'It is no longer in your workspace.'];
        }
        if ($result['status'] >= 300) {
            return ['action' => self::SKIP, 'reason' => self::reason($result)];
        }
        $this->ledger->setFingerprint($tenantId, (int) $run['id'], $kind, $itemKey, $fingerprint);

        return ['action' => self::ADD];
    }

    /**
     * A manifest entry's fingerprint: the same entry always gives the same
     * one, whatever order its keys are in.
     *
     * @param array<array-key, mixed> $entry
     */
    public static function fingerprint(array $entry): string
    {
        return substr(hash('sha256', (string) json_encode(self::canonical($entry), JSON_UNESCAPED_UNICODE)), 0, 40);
    }

    /**
     * An item an earlier run made: `done`, or `changed` when its manifest
     * entry differs from the one it was made from. An item made before
     * fingerprints were kept takes today's when the workspace already has
     * this version, and is offered as changed when it does not.
     *
     * @param array<string, mixed> $entry
     * @param array<string, array{ref_id: string, removed: bool, fingerprint?: ?string}> $done
     */
    private function doneOrChanged(string $kind, string $key, array $entry, array $done): string
    {
        $stored = $done["{$kind}:{$key}"]['fingerprint'] ?? null;
        $now = self::fingerprint($entry);
        if ($stored === null) {
            if ($this->sameVersions) {
                $this->ledger->setFingerprint($this->tenantId, $this->runId, $kind, $key, $now);

                return self::DONE;
            }

            return self::CHANGED;
        }

        return $stored === $now ? self::DONE : self::CHANGED;
    }

    /**
     * @param array<string, mixed> $service
     * @return array{status: int, body: mixed}
     */
    private function adoptService(string $id, array $service): array
    {
        $effective = $this->effectiveSettings();
        $currency = is_string($effective['billing.default_currency'] ?? null) ? $effective['billing.default_currency'] : 'USD';
        $amount = is_array($service['prices'] ?? null) ? ($service['prices'][$currency] ?? null) : null;
        $price = is_string($amount) ? TemplateMoney::toMinor($amount, $currency) : null;

        return $this->api->call('PATCH', "/api/calendar/services/{$id}", array_filter([
            'name' => $service['name'],
            'description' => $service['description'] ?? null,
            'duration_minutes' => $service['durationMinutes'],
            'buffer_after_minutes' => $service['bufferAfterMinutes'] ?? 0,
            'price_minor' => $price,
            'currency' => $price === null ? null : $currency,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * @param array<string, mixed> $role
     * @return array{status: int, body: mixed}
     */
    private function adoptRole(int $tenantId, int $profileId, string $id, array $role): array
    {
        $permissions = [];
        foreach ((array) $role['permissions'] as $slug) {
            if (is_string($slug) && ($this->holds)($profileId, $slug, $tenantId)) {
                $permissions[] = $slug;
            }
        }
        if ($permissions === []) {
            return ['status' => 200, 'body' => null];
        }

        return $this->api->call('POST', "/api/roles/{$id}/permissions", ['permissions' => $permissions]);
    }

    /**
     * @param array<string, mixed> $form
     * @return array{status: int, body: mixed}
     */
    private function adoptForm(string $id, array $form): array
    {
        $current = $this->api->call('GET', "/api/forms/{$id}/fields");
        if ($current['status'] >= 300) {
            return $current;
        }
        $have = [];
        foreach (self::listData($current) as $row) {
            if (is_string($row['field_key'] ?? null)) {
                $have[$row['field_key']] = (int) ($row['id'] ?? 0);
            }
        }
        $last = ['status' => 200, 'body' => null];
        foreach ((array) $form['fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $fieldId = $have[(string) $field['key']] ?? null;
            $result = $fieldId === null
                ? $this->api->call('POST', "/api/forms/{$id}/fields", self::field($field))
                : $this->api->call('PATCH', "/api/forms/{$id}/fields/{$fieldId}", array_filter([
                    'label' => $field['label'],
                    'help_text' => $field['help'] ?? null,
                ], static fn (mixed $v): bool => $v !== null));
            if ($result['status'] >= 300) {
                return $result;
            }
            $last = $result;
        }

        return $last;
    }

    /**
     * @param array<string, mixed> $document
     * @return array{status: int, body: mixed}
     */
    private function adoptDocument(array $document): array
    {
        $language = ($this->effectiveSettings()['locale'] ?? 'en') === 'ar' ? 'ar' : 'en';

        return $this->api->call('POST', '/api/document-sources', [
            'kind' => $document['kind'],
            'title' => $document['title'][$language] ?? $document['title']['en'],
            'lang' => $language,
            'blocks' => $document['blocks'][$language] ?? $document['blocks']['en'],
            'scope' => 'tenant',
        ]);
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $pipeline
     * @return array{status: int, body: mixed}
     */
    private function adoptPipeline(int $tenantId, int $profileId, int $runId, array $template, string $key, string $oldId, array $pipeline): array
    {
        if (!($this->holds)($profileId, self::ROUTE_PERMISSION, $tenantId)) {
            return ['status' => 403, 'body' => ['error' => 'You may not design review routes.']];
        }
        $language = ($this->effectiveSettings()['locale'] ?? 'en') === 'ar' ? 'ar' : 'en';
        $items = $this->ledger->items($tenantId, $runId);
        $roleIds = $this->roleIds($items, is_array($template['roles'] ?? null) ? $template['roles'] : []);
        $formIds = $this->formIds($items, is_array($template['forms'] ?? null) ? $template['forms'] : []);
        $version = (int) $template['version'];
        $name = (string) ($pipeline['name'][$language] ?? $pipeline['name']['en'])
            . ($language === 'ar' ? "، الإصدار {$version}" : ", version {$version}");
        $result = $this->makeRoute($pipeline, $language, $roleIds, $name, $key);
        $id = self::data($result)['id'] ?? null;
        if ($result['status'] >= 300 || !is_numeric($id)) {
            return $result['status'] === 404 ? ['status' => 422, 'body' => $result['body']] : $result;
        }
        $this->ledger->record($tenantId, $runId, 'pipeline', $key, (string) $id, false);
        $formId = $formIds[(string) $pipeline['for']['form']] ?? null;
        if ($formId !== null) {
            $this->attach($formId, (int) $id, false, (int) $oldId, $key);
        }

        return $result;
    }

    /**
     * A form question as core's routes take it.
     *
     * @param array<mixed> $field
     * @return array<string, mixed>
     */
    private static function field(array $field): array
    {
        return array_filter([
            'field_key' => $field['key'],
            'field_type' => $field['type'],
            'label' => $field['label'],
            'help_text' => $field['help'] ?? null,
            'is_required' => $field['required'] ?? false,
            'options' => $field['options'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    /** @param array{status: int, body: mixed} $result */
    private function created(array $result, string $part, string $key): ?int
    {
        $id = self::data($result)['id'] ?? null;
        if ($result['status'] >= 300 || !is_numeric($id)) {
            $reason = self::reason($result);
            $this->skip($part, $key, $reason);
            $this->note($part, $key, self::SKIP, $reason);

            return null;
        }
        $this->made[$part] = ($this->made[$part] ?? 0) + 1;
        $this->note($part, $key, self::ADD);

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

    private function note(string $part, string $key, string $action, ?string $reason = null): void
    {
        $this->items[] = $reason === null
            ? ['part' => $part, 'key' => $key, 'action' => $action]
            : ['part' => $part, 'key' => $key, 'action' => $action, 'reason' => $reason];
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
}
