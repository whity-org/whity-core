<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use PDOException;
use Throwable;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentState;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Print\SourceDocumentPrinter;
use Whity\Core\Document\Print\WhityDocumentPrinter;
use Whity\Core\Document\Qr\DocumentQrPolicy;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrStamp;
use Whity\Core\Document\Render\DocumentRenderRejectedException;
use Whity\Core\Document\Render\FlowDocumentRenderer;
use Whity\Core\Document\Render\RenderServiceUnavailableException;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\MeterService;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Sdk\Documents\DocumentNumberingRule;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Storage\StorageException;

/**
 * Issues documents from editor templates (editor M4c), and corrects them: the
 * ONE issuing service of DOCUMENT-AUTOMATION.md (phase A, the owner's call on
 * 31 Oct). `POST /api/documents`, the editor's render route with `persist`, a
 * correction and the SDK's renderer all issue through it, so every issued
 * document has its source link, its QR code and its events.
 *
 * A document is raised from a template's PUBLISHED version (E.9) and keeps
 * which version and which values it printed (`document_source_id`,
 * `document_source_version`, `variable_data`), so a correction re-renders the
 * same version with the same values; records and charts are read again, for
 * the person correcting it, and frozen into the new PDF (E.16).
 *
 * The record is committed BEFORE the render, as `POST /api/documents` always
 * did: the verification QR (6.7) needs the document's id, and a render that
 * fails must not lose a document somebody just raised. The response says
 * whether a PDF was stored and why not, in the closed vocabulary
 * `declined | disabled | persist_disabled | rejected | unavailable |
 * storage_unavailable | limit`.
 *
 * The verification QR is automatic: the tenant's documents.qr_enabled
 * decides (the owner's call on 31 Oct; the old per-template switch is gone).
 *
 * An ORIGIN KEY is the caller's own name for what a document is ("the
 * invoice for order 123"): issuing under a key that already names a document
 * returns that document (`replayed`), so a retried request or a redelivered
 * event never issues twice; under a key naming a document from another
 * template it is refused (DocumentOriginKeyConflictException).
 *
 * EVENTS (sdk Hooks\Events): `document.issuing` before the record is raised,
 * where a listener may veto (HookVetoException); `document.issued` once the
 * record exists, and `document.rendered` whenever a PDF is stored, each also
 * as `.async` onto the outbox. Payloads carry ids, never field values: a
 * listener reads the document with its own permissions.
 */
final class DocumentIssuing
{
    /** The field a template prints a document's number with. */
    public const NUMBER_FIELD = 'issued_document.number';

    /** The field a template prints a document's revision letter with. */
    public const REVISION_FIELD = 'issued_document.revision';

    /** The limits one rendered document spends. */
    public const RENDER_METERS = [
        EntitlementRegistry::DOCUMENTS_RENDER_PER_DAY,
        EntitlementRegistry::DOCUMENTS_RENDER_PER_MONTH,
    ];

    public function __construct(
        private readonly SourceDocumentPrinter $print,
        private readonly DocumentSourceVersionRepository $versions,
        private readonly DocumentIssuer $issuer,
        private readonly SettingsService $settings,
        private readonly ?FlowDocumentRenderer $renderer = null,
        private readonly ?DocumentQrService $qr = null,
        private readonly ?MeterService $meters = null,
        private readonly ?HookManager $hooks = null,
        private readonly ?DocumentRepository $documents = null,
        private readonly ?DocumentArtifactRepository $artifacts = null,
    ) {
    }

    /**
     * A template's published version (E.9), or null before its first publish.
     *
     * @param array<string, mixed> $source The `document_sources` row.
     * @return array<string, mixed>|null
     */
    public function published(int $tenantId, array $source): ?array
    {
        return ($source['published_version'] ?? null) !== null
            ? $this->versions->find((int) $source['id'], $tenantId, (int) $source['published_version'])
            : null;
    }

