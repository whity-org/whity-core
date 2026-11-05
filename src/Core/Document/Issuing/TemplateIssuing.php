<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Fields\FieldLabels;
use Whity\Core\Document\Slots\BackgroundIssuing;
use Whity\Core\Document\Slots\SlotBindings;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Sdk\Hooks\HookVetoException;

/**
 * Issue a document from a template or a slot, for a record, with merge values
 * (WHIT-742, DOCUMENT-AUTOMATION.md 3.1 to 3.3). One entry point for
 * `POST /api/documents`, the SDK's `Documents::issue()` and automations, so
 * all three resolve, check and refuse the same way.
 *
 * Steps, each refusing with a {@see DocumentIssueRefused} and writing nothing:
 * the template (a slot through the workspace's binding, or an id, as the actor
 * may see it), its version (published, or a letter frozen now), the record
 * (read with the actor's permissions), the values (only fields the template
 * offers, never over one the record resolved), required fields, the origin
 * key, and whether a render was required where none can happen. Then
 * {@see DocumentIssuing::issue()} raises, renders and announces it.
 */
final class TemplateIssuing
{
    /** How many unknown or conflicting fields a refusal names. */
    private const FIELDS_REPORTED = 10;

    /** What a person reads for each refusal in a preflight (WHIT-892). */
    private const WHY = [
        'invalid_request' => 'The request has a mistake in it',
        'template_not_found' => 'The template wasn\'t found, or you can\'t see it',
        'template_unpublished' => 'This template hasn\'t been published yet',
        'slot_unavailable' => 'The plugin this is for is off here',
        'slot_unbound' => 'No template has been chosen for this yet',
        'slot_misfit' => 'The template chosen for this no longer fits it',
        'background_not_allowed' => 'This workspace hasn\'t allowed this plugin to issue documents on its own',
        'record_not_found' => 'The record wasn\'t found, or you can\'t see it',
        'field_conflict' => 'A value was given for something the record already says',
        'missing_fields' => 'Some required fields have no value',
        'origin_key_conflict' => 'This origin key already names another document',
        'render_unavailable' => 'PDFs can\'t be made on this instance',
        'persist_unavailable' => 'PDFs can\'t be stored on this instance',
    ];

    /** @var callable(int, int, int): (array<string, mixed>|null) */
    private $visibleSource;

    /** @var (callable(int, int): (array<string, mixed>|null))|null */
    private $workspaceSource;

    /** @var DocumentIssuing|\Closure(): DocumentIssuing */
    private DocumentIssuing|\Closure $issuing;

    /**
     * @param callable(int, int, int): (array<string, mixed>|null) $visibleSource
     *        template id, tenant id, profile id => the template row when the
     *        profile may see it (the editor's own visibility policy)
     * @param DocumentIssuing|\Closure(): DocumentIssuing $issuing built on the
     *        first issue when a closure: a worker that never issues never
     *        needs the render service, storage or encryption key
     */
    public function __construct(
        DocumentIssuing|\Closure $issuing,
        private readonly DocumentFieldRegistry $fields,
        private readonly SettingsService $settings,
        callable $visibleSource,
        private readonly ?TemplateSlotRegistry $slots = null,
        // A template by id in a workspace, for a plugin issuing with no person:
        // its slot's binding decides it, and a bound template is never personal.
        ?callable $workspaceSource = null,
        private readonly ?FieldLabels $labels = null,
    ) {
        $this->visibleSource = $visibleSource;
        $this->workspaceSource = $workspaceSource;
        $this->issuing = $issuing;
    }

    private function issuing(): DocumentIssuing
    {
        if ($this->issuing instanceof \Closure) {
            $this->issuing = ($this->issuing)();
        }

        return $this->issuing;
    }

