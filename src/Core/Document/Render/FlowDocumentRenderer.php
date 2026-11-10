<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * The tenant-policy layer in front of the render service's FLOWING mode
 * (#1072).
 *
 * The sibling of {@see DocumentRenderer}, which does the same job for the
 * fixed-canvas mode, and split from it for the reason that file's own docblock
 * gives: one place enforcing the ceilings, so a second entry point cannot end
 * up enforcing a limit the first does not. Everything HTTP — status codes,
 * RBAC, response shape — stays in the handler; everything plugin-facing stays
 * in {@see SdkDocumentRenderer}. What lives here is the part both need and
 * neither should own.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It does not validate the content tree. The render service does that — block
 * types, heading levels, table row shapes, figure sources, front-matter kinds —
 * and answers 422 naming the offending field, which
 * {@see RenderServiceClient} relays as a {@see DocumentRenderRejectedException}
 * rather than an outage. A second copy of those rules in PHP would be two
 * validators that drift, and the one that drifted would be the one nobody
 * exercised.
 *
 * So the division is: the SERVICE owns what a valid document is, and this owns
 * how much of one THIS TENANT may render. Neither can answer the other's
 * question — the service has no idea tenants exist, and core cannot know what
 * the paginator will accept.
 *
 * WHY THE CEILINGS BOUND THE INPUT AND NOT THE PAGE COUNT
 * -------------------------------------------------------
 * The fixed-canvas mode can refuse a render for producing too many pages,
 * because one template page is one PDF page and the total is arithmetic. A
 * flowing document is defined by not having that property: the page count is
 * decided by the paginator, from the content, at render time. There is no
 * honest pre-flight page ceiling to enforce, and a post-hoc one would refuse
 * work already paid for in full — the bytes exist by the time anyone can count
 * them. So the ceilings bound what CAN be measured in advance: how many blocks,
 * how large a table, how many bytes on the wire.
 */
final class FlowDocumentRenderer
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly RenderServiceClientInterface $renderService,
    ) {
    }

    /** What a print says when an operator has switched rendering off. */
    public const SWITCHED_OFF = 'Server-side document rendering is disabled on this instance';

    /**
     * Whether a flowing render could be attempted for this tenant: the
     * instance-wide switch is on AND the render service is ready.
     *
     * Both halves matter and they fail differently. The setting is an operator
     * DECISION to switch printing off (default on since WHIT-1001: running the
     * render service with a shared secret is already the opt-in, and a second
     * switch nobody knew about left fresh installs unable to print). Readiness
     * is the deployment's STATE: configured, reachable, the same release as the
     * app, and accepting the app's secret. A renderer at another release would
     * print the other release's layout without a word, so it counts as not
     * ready rather than as a renderer.
     */
    public function isEnabled(int $tenantId): bool
    {
        return $this->unavailableReason($tenantId) === null;
    }

    /**
     * Null when this tenant can print, or the sentence saying why not: the
     * switch is off, or {@see RenderReadiness::$message} for the deployment's
     * fault. Safe to show anyone; the operator's fix is on {@see readiness()}.
     */
    public function unavailableReason(int $tenantId): ?string
    {
        $effective = $this->settings->effective($tenantId);
        if (($effective[SettingsRegistry::DOCUMENTS_RENDER_ENABLED] ?? 'true') !== 'true') {
            return self::SWITCHED_OFF;
        }

        $readiness = $this->renderService->readiness();

        return $readiness->isReady() ? null : $readiness->message;
    }

    /**
     * The sentence for a print that just failed with the service unavailable.
     * The client forgets its cached readiness on such a failure, so this asks
     * the service again and names the cause (a secret changed, the renderer
     * went down or was swapped for another version) when there is one.
     */
    public function failureReason(int $tenantId): string
    {
        return $this->unavailableReason($tenantId) ?? 'Document rendering is temporarily unavailable';
    }

    /** The render service's state, asked afresh when `$fresh`. */
    public function readiness(bool $fresh = false): RenderReadiness
    {
        return $this->renderService->readiness($fresh);
    }

    /**
     * Apply this tenant's ceilings, then render.
     *
     * @param array<string, mixed> $payload A flowing-document payload — the
     *        shape {@see \Whity\Sdk\Render\FlowDocument::toPayload()} produces.
     *        Passed to the service verbatim; nothing here rewrites a caller's
     *        content.
     *
     * @throws DocumentRenderRejectedException   A ceiling was exceeded, or the
     *         service refused the tree (422, relayed with its own wording).
     * @throws RenderServiceUnavailableException The service could not do it.
     */
    public function render(int $tenantId, array $payload): RenderedDocument
    {
        $this->check($tenantId, $payload);

        return $this->renderService->renderFlow($payload);
    }

    /**
     * A label sheet (E.43). The block and table ceilings are about what a
     * paginator fragments, which a label sheet has none of; the size of the
     * request is the ceiling that still applies. How many labels one print
     * may hold is the caller's to bound before it reads any records.
     *
     * @param array<string, mixed> $payload {sheet, grid, labels, ...}
     *
     * @throws DocumentRenderRejectedException   Over the size ceiling, or refused by the service.
     * @throws RenderServiceUnavailableException The service could not do it.
     */
    public function renderLabels(int $tenantId, array $payload): RenderedDocument
    {
        $size = self::size($this->settings, $tenantId, $payload, 'Labels');
        if ($size['bytes'] > $size['max']) {
            throw DocumentRenderRejectedException::because(
                "The labels exceed the maximum render size ({$size['bytes']} bytes, max {$size['max']})"
            );
        }

        return $this->renderService->renderLabels($payload);
    }

    /**
     * Apply this tenant's ceilings WITHOUT rendering.
     *
     * Split out because one caller has to know the answer before it writes
     * anything. {@see SdkDocumentRenderer::issue()} must create the document
     * record BEFORE the render — a verification code encodes a document id, and
     * an id only exists once a row does — so if the ceilings were only checked
     * on the way into the render, a payload that was going to be refused would
     * already have left a row behind.
     *
     * @param array<string, mixed> $payload
     *
     * @throws DocumentRenderRejectedException A ceiling was exceeded.
     */
    public function check(int $tenantId, array $payload): void
    {
        $effective = $this->settings->effective($tenantId);

        $content = $payload['content'] ?? null;
        if (!is_array($content) || $content === []) {
            // The one shape check made here rather than left to the service,
            // because every ceiling below is a statement about this array and a
            // missing one would otherwise be reported as "0 blocks, within the
            // limit" — a pass, for a payload that cannot render.
            throw DocumentRenderRejectedException::because('A document must have at least one block of content');
        }

        $maxBlocks = $this->ceiling($effective, SettingsRegistry::DOCUMENTS_FLOW_MAX_BLOCKS);
        if (count($content) > $maxBlocks) {
            throw DocumentRenderRejectedException::because(
                'Document has too many content blocks (' . count($content) . ", max {$maxBlocks})"
            );
        }

        $maxTableRows = $this->ceiling($effective, SettingsRegistry::DOCUMENTS_FLOW_MAX_TABLE_ROWS);
        $largestTable = self::largestTableRowCount($content);
        if ($largestTable > $maxTableRows) {
            throw DocumentRenderRejectedException::because(
                "A table has too many rows ({$largestTable}, max {$maxTableRows})"
            );
        }

        $size = self::size($this->settings, $tenantId, $payload);
        if ($size['bytes'] > $size['max']) {
            throw DocumentRenderRejectedException::because(
                "Document exceeds the maximum render size ({$size['bytes']} bytes, max {$size['max']})"
            );
        }
    }

    /**
     * A payload's render size against this tenant's size ceiling
     * (`documents.flow_max_bytes`), without rendering or refusing anything.
     *
     * The one measure of "too large to print": the render paths above refuse
     * on it, and publishing a template asks it of the payload printing would
     * send (#1435), so the two can't disagree about what fits. Static, and
     * needing only the settings, because a publish check must answer even on
     * an instance whose render service isn't configured yet.
     *
     * Measured on the ENCODED payload, not estimated from the tree: figures
     * are `data:` URIs and a single embedded scan can be most of a document's
     * weight, so anything short of encoding it would be guessing at the number
     * the limit is actually about.
     *
     * @param array<string, mixed> $payload
     * @return array{bytes: int, max: int}
     *
     * @throws DocumentRenderRejectedException The payload can't be encoded at all.
     */
    public static function size(SettingsService $settings, int $tenantId, array $payload, string $what = 'Document'): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw DocumentRenderRejectedException::because(
                $what . ' could not be encoded for rendering: ' . json_last_error_msg()
            );
        }
        $effective = $settings->effective($tenantId);
        $key = SettingsRegistry::DOCUMENTS_FLOW_MAX_BYTES;

        return ['bytes' => strlen($encoded), 'max' => (int) ($effective[$key] ?? SettingsRegistry::defaultFor($key))];
    }

    /**
     * The largest single table in the tree, in rows.
     *
     * The largest rather than the total, because the limit is about what one
     * table asks the paginator to fragment across pages — a document of fifty
     * ten-row tables is ordinary and a single fifty-thousand-row table is not,
     * and a summed ceiling would refuse the first to catch the second.
     *
     * @param array<mixed> $content
     */
    private static function largestTableRowCount(array $content): int
    {
        $largest = 0;
        foreach ($content as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'table') {
                continue;
            }
            if (is_array($block['rows'] ?? null)) {
                $largest = max($largest, count($block['rows']));
            }
        }

        return $largest;
    }

    /**
     * A ceiling from the effective settings, falling back to the registry
     * default — the tenant override / global / registry chain every other
     * tunable in this codebase resolves through.
     *
     * @param array<string, string> $effective
     */
    private function ceiling(array $effective, string $key): int
    {
        return (int) ($effective[$key] ?? SettingsRegistry::defaultFor($key));
    }
}
