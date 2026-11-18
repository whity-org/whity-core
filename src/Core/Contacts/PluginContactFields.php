<?php

declare(strict_types=1);

namespace Whity\Core\Contacts;

use Whity\Core\CustomFields\CustomFieldService;

/**
 * Plugins' own fields on contacts (SDK 1.119, WHIT-1209, #2131): keeps each
 * workspace's `contact` fields in step with the plugins it has on.
 *
 * A plugin declares its fields once
 * ({@see \Whity\Sdk\PluginContactFieldsInterface}); custom fields live in a
 * workspace. So for each workspace:
 * - a plugin that is on (or read-only) has its fields made, or restored when
 *   archived; nothing the workspace changed on them is overwritten;
 * - a plugin that is off, lapsed or no longer installed has its fields
 *   archived, every value kept.
 *
 * It runs when a workspace turns a plugin on or off, before the plugin's own
 * `onWorkspaceEnabled()`, and otherwise the first time a workspace's contact
 * fields are read or written in a while ({@see self::TTL_SECONDS}), which
 * covers workspaces that had the plugin on before this existed.
 *
 * Worker safety: holds only when each workspace was last brought in step,
 * bounded to {@see self::MAX_REMEMBERED} workspaces. Being in step is a fact
 * about the database, so a stale entry only delays the next check.
 */
final class PluginContactFields
{
    public const RECORD_TYPE = ContactService::RECORD_TYPE;

    public const TTL_SECONDS = 300;

    private const MAX_REMEMBERED = 2000;

    /** @var array<int, float> tenant id => when it was last brought in step */
    private array $checked = [];

    private bool $running = false;

    /**
     * @param \Closure(int): ?array<string, list<array{key: string, label: array{en: string, ar: string}, kind: string, options: list<string>, required: bool, in_list: bool, edit_permission: ?string}>> $declared
     *        plugin name => its checked fields, for the plugins visible in that workspace;
     *        null when the plugins aren't loaded here (then nothing changes)
     */
    public function __construct(
        private readonly CustomFieldService $fields,
        private readonly \Closure $declared,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /** The sync {@see CustomFieldService::useOwnedFieldSync()} takes. */
    public function syncer(): \Closure
    {
        return function (int $tenantId, string $recordType): void {
            if ($recordType === self::RECORD_TYPE) {
                $this->ensure($tenantId);
            }
        };
    }

    /** Brings a workspace in step, unless it was a moment ago. */
    public function ensure(int $tenantId): void
    {
        $at = $this->checked[$tenantId] ?? null;
        if ($at !== null && $this->now() - $at < self::TTL_SECONDS) {
            return;
        }
        $this->reconcile($tenantId);
    }

    /**
     * Brings a workspace in step now: when it turns a plugin on or off.
     *
     * @return array{made: list<string>, archived: list<string>}
     */
    public function reconcile(int $tenantId): array
    {
        if ($tenantId <= 0 || $this->running) {
            return ['made' => [], 'archived' => []];
        }
        $this->running = true;
        try {
            $made = [];
            $declared = ($this->declared)($tenantId);
            if ($declared === null) {
                // Not knowing which plugins are on is not "none are on".
                return ['made' => [], 'archived' => []];
            }
            foreach ($declared as $plugin => $fields) {
                array_push($made, ...$this->fields->syncOwned($tenantId, self::RECORD_TYPE, (string) $plugin, $fields));
            }
            $archived = $this->fields->archiveOwnedExcept($tenantId, self::RECORD_TYPE, array_map('strval', array_keys($declared)));
            $this->remember($tenantId);

            return ['made' => $made, 'archived' => $archived];
        } finally {
            $this->running = false;
        }
    }

    /**
     * One plugin's declared fields, checked and named under the plugin; a
     * field that fails is dropped with its reasons.
     *
     * @param mixed $declared what getContactFields() returned: runtime data, whatever its declared shape
     * @param (callable(string): void)|null $warn told why a field was dropped
     * @return list<array{key: string, label: array{en: string, ar: string}, kind: string, options: list<string>, required: bool, in_list: bool, edit_permission: ?string}>
     */
    public static function normalize(string $pluginName, mixed $declared, ?callable $warn = null): array
    {
        if (!is_array($declared) || !array_is_list($declared)) {
            $warn !== null && $warn('getContactFields() must return a list; ignored');

            return [];
        }
        $out = [];
        $seen = [];
        foreach ($declared as $i => $field) {
            if (count($out) >= \Whity\Sdk\Manifest\ContactFieldValidator::MAX_FIELDS) {
                $warn !== null && $warn('more than ' . \Whity\Sdk\Manifest\ContactFieldValidator::MAX_FIELDS . ' contact fields; the rest are dropped');
                break;
            }
            if (!is_array($field)) {
                $warn !== null && $warn('contact field ' . $i . ' dropped: a field must be an array');
                continue;
            }
            $errors = \Whity\Sdk\Manifest\ContactFieldValidator::errors($pluginName, $field);
            $key = \Whity\Sdk\Manifest\ContactFieldValidator::qualifiedKey($pluginName, $field['key'] ?? null);
            if ($errors === [] && $key !== null && isset($seen[$key])) {
                $errors[] = 'key ' . $key . ' is declared twice';
            }
            if ($errors !== [] || $key === null) {
                $warn !== null && $warn('contact field ' . $i . ' dropped: ' . implode('; ', array_slice($errors, 0, 5)));
                continue;
            }
            $seen[$key] = true;
            /** @var array{en: string, ar: string} $label */
            $label = $field['label'];
            $kind = (string) $field['type'];
            $options = [];
            if (in_array($kind, ['select', 'multiselect'], true)) {
                foreach ((array) $field['options'] as $option) {
                    $option = mb_substr(trim((string) $option), 0, 100);
                    $options[mb_strtolower($option)] ??= $option;
                }
            }
            $out[] = [
                'key' => $key,
                'label' => ['en' => trim($label['en']), 'ar' => trim($label['ar'])],
                'kind' => $kind,
                'options' => array_values($options),
                'required' => ($field['required'] ?? false) === true,
                'in_list' => ($field['inList'] ?? false) === true,
                'edit_permission' => is_string($field['editPermission'] ?? null) ? $field['editPermission'] : null,
            ];
        }

        return $out;
    }

    private function remember(int $tenantId): void
    {
        if (count($this->checked) >= self::MAX_REMEMBERED) {
            $this->checked = [];
        }
        $this->checked[$tenantId] = $this->now();
    }

    private function now(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
    }
}