    /**
     * @return array{document: array<string, mixed>, artifacts: list<array<string, mixed>>, render: array<string, mixed>, replayed: bool}
     * @throws DocumentIssueRefused
     */
    public function issue(ActingAs $actor, IssueInput $input): array
    {
        // A workspace's plugins can change between two issues in one worker.
        $this->fields->resetRequest();
        $tenantId = $actor->tenantId;
        $callerId = $actor->profileId;
        $hasPermission = $actor->hasPermission;

        $source = $this->source($actor, $input);
        if ($source['kind'] === 'template') {
            $version = $this->publishedVersion($tenantId, $source);
        } elseif (in_array($source['kind'], ['letter', 'report'], true)) {
            // A one-off document ("Edit before issuing"): what it says now is frozen as a version.
            $version = $this->issuing()->freeze($tenantId, $callerId, $source);
            if ($version === null) {
                throw new DocumentIssueRefused('concurrent_edit', 'The document changed while it was being issued; try again', 409);
            }
        } else {
            throw self::notIssuable();
        }

        $type = $source['record_type'] !== null ? (string) $source['record_type'] : null;
        [$record, $resolved] = $this->readRecord($actor, $type, $input);

        $given = self::givenValues(
            $input->values,
            $version['own_fields'] ?? null,
            $this->fields->paths($tenantId, $type),
            $resolved['values'],
        );
        $values = $given + $resolved['values'];

        $missing = $this->issuing()->missing(new IssueRequest($source, $version, $values, ''), $actor);
        if ($missing !== []) {
            throw new DocumentIssueRefused('missing_fields', 'Required fields have no value for this record', 422, ['missing' => $missing]);
        }

        // The caller's own name for what this document is: issuing it again returns it.
        $originKey = self::originKey($input);

        $title = $input->title;
        $title = is_string($title) && trim($title) !== ''
            ? trim($title)
            : ($resolved['label'] !== '' ? $version['title'] . ' · ' . $resolved['label'] : (string) $version['title']);

        $requested = $input->render;
        $this->assertRenderable($tenantId, $requested);

        try {
            return $this->issuing()->issue(new IssueRequest(
                source: $source,
                version: $version,
                values: $values,
                title: $title,
                recordLabel: $resolved['label'],
                render: is_bool($requested) ? $requested : null,
                // Only a record the template read, with the actor's permissions: a typeless
                // template checks nothing, and an unchecked claim isn't a record to keep.
                record: $type !== null && $record !== null ? $record[0] . ':' . $record[1] : null,
                originKey: $originKey,
                slot: $input->slot,
                numbering: $input->slot !== null ? $this->slots?->find($tenantId, $input->slot)?->numbering : null,
            ), $actor);
        } catch (HookVetoException $e) {
            // A `document.issuing` listener refused it; nothing was written.
            throw new DocumentIssueRefused('vetoed', $e->reason(), 409, ['code' => 'vetoed', 'reason' => 'blocked_by_plugin']);
        } catch (DocumentOriginKeyConflictException $e) {
            throw new DocumentIssueRefused('origin_key_conflict', 'This origin key already names another document', 409, [
                'code' => 'origin_key_conflict',
                'document_id' => $e->documentId,
            ]);
        }
    }

