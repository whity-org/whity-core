<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Document\Issuing\DocumentIssuing;
use Whity\Core\Document\Render\DocumentRenderRejectedException;
use Whity\Core\Document\Render\FlowDocumentRenderer;
use Whity\Core\Document\Render\RenderServiceUnavailableException;
use Whity\Core\Document\Theme\DocumentThemeTokens;
use Whity\Core\Entitlement\MeterService;
use Whity\Core\Form\Print\FormPrintoutService;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Form printouts on screen (design 3.4, P1).
 *
 *  - `GET /api/form-submissions/{id}/printout?lang=en|ar`: one response in the
 *    automatic layout, as it would print now. Not stored and not a document:
 *    the stored record is the PDF a route's final step makes, which never
 *    changes.
 *  - `POST /api/document-themes/form-printout-preview`: the Form printouts tab's
 *    live preview (`TplFormPrintStyle`), the workspace's latest response (or
 *    `submissionId`) printed with the unsaved `form` and `letterhead` options in
 *    the body. A free workspace sees the standard layout, as it would print.
 *
 * Each preview is one render against the workspace's render limits.
 */
final class FormPrintoutsApiHandler
{
    public function __construct(
        private readonly PDO $db,
        private readonly FormPrintoutService $printouts,
        private readonly ?FlowDocumentRenderer $renderer,
        private readonly ?MeterService $meters = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function submission(Request $request, array $params): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }
        $lang = self::query($request)['lang'] ?? null;
        $payload = $this->printouts->payload($tenantId, (int) ($params['id'] ?? 0), [
            'lang' => in_array($lang, ['en', 'ar'], true) ? $lang : null,
        ]);
        if ($payload === null) {
            return Response::error('Submission not found', 404);
        }

        return $this->pdf($tenantId, $payload);
    }

    public function themePreview(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }
        $body = JsonBody::parsed($request);
        $theme = [];
        foreach (['form', 'letterhead', 'accent'] as $key) {
            if (array_key_exists($key, $body)) {
                $theme[$key] = $body[$key];
            }
        }
        $errors = DocumentThemeTokens::errors($theme);
        if ($errors !== []) {
            return Response::error('Some options are not valid', 422, ['errors' => $errors]);
        }
        $submissionId = is_int($body['submissionId'] ?? null) ? $body['submissionId'] : $this->latestSubmission($tenantId);
        if ($submissionId === null) {
            return Response::error('There is no form response to preview yet. Send one form first.', 404);
        }
        $lang = $body['lang'] ?? null;
        $payload = $this->printouts->payload($tenantId, $submissionId, [
            'lang' => in_array($lang, ['en', 'ar'], true) ? $lang : null,
            'theme' => DocumentThemeTokens::normalize($theme),
        ]);
        if ($payload === null) {
            return Response::error('Submission not found', 404);
        }

        return $this->pdf($tenantId, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function pdf(int $tenantId, array $payload): Response
    {
        if ($this->renderer === null || !$this->renderer->isEnabled($tenantId)) {
            return Response::error('PDFs are turned off on this workspace', 503);
        }
        $metered = false;
        if ($this->meters !== null) {
            if (!$this->meters->consume($tenantId, DocumentIssuing::RENDER_METERS)->allowed) {
                return Response::error('The document render limit has been reached', 429);
            }
            $metered = true;
        }
        try {
            $pdf = $this->renderer->render($tenantId, $payload)->bytes;
        } catch (DocumentRenderRejectedException $e) {
            $this->refund($tenantId, $metered);

            return Response::error($e->clientMessage, 422);
        } catch (RenderServiceUnavailableException $e) {
            $this->refund($tenantId, $metered);
            error_log('[FormPrintoutsApiHandler] render failed: ' . $e->getMessage());

            return Response::error($this->renderer->failureReason($tenantId), 503);
        }

        return new Response(200, $pdf, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="printout.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function latestSubmission(int $tenantId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM form_submissions WHERE tenant_id = :tenant_id AND withdrawn_at IS NULL ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function refund(int $tenantId, bool $metered): void
    {
        if ($metered && $this->meters !== null) {
            $this->meters->refund($tenantId, DocumentIssuing::RENDER_METERS);
        }
    }

    /** @return array<string, string> */
    private static function query(Request $request): array
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $query[$k] = $v;
                }
            }
        }

        return $query;
    }
}
