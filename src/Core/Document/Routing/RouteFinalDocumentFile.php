<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentPresenter;
use Whity\Core\Response;
use Whity\Storage\StorageException;

/**
 * The PDF a route's finalise step made (WHIT-921), served to somebody the
 * caller has already decided may have it: the applicant from My submissions,
 * or a reviewer the route reached. Who may is the caller's question; this only
 * reads the one artifact the finalisation names, on its own document.
 */
final class RouteFinalDocumentFile
{
    public function __construct(
        private readonly RouteFinalisationRepository $finalisations,
        private readonly DocumentArtifactRepository $artifacts,
        private readonly DocumentArtifactStore $store,
    ) {
    }

    /**
     * The route's final PDF, or a 404 while there is none.
     */
    public function forRoute(int $tenantId, int $routeId, string $title): Response
    {
        $finalisation = $this->finalisations->findForRoute($tenantId, $routeId);
        if ($finalisation === null
            || $finalisation['status'] !== RouteFinalisationRepository::DONE
            || $finalisation['document_id'] === null
            || $finalisation['artifact_id'] === null) {
            return Response::error('There is no final document yet', 404);
        }

        $artifact = $this->artifacts->findById(
            (int) $finalisation['artifact_id'],
            (int) $finalisation['document_id'],
            $tenantId,
        );
        if ($artifact === null) {
            return Response::error('There is no final document yet', 404);
        }

        try {
            $bytes = $this->store->get((string) $artifact['storage_key']);
        } catch (StorageException $e) {
            error_log('[RouteFinalDocumentFile] reading a final document failed: ' . $e->getMessage());

            return Response::error('The final document is temporarily unavailable', 503);
        }

        return new Response(200, $bytes, [
            'Content-Type' => (string) $artifact['content_type'],
            'Content-Disposition' => 'inline; filename="' . DocumentPresenter::slugify($title) . '.pdf"',
            'ETag' => '"' . $artifact['checksum_sha256'] . '"',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