    /**
     * Would this issue, and if not, why (WHIT-892)? The same steps as
     * {@see issue()}, in the same order and through the same code, and nothing is
     * written: a letter's draft is read rather than frozen, no number is allocated,
     * nothing is rendered. Unlike an issue it doesn't stop at the first problem, so a
     * screen can show everything that stands between the record and the document.
     *
     * A real issue still runs every check again: this is advice, and the answer can
     * be out of date a moment later.
     *
     * @return array{ok: bool, checks: list<array<string, mixed>>, missing_fields: list<string>, replays_document_id: int|null}
     */
    public function preflight(ActingAs $actor, IssueInput $input): array
    {
        $this->fields->resetRequest();
        $tenantId = $actor->tenantId;
        $checks = [];
        $missing = [];

        try {
            $source = $this->source($actor, $input);
            $version = match (true) {
                $source['kind'] === 'template' => $this->publishedVersion($tenantId, $source),
                // What it says now. An issue would freeze this as a version.
                in_array($source['kind'], ['letter', 'report'], true) => [
                    'version' => (int) $source['draft_version'],
                    'title' => (string) $source['title'],
                    'page' => $source['page'] ?? null,
                    'blocks' => $source['blocks'] ?? [],
                    'own_fields' => $source['own_fields'] ?? [],
                ],
                default => throw self::notIssuable(),
            };
            $checks[] = $this->passed('template', 'There is a template to issue from');
        } catch (DocumentIssueRefused $e) {
            // Nothing further can be asked without a template.
            $checks[] = $this->failed('template', 'There is a template to issue from', $e);

            return ['ok' => false, 'checks' => $checks, 'missing_fields' => [], 'replays_document_id' => null];
        }

        $type = $source['record_type'] !== null ? (string) $source['record_type'] : null;
        $record = null;
        $resolved = null;
        try {
            [$record, $resolved] = $this->readRecord($actor, $type, $input);
            $checks[] = $this->passed('record', 'The record can be read');
        } catch (DocumentIssueRefused $e) {
            $checks[] = $this->failed('record', 'The record can be read', $e);
        }

        // Without the record, its values aren't known, so a field can't be called missing.
        if ($resolved !== null) {
            try {
                $given = self::givenValues($input->values, $version['own_fields'] ?? null, $this->fields->paths($tenantId, $type), $resolved['values']);
                $checks[] = $this->passed('values', 'The values given fit the template');
                $missing = $this->issuing()->missing(new IssueRequest($source, $version, $given + $resolved['values'], ''), $actor);
                $checks[] = $missing === []
                    ? $this->passed('fields', 'Every required field has a value')
                    : $this->failed('fields', 'Every required field has a value', new DocumentIssueRefused('missing_fields', 'Required fields have no value for this record', 422));
            } catch (DocumentIssueRefused $e) {
                $checks[] = $this->failed('values', 'The values given fit the template', $e);
            }
        }

        $originKey = null;
        if ($input->originKey !== null) {
            try {
                $originKey = self::originKey($input);
                $checks[] = $this->passed('origin_key', 'The origin key can be used');
            } catch (DocumentIssueRefused $e) {
                $checks[] = $this->failed('origin_key', 'The origin key can be used', $e);
            }
        }

        if ($input->render === true) {
            try {
                $this->assertRenderable($tenantId, true);
                $checks[] = $this->passed('render', 'A PDF can be made here');
            } catch (DocumentIssueRefused $e) {
                $checks[] = $this->failed('render', 'A PDF can be made here', $e);
            }
        }

        $request = new IssueRequest(
            source: $source,
            version: $version,
            values: [],
            title: '',
            record: $type !== null && $record !== null ? $record[0] . ':' . $record[1] : null,
            originKey: is_string($originKey) ? $originKey : null,
            slot: $input->slot,
        );

        // A key that already names this document means an issue would return it,
        // and no check or listener would run for it.
        try {
            $replays = $this->issuing()->replaysDocument($request);
        } catch (DocumentOriginKeyConflictException $e) {
            $replays = null;
            $checks[] = $this->failed('origin_key', 'The origin key can be used', new DocumentIssueRefused('origin_key_conflict', 'This origin key already names another document', 409));
        }
        if ($replays === null) {
            array_push($checks, ...$this->issuing()->listenerChecks($request, $actor, $missing, fn (string $key, string $english): string => $this->label($key, $english)));
        }

        $ok = $replays !== null || array_reduce($checks, static fn (bool $all, array $check): bool => $all && $check['ok'] === true, true);

        return ['ok' => $ok, 'checks' => $checks, 'missing_fields' => $missing, 'replays_document_id' => $replays];
    }

    /** @return array<string, mixed> */
    private function passed(string $code, string $english): array
    {
        return ['code' => $code, 'label' => $this->label('preflight.check.' . $code, $english), 'ok' => true];
    }

    /**
     * The sentence people read is from a fixed list, in their language; `detail` is
     * the refusal's own English sentence, which can name the field a developer got wrong.
     *
     * @return array<string, mixed>
     */
    private function failed(string $code, string $english, DocumentIssueRefused $refused): array
    {
        return [
            'code' => $code,
            'label' => $this->label('preflight.check.' . $code, $english),
            'ok' => false,
            'why' => $this->label('preflight.why.' . $refused->reason, self::WHY[$refused->reason] ?? self::WHY['invalid_request']),
            'reason' => $refused->reason,
            'detail' => $refused->clientMessage,
        ];
    }

    private function label(string $key, string $english): string
    {
        return $this->labels?->get($key, $english) ?? $english;
    }