    /**
     * The version a document printed, for a revision that keeps its template as it was.
     *
     * @return array<string, mixed>|null
     */
    public function version(int $tenantId, int $sourceId, int $version): ?array
    {
        return $this->versions->find($sourceId, $tenantId, $version);
    }

    /**
     * A one-off document's draft (a letter, a report), frozen as a version so
     * the issued document records exactly what it said and a correction
     * reprints it, with the theme it printed in (editor M4d). A draft that
     * matches its last version, in the same resolved theme, is not frozen
     * again. Null when the draft moved on while this ran.
     *
     * @param array<string, mixed> $source The `document_sources` row, with blocks.
     * @return array<string, mixed>|null
     */
    public function freeze(int $tenantId, int $callerId, array $source): ?array
    {
        $last = $this->published($tenantId, $source);
        $theme = $this->print->theme($tenantId, $source['theme'] ?? null, (string) $source['kind']);
        // Compared without key order: PostgreSQL's JSONB stores object keys in its own order.
        if ($last !== null
            && $last['title'] === (string) $source['title']
            && self::canonical($last['page']) === self::canonical($source['page'])
            && self::canonical($last['blocks']) === self::canonical($source['blocks'])
            && self::canonical($last['own_fields']) === self::canonical($source['own_fields'] ?? [])
            && ($last['theme_resolved']['hash'] ?? null) === $theme['hash']) {
            return $last;
        }
        $version = $this->versions->publish((int) $source['id'], $tenantId, (int) $source['draft_version'], $callerId, $theme);

        return $version === null ? null : $this->versions->find((int) $source['id'], $tenantId, $version);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * The required fields a version uses that these values don't have
     * (E.27); empty when it can be issued.
     *
     * @return list<string>
     */
    public function missing(IssueRequest $request, ActingAs $actor): array
    {
        return $this->printer($actor, $request->source, $request->values)
            ->missingRequired(is_array($request->version['blocks'] ?? null) ? $request->version['blocks'] : []);
    }

    /**
     * Raise a document from a version, then try to render it.
     *
     * @return array{document: array<string, mixed>, artifacts: list<array<string, mixed>>,
     *               render: array{attempted: bool, stored: bool, reason: string|null}, replayed: bool}
     * @throws HookVetoException When a `document.issuing` listener refuses; nothing is written.
     * @throws DocumentOriginKeyConflictException When the origin key names a document from another template.
     * @throws \Throwable When the record itself can't be written.
     */
    public function issue(IssueRequest $request, ActingAs $actor): array
    {
        [$tenantId, $callerId] = [$actor->tenantId, $actor->issuerId()];
        [$source, $version, $values, $originKey] = [$request->source, $request->version, $request->values, $request->originKey];
        if ($originKey !== null) {
            $existing = $this->documents?->findByOriginKey($originKey, $tenantId);
            if ($existing !== null) {
                return $this->replay($tenantId, $existing, $source);
            }
        }

        $event = self::issuingEvent($request, $actor);
        $this->veto(Events::DOCUMENT_ISSUING, $event);

        // A revision keeps its original's number and rule; it never starts a series.
        $replaced = $request->revisionOf;
        $numbering = $replaced === null ? self::rule($source, $request->numbering) : null;
        $record = TemplateIssuing::parseRecord($request->record);

        try {
            $document = $this->issuer->raise(
                $tenantId,
                $callerId,
                [
                    'name' => (string) $version['title'],
                    'source_id' => (int) $source['id'],
                    'source_version' => (int) $version['version'],
                    'origin_key' => $originKey,
                    'number_pattern' => $replaced !== null ? ($replaced['number_pattern'] ?? null) : $numbering?->pattern,
                    'number_now' => $numbering?->assign === DocumentNumberingRule::ON_ISSUE,
                    'number' => $replaced['number'] ?? null,
                    'number_assigned_at' => $replaced['number_assigned_at'] ?? null,
                    'supersedes_id' => $replaced['id'] ?? null,
                    'revision' => $replaced !== null ? (int) ($replaced['revision'] ?? 1) + 1 : 1,
                    'revision_reason' => $request->revisionReason,
                    'record_type' => $record[0] ?? null,
                    'record_id' => $record[1] ?? null,
                    'slot' => $request->slot,
                ],
                mb_substr($request->title, 0, 255),
                // What printed, as the one data row it was raised with; a missing optional value printed blank.
                [array_map(static fn (?string $v): string => $v ?? '', $values)],
            );
        } catch (PDOException $e) {
            // Two people revising one document: the unique index let one through.
            if ($replaced !== null) {
                $current = $this->documents?->findById((int) $replaced['id'], $tenantId);
                if (($current['superseded_by'] ?? null) !== null) {
                    throw new DocumentRevisionConflictException((int) $current['superseded_by']);
                }
                throw $e;
            }
            // Two requests with the same origin key at once: the unique index
            // let one through, and the other answers with that one's document.
            $existing = $originKey !== null ? $this->documents?->findByOriginKey($originKey, $tenantId) : null;
            if ($existing === null) {
                throw $e;
            }

            return $this->replay($tenantId, $existing, $source);
        }
        $event = ['id' => (int) $document['id'], 'number' => $document['number'] ?? null] + $event;
        $this->announce(Events::DOCUMENT_ISSUED, $event);
        if ($replaced !== null) {
            $this->announce(Events::DOCUMENT_REVISED, [
                'id' => (int) $document['id'],
                'tenant_id' => $tenantId,
                'supersedes_id' => (int) $replaced['id'],
                'number' => $document['number'] ?? null,
                'revision' => DocumentState::revisionLetter((int) $document['revision']),
                'reason' => $request->revisionReason,
                'actor' => $callerId,
            ]);
        }

        // The verification code is minted now, with the record, whether or not
        // a PDF follows: the record page shows it, and a later render (or a
        // correction) prints the same one.
        $this->stamp($tenantId, $callerId, (int) $document['id'], $this->settings->effective($tenantId));

        if ($request->render === false) {
            return ['document' => $document, 'artifacts' => [], 'render' => ['attempted' => false, 'stored' => false, 'reason' => 'declined'], 'replayed' => false];
        }
        [$outcome, $artifact] = $this->renderAndStore($actor, $source, $version, $values, $request->recordLabel, $document);

        return ['document' => $document, 'artifacts' => $artifact !== null ? [$artifact] : [], 'render' => $outcome, 'replayed' => false];
    }

    /**
     * What `document.issuing` carries, for an issue and for a preflight alike.
     *
     * @return array<string, mixed>
     */
    private static function issuingEvent(IssueRequest $request, ActingAs $actor): array
    {
        $event = [
            'tenant_id' => $actor->tenantId,
            'source_id' => (int) $request->source['id'],
            'version' => (int) $request->version['version'],
            'record' => $request->record,
            'slot' => $request->slot,
            'origin_key' => $request->originKey,
            'actor' => $actor->issuerId(),
        ];
        if ($actor->plugin !== null) {
            // A plugin in the background: no person, and which plugin it was.
            $event['origin'] = 'plugin:' . $actor->plugin;
        }

        return $event;
    }

    /**
     * The document this request's origin key already names, when an issue would
     * return it instead of raising one.
     *
     * @throws DocumentOriginKeyConflictException When the key names a document from another template.
     */
    public function replaysDocument(IssueRequest $request): ?int
    {
        if ($request->originKey === null) {
            return null;
        }
        $existing = $this->documents?->findByOriginKey($request->originKey, (int) $request->source['tenant_id']);
        if ($existing === null) {
            return null;
        }
        if (($existing['document_source_id'] ?? null) !== (int) $request->source['id']) {
            throw new DocumentOriginKeyConflictException((int) $existing['id']);
        }

        return (int) $existing['id'];
    }

    /**
     * Ask `document.issuing` listeners, without issuing (WHIT-892). The payload is an
     * issue's plus `check_only: true`, `checks: []` and `missing_fields`. A listener
     * that knows the flag appends `{label, ok, why?}` to `checks` and returns the
     * payload, so it can give every reason. One that doesn't know it may still veto,
     * which is reported as a failed check and can't hide core's own.
     *
     * @param list<string> $missing
     * @param callable(string, string): string $label
     * @return list<array<string, mixed>>
     */
    public function listenerChecks(IssueRequest $request, ActingAs $actor, array $missing, callable $label): array
    {
        if ($this->hooks === null) {
            return [];
        }
        $payload = self::issuingEvent($request, $actor) + ['check_only' => true, 'checks' => [], 'missing_fields' => $missing];
        try {
            $answer = $this->hooks->dispatch(Events::DOCUMENT_ISSUING, $payload);
        } catch (HookVetoException $e) {
            return [[
                'code' => 'plugin',
                'label' => $label('preflight.check.plugin', 'A plugin\'s own check'),
                'ok' => false,
                'why' => $e->reason(),
            ]];
        } catch (Throwable $e) {
            error_log('[DocumentIssuing] a listener on ' . Events::DOCUMENT_ISSUING . ' failed a preflight: ' . $e->getMessage());

            return [];
        }

        $checks = [];
        foreach (is_array($answer['checks'] ?? null) ? $answer['checks'] : [] as $check) {
            if (!is_array($check) || !is_string($check['label'] ?? null) || trim($check['label']) === '' || !is_bool($check['ok'] ?? null)) {
                error_log('[DocumentIssuing] a preflight listener returned a check with no label or no ok');
                continue;
            }
            $row = ['code' => 'plugin', 'label' => mb_substr(trim($check['label']), 0, HookVetoException::REASON_MAX_LENGTH), 'ok' => $check['ok']];
            if (!$check['ok'] && is_string($check['why'] ?? null) && trim($check['why']) !== '') {
                $row['why'] = mb_substr(trim($check['why']), 0, HookVetoException::REASON_MAX_LENGTH);
            }
            $checks[] = $row;
        }

        return $checks;
    }

    /**
     * The rule a document is numbered by: the template's own, or its slot's. A
     * stored rule that no longer parses falls back rather than stopping the issue;
     * the API refuses a bad one on the way in.
     *
     * @param array<string, mixed> $source The `document_sources` row.
     */
    private static function rule(array $source, ?DocumentNumberingRule $slot): ?DocumentNumberingRule
    {
        $own = $source['numbering'] ?? null;
        if ($own === null) {
            return $slot;
        }
        try {
            return DocumentNumberingRule::fromArray($own);
        } catch (\InvalidArgumentException $e) {
            error_log('[DocumentIssuing] template ' . (string) ($source['id'] ?? '?') . ' has a bad numbering rule: ' . $e->getMessage());

            return $slot;
        }
    }

    /**
     * The record for a document a plugin assembled in code (the SDK's
     * deprecated `DocumentRenderer::issue()`): no template and no version, but
     * the same `document.issuing` veto and `document.issued` event as every
     * other issue, and its origin key, so a redelivered event can't issue
     * twice. The caller mints the QR code and renders.
     *
     * @return array{document: array<string, mixed>, replayed: bool}
     * @throws HookVetoException When a listener refuses; nothing is written.
     * @throws DocumentOriginKeyConflictException When the key names a document issued from a template.
     */
    public function raiseBuilt(int $tenantId, ?int $actorId, string $title, ?string $originKey = null): array
    {
        if ($originKey !== null) {
            $existing = $this->documents?->findByOriginKey($originKey, $tenantId);
            if ($existing !== null) {
                if (($existing['document_source_id'] ?? null) !== null) {
                    throw new DocumentOriginKeyConflictException((int) $existing['id']);
                }

                return ['document' => $existing, 'replayed' => true];
            }
        }
        $event = ['tenant_id' => $tenantId, 'source_id' => null, 'version' => null, 'record' => null, 'origin_key' => $originKey, 'actor' => $actorId];
        $this->veto(Events::DOCUMENT_ISSUING, $event);
        $document = $this->issuer->raise($tenantId, $actorId, ['name' => '', 'origin_key' => $originKey], $title);
        $this->announce(Events::DOCUMENT_ISSUED, ['id' => (int) $document['id']] + $event);

        return ['document' => $document, 'replayed' => false];
    }

    /**
     * A PDF stored for a document assembled in code: `document.rendered`.
     *
     * @param array<string, mixed> $artifact
     */
    public function storedBuilt(int $tenantId, int $documentId, array $artifact): void
    {
        $this->announce(Events::DOCUMENT_RENDERED, [
            'id' => $documentId,
            'tenant_id' => $tenantId,
            'artifact_id' => (int) $artifact['id'],
            'source_id' => null,
            'version' => null,
        ]);
    }

    /**
     * The document an origin key already names, as an issue's result.
     *
     * @param array<string, mixed> $existing The `documents` row.
     * @param array<string, mixed> $source   The template asked for now.
     * @return array{document: array<string, mixed>, artifacts: list<array<string, mixed>>,
     *               render: array{attempted: bool, stored: bool, reason: string|null}, replayed: bool}
     */
    private function replay(int $tenantId, array $existing, array $source): array
    {
        if (($existing['document_source_id'] ?? null) !== (int) $source['id']) {
            throw new DocumentOriginKeyConflictException((int) $existing['id']);
        }
        $artifacts = $this->artifacts?->listForDocument((int) $existing['id'], $tenantId) ?? [];

        return [
            'document' => $existing,
            'artifacts' => $artifacts,
            'render' => ['attempted' => false, 'stored' => $artifacts !== [], 'reason' => null],
            'replayed' => true,
        ];
    }

    /**
     * A synchronous event a listener may veto (HookVetoException goes to the
     * caller; any other failure of a listener is logged and doesn't stop it).
     *
     * @param array<string, mixed> $payload
     */
    private function veto(string $event, array $payload): void
    {
        if ($this->hooks === null) {
            return;
        }
        try {
            $this->hooks->dispatch($event, $payload);
        } catch (HookVetoException $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log('[DocumentIssuing] a listener on ' . $event . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * An event after the fact: synchronously for listeners, and as `.async`
     * onto the outbox for the relay. Two try blocks, so a listener that throws
     * can't cost the durable append (the shape DocumentRouter::broadcast uses).
     *
     * @param array<string, mixed> $payload
     */
    private function announce(string $event, array $payload): void
    {
        if ($this->hooks === null) {
            return;
        }
        try {
            $this->hooks->dispatch($event, $payload);
        } catch (Throwable $e) {
            error_log('[DocumentIssuing] a listener on ' . $event . ' failed: ' . $e->getMessage());
        }
        try {
            $this->hooks->dispatchAsync($event . '.async', $payload);
        } catch (Throwable $e) {
            error_log('[DocumentIssuing] recording ' . $event . '.async failed: ' . $e->getMessage());
        }
    }

    /**
     * Re-render a document from the version and values it was issued with,
     * and append the PDF. The same verification code goes on it, so paper
     * already in circulation stays valid.
     *
     * A correction appends to the same document: it fires `document.rendered`
     * only, never `document.issuing` or `document.issued`, and skips the origin
     * key (DOCUMENT-AUTOMATION.md).
     *
     * @param array<string, mixed>   $source   The `document_sources` row.
     * @param array<string, mixed>   $document The `documents` row, with variable_data.
     * @return array{attempted: bool, stored: bool, reason: string|null}|null Null when the version is gone.
     */
    public function rerender(ActingAs $actor, array $source, array $document): ?array
    {
        $tenantId = $actor->tenantId;
        $version = $this->versions->find((int) $source['id'], $tenantId, (int) $document['document_source_version']);
        if ($version === null) {
            return null;
        }
        $rows = is_array($document['variable_data'] ?? null) ? $document['variable_data'] : [];
        $values = [];
        foreach (is_array($rows[0] ?? null) ? $rows[0] : [] as $path => $value) {
            $values[(string) $path] = is_scalar($value) ? (string) $value : null;
        }

        return $this->renderAndStore($actor, $source, $version, $values, null, $document)[0];
    }

    /**
     * Print a number handed out at final approval, when the document's template
     * prints it: the PDF from before the approval has a blank there. A template that
     * doesn't use the field has nothing to change, so it spends no render.
     *
     * @param array<string, mixed> $source   The `document_sources` row.
     * @param array<string, mixed> $document The `documents` row, with variable_data.
     * @return array{attempted: bool, stored: bool, reason: string|null}|null Null when nothing needed printing.
     */
    public function reprintNumbered(ActingAs $actor, array $source, array $document): ?array
    {
        $version = $this->versions->find((int) $source['id'], $actor->tenantId, (int) $document['document_source_version']);
        if ($version === null || !str_contains((string) json_encode($version['blocks']), self::NUMBER_FIELD)) {
            return null;
        }

        return $this->rerender($actor, $source, $document);
    }

    /**
     * Null when this tenant can print, or the sentence saying why it cannot
     * (WHIT-1001): rendering switched off, or what is wrong with the render
     * service. The routes answer their 503 with it instead of a bare
     * "unavailable".
     */
    public function renderUnavailable(int $tenantId): ?string
    {
        if ($this->renderer === null) {
            return 'Document rendering is not set up on this instance';
        }

        return $this->renderer->unavailableReason($tenantId);
    }

    /**
     * The operator's fix for {@see renderUnavailable()}: env variables, image
     * tags, the setting to turn back on. Null when this tenant can print. Show
     * it only to operators; everyone else gets the reason alone.
     */
    public function renderFix(int $tenantId): ?string
    {
        if ($this->renderer === null) {
            return 'Start the render service and set RENDER_SHARED_SECRET on the app and the render service. '
                . 'See docs/wiki/Document-Render-Service.md, "Set up rendering".';
        }
        $reason = $this->renderer->unavailableReason($tenantId);
        if ($reason === null) {
            return null;
        }
        if ($reason === FlowDocumentRenderer::SWITCHED_OFF) {
            return 'Turn on Render Enabled (documents.render_enabled) in Settings, Feature flags.';
        }

        return $this->renderer->readiness()->fix;
    }

    /**
     * @param array<string, mixed>   $source
     * @param array<string, mixed>   $version
     * @param array<string, ?string> $values
     * @param array<string, mixed>   $document
     * @return array{0: array{attempted: bool, stored: bool, reason: string|null}, 1: array<string, mixed>|null} What happened, and the stored artifact.
     */
    private function renderAndStore(
        ActingAs $actor,
        array $source,
        array $version,
        array $values,
        ?string $recordLabel,
        array $document,
    ): array {
        [$tenantId, $callerId] = [$actor->tenantId, $actor->issuerId()];
        $effective = $this->settings->effective($tenantId);
        if ($this->renderer === null || !$this->renderer->isEnabled($tenantId)) {
            return [['attempted' => false, 'stored' => false, 'reason' => 'disabled'], null];
        }
        if (($effective[SettingsRegistry::DOCUMENTS_PERSIST_ENABLED] ?? 'true') !== 'true') {
            return [['attempted' => false, 'stored' => false, 'reason' => 'persist_disabled'], null];
        }

        // Stored on the record, and read from it for every print: an issue, a
        // correction and a reprint after final approval all show the same one.
        $values[self::NUMBER_FIELD] = isset($document['number']) ? (string) $document['number'] : null;
        $values[self::REVISION_FIELD] = DocumentState::revisionLetter((int) ($document['revision'] ?? 1));

        $printer = $this->printer($actor, $source, $values);
        $payload = $this->print->payload(
            $printer,
            $tenantId,
            ['title' => (string) $version['title'], 'lang' => (string) $source['lang'], 'page' => $version['page'], 'blocks' => $version['blocks']],
            $recordLabel,
            $values,
            // The theme frozen with the version: an issue and a correction print alike.
            $this->print->versionTheme($tenantId, $version, (string) $source['kind']),
        );
        $stamp = $this->stamp($tenantId, $callerId, (int) $document['id'], $effective);
        if ($stamp !== null) {
            $payload['content'][] = ['type' => 'qr', 'value' => $stamp->url, 'reference' => $stamp->reference];
        }

        $metered = false;
        if ($this->meters !== null) {
            if (!$this->meters->consume($tenantId, self::RENDER_METERS)->allowed) {
                return [['attempted' => false, 'stored' => false, 'reason' => 'limit'], null];
            }
            $metered = true;
        }
        try {
            $rendered = $this->renderer->render($tenantId, $payload);
        } catch (DocumentRenderRejectedException $e) {
            $this->refund($tenantId, $metered);
            error_log('[DocumentIssuing] the render was refused: ' . $e->clientMessage);

            return [['attempted' => true, 'stored' => false, 'reason' => 'rejected'], null];
        } catch (RenderServiceUnavailableException $e) {
            $this->refund($tenantId, $metered);
            error_log('[DocumentIssuing] the render failed: ' . $e->getMessage());

            return [['attempted' => true, 'stored' => false, 'reason' => 'unavailable'], null];
        }

        try {
            $pages = $rendered->pageCount > 0 ? $rendered->pageCount : null;
            $artifact = $this->issuer->appendArtifact($tenantId, $callerId, $document, $rendered->bytes, pages: $pages);
        } catch (StorageException $e) {
            error_log('[DocumentIssuing] storing the PDF failed: ' . $e->getMessage());

            return [['attempted' => true, 'stored' => false, 'reason' => 'storage_unavailable'], null];
        }

        $this->announce(Events::DOCUMENT_RENDERED, [
            'id' => (int) $document['id'],
            'tenant_id' => $tenantId,
            'artifact_id' => (int) $artifact['id'],
            'source_id' => (int) $source['id'],
            'version' => (int) $version['version'],
            'pages' => $pages,
        ]);

        return [['attempted' => true, 'stored' => true, 'reason' => null], $artifact];
    }

    /**
     * @param array<string, mixed>   $source
     * @param array<string, ?string> $values
     */
    private function printer(ActingAs $actor, array $source, array $values): WhityDocumentPrinter
    {
        $type = isset($source['record_type']) ? (string) $source['record_type'] : null;

        return $this->print->printer($actor->tenantId, $actor->profileId, $actor->hasPermission, (int) $source['id'], $values, $type);
    }

    /**
     * The verification code, minted once per document and never rotated here.
     * A mint that fails leaves the PDF without a code rather than losing it;
     * the record page says so and the code can be minted there.
     *
     * @param array<string, string> $effective
     */
    private function stamp(int $tenantId, ?int $callerId, int $documentId, array $effective): ?DocumentQrStamp
    {
        if ($this->qr === null || !DocumentQrPolicy::enabledForTenant($effective)) {
            return null;
        }
        try {
            $token = $this->qr->ensure($tenantId, $documentId, $callerId);
        } catch (\Throwable $e) {
            error_log('[DocumentIssuing] minting the verification code failed: ' . $e->getMessage());

            return null;
        }

        return $token === null ? null : DocumentQrStamp::forToken($this->qr, (string) $token['token']);
    }

    private function refund(int $tenantId, bool $metered): void
    {
        if ($metered && $this->meters !== null) {
            $this->meters->refund($tenantId, self::RENDER_METERS);
        }
    }
}