    private static function notIssuable(): DocumentIssueRefused
    {
        return DocumentIssueRefused::invalid('Only templates, letters and reports issue documents; a label sheet prints labels');
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function publishedVersion(int $tenantId, array $source): array
    {
        $version = $this->issuing()->published($tenantId, $source);
        if ($version === null) {
            throw new DocumentIssueRefused('template_unpublished', 'This template has no published version yet', 409);
        }

        return $version;
    }

    /**
     * The record the template is filled from, read with the actor's permissions.
     *
     * @return array{0: array{0: string, 1: string}|null, 1: array{label: string, values: array<string, string|null>}}
     */
    private function readRecord(ActingAs $actor, ?string $type, IssueInput $input): array
    {
        $record = self::parseRecord($input->record);
        if ($type !== null && ($record === null || $record[0] !== $type)) {
            throw DocumentIssueRefused::invalid('record must be "' . $type . ':<id>"');
        }
        $resolved = $this->fields->resolve($actor->tenantId, $actor->profileId, $actor->hasPermission, $type, $record[1] ?? null);
        if ($resolved === null) {
            throw new DocumentIssueRefused('record_not_found', 'Record not found', 404);
        }

        return [$record, $resolved];
    }

    private static function originKey(IssueInput $input): ?string
    {
        $originKey = $input->originKey;
        if ($originKey !== null && (!is_string($originKey) || preg_match('/^[A-Za-z0-9:._\/-]{1,160}$/', $originKey) !== 1)) {
            throw DocumentIssueRefused::invalid("'origin_key' must be 1 to 160 letters, digits or : . _ / -");
        }

        return $originKey;
    }

    /**
     * Tri-state: true = "I require an artifact"; false = "record only"; absent =
     * "render if this instance can".
     */
    private function assertRenderable(int $tenantId, mixed $requested): void
    {
        if ($requested !== true) {
            return;
        }
        $effective = $this->settings->effective($tenantId);
        if (($effective[SettingsRegistry::DOCUMENTS_RENDER_ENABLED] ?? 'false') !== 'true') {
            throw new DocumentIssueRefused('render_unavailable', 'Server-side document rendering is disabled on this instance', 503);
        }
        // The artifact half only: an operator who capped storage can still raise documents.
        if (($effective[SettingsRegistry::DOCUMENTS_PERSIST_ENABLED] ?? 'true') !== 'true') {
            throw new DocumentIssueRefused('persist_unavailable', 'Persisting rendered documents is disabled on this instance', 503);
        }
    }

    /**
     * The template to issue from, as the actor may see it.
     *
     * @return array<string, mixed>
     */
    private function source(ActingAs $actor, IssueInput $input): array
    {
        if ($input->both) {
            throw DocumentIssueRefused::invalid("Send 'slot' or 'document_source_id', not both");
        }
        if ($actor->plugin !== null) {
            return $this->backgroundSource($actor, $input);
        }
        if ($input->slot !== null) {
            return $this->slotSource($actor, $input->slot);
        }

        $sourceId = $input->sourceId;
        // A string id is accepted: JSON from a form-driven client routinely
        // carries numbers as strings.
        if (is_string($sourceId) && ctype_digit($sourceId)) {
            $sourceId = (int) $sourceId;
        }
        if (!is_int($sourceId) || $sourceId <= 0) {
            throw DocumentIssueRefused::invalid("'document_source_id' must be the id of a template to issue this document from, or send 'slot'");
        }

        return $this->visible($actor, $sourceId);
    }

    /**
     * The template bound to a slot (DOCUMENT-AUTOMATION.md 3.2). A slot whose
     * plugin is off, or that nothing fills, is refused with a code the plugin
     * can show; a bound template that no longer fits (unpublished, made
     * personal, a different record type) names why, never a silent fallback.
     *
     * @return array<string, mixed>
     */
    private function slotSource(ActingAs $actor, string $key): array
    {
        if ($key === '') {
            throw DocumentIssueRefused::invalid("'slot' must be a slot key such as bakery.order_confirmation");
        }
        $slot = $this->slots?->find($actor->tenantId, $key);
        if ($slot === null) {
            $plugin = strstr($key, '.', true);

            throw new DocumentIssueRefused('slot_unavailable', 'This slot is unknown here, or its plugin is off', 409, [
                'code' => 'slot_unavailable',
                'plugin' => $plugin !== false ? $plugin : $key,
            ]);
        }
        $effective = $this->settings->effective($actor->tenantId);
        $templateId = SlotBindings::parse((string) ($effective[SettingsRegistry::DOCUMENTS_SLOT_BINDINGS] ?? ''))[$slot->key] ?? null;
        if ($templateId === null) {
            throw new DocumentIssueRefused('slot_unbound', 'No template is bound to this slot yet', 409, ['code' => 'slot_unbound', 'slot' => $slot->key]);
        }
        $source = $this->visible($actor, $templateId);
        $problem = $slot->misfit($source);
        if ($problem !== null) {
            throw new DocumentIssueRefused('slot_misfit', 'The template bound to this slot no longer fits it: ' . $problem['message'], 409, [
                'code' => 'slot_misfit',
                'slot' => $slot->key,
                'reason' => $problem['code'],
            ]);
        }

        return $source;
    }

    /**
     * The template a plugin issues from with no person (DOCUMENT-AUTOMATION.md
     * 3.7): one of its own slots, in a workspace that allowed it.
     *
     * @return array<string, mixed>
     */
    private function backgroundSource(ActingAs $actor, IssueInput $input): array
    {
        $plugin = (string) $actor->plugin;
        $refuse = static fn (string $why): DocumentIssueRefused => new DocumentIssueRefused(
            'background_not_allowed',
            $why,
            403,
            ['code' => 'background_not_allowed', 'plugin' => $plugin],
        );
        if ($input->slot === null || !str_starts_with($input->slot, $plugin . '.')) {
            throw $refuse("With no person to act for, {$plugin} may issue only from its own slots");
        }
        $effective = $this->settings->effective($actor->tenantId);
        if (!in_array($plugin, BackgroundIssuing::parse((string) ($effective[SettingsRegistry::DOCUMENTS_BACKGROUND_ISSUING] ?? '')), true)) {
            throw $refuse("This workspace hasn't allowed {$plugin} to issue documents with no person");
        }

        return $this->slotSource($actor, $input->slot);
    }

    /** @return array<string, mixed> */
    private function visible(ActingAs $actor, int $id): array
    {
        // Not found rather than forbidden on a miss: forbidden would confirm the template exists.
        $source = $actor->plugin !== null
            ? ($this->workspaceSource !== null ? ($this->workspaceSource)($id, $actor->tenantId) : null)
            : ($this->visibleSource)($id, $actor->tenantId, $actor->profileId);
        if ($source === null) {
            throw new DocumentIssueRefused('template_not_found', 'Template not found', 404);
        }

        return $source;
    }

    /**
     * The values a request filled (merge data, DOCUMENT-AUTOMATION.md 3.3). A
     * value may fill a template's own field (`template.<key>`) or a field the
     * record left empty; a value for a field the record RESOLVED is refused as
     * `field_conflict`, so a caller can't overwrite an amount, a tax number or
     * any other value the record owns. Unknown names are echoed (up to a few)
     * so a developer can see the typo.
     *
     * @param array<string, true>         $paths    The fields the template is offered.
     * @param array<string, string|null> $resolved What the record resolved.
     * @return array<string, string>
     */
    private static function givenValues(mixed $values, mixed $ownFields, array $paths, array $resolved): array
    {
        if ($values === null) {
            return [];
        }
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            throw DocumentIssueRefused::invalid("'values' must be an object of field paths and their text");
        }
        $declared = $paths;
        foreach (is_array($ownFields) ? $ownFields : [] as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                $declared['template.' . $field['key']] = true;
            }
        }
        $out = [];
        $unknown = [];
        $conflicts = [];
        foreach ($values as $path => $value) {
            $path = (string) $path;
            // Core hands the number out; nobody names their own.
            if ($path === DocumentIssuing::NUMBER_FIELD) {
                $conflicts[] = $path;
                continue;
            }
            if (!isset($declared[$path])) {
                if (count($unknown) < self::FIELDS_REPORTED) {
                    $unknown[] = $path;
                }
                continue;
            }
            if (($resolved[$path] ?? null) !== null && $resolved[$path] !== '') {
                $conflicts[] = $path;
                continue;
            }
            if (!is_string($value) || mb_strlen($value) > 1000) {
                throw DocumentIssueRefused::invalid("'values." . $path . "' must be text of at most 1000 characters");
            }
            $out[$path] = $value;
        }
        if ($unknown !== []) {
            throw DocumentIssueRefused::invalid('These are not fields of this template: ' . implode(', ', $unknown), ['unknown' => $unknown]);
        }
        if ($conflicts !== []) {
            throw new DocumentIssueRefused(
                'field_conflict',
                'These fields come from the record and can\'t be given a value: ' . implode(', ', array_slice($conflicts, 0, self::FIELDS_REPORTED)),
                422,
                ['code' => 'field_conflict', 'conflicts' => $conflicts],
            );
        }

        return $out;
    }

    /**
     * "type:id" as [type, id], or null. A workspace plugin's type keeps its
     * plugin's casing and can be 129 characters (`CakeOrders.order`, WHIT-742).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseRecord(mixed $value): ?array
    {
        if (!is_string($value) || preg_match('/^([A-Za-z0-9._-]{1,160}):([A-Za-z0-9._-]{1,64})$/', $value, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }
}
