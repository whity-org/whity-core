<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Form\FormBanner;
use Whity\Core\Form\FormRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Storage\MimeTypes;
use Whity\Storage\StorageDriverInterface;
use Whity\Storage\StorageKey;

/**
 * `POST /api/v1/forms/{id}/banner` — put an uploaded picture at the top of a form
 * (WHIT-944). Gated `forms:manage`: it's authoring.
 *
 * Multipart, field `file`, for the reason {@see FormUploadsApiHandler} gives. PNG,
 * JPEG or WebP up to {@see FormBanner::IMAGE_MAX_BYTES}, recognised by their first
 * bytes. SVG is refused: a banner is shown on public pages.
 *
 * The file goes beside the workspace's branding assets, so the public, hardened
 * branding asset route serves it to a visitor of a public form. The banner is
 * set at once, not through the builder's save bar, because the bytes are already
 * stored; the previous uploaded banner, if any, is deleted.
 *
 * Choosing a drawn pattern or no banner is a PATCH of the form, which also
 * deletes an uploaded one it replaces ({@see self::forget()}).
 */
final class FormBannerApiHandler
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly StorageDriverInterface $storage,
    ) {
    }

    /** @param array<string, string> $params */
    public function upload(Request $request, array $params): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }
        $id = (int) ($params['id'] ?? 0);
        if ($this->forms->find($tenantId, $id) === null) {
            return Response::error('Form not found', 404);
        }

        try {
            $file = $request->getUploadedFiles()['file'] ?? null;
        } catch (\Throwable $e) {
            error_log('[FormBannerApiHandler] getUploadedFiles failed: ' . $e->getMessage());

            return Response::error('The uploaded file could not be read.', 400);
        }
        if ($file === null) {
            return Response::error('A file (field "file") is required.', 400);
        }
        $path = $file->getStreamPath();
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            return Response::error('The uploaded file could not be read.', 400);
        }
        if ($bytes === '') {
            return Response::error('Validation failed', 422, ['file' => 'The file is empty.']);
        }
        if (strlen($bytes) > FormBanner::IMAGE_MAX_BYTES) {
            return Response::error('Validation failed', 422, ['file' => 'The picture must be 5 MB or smaller.']);
        }
        $ext = FormBanner::imageExtension($bytes);
        if ($ext === null) {
            return Response::error('Validation failed', 422, ['file' => 'Use a PNG, JPEG or WebP picture.']);
        }

        $previous = $this->forms->storedBanner($tenantId, $id);
        $name = FormBanner::imageName($id, $bytes, $ext);
        try {
            // The content type is given here, not derived on read: an object store
            // keeps it with the object (#786).
            $this->storage->put(StorageKey::build($tenantId, 'branding', $name), $bytes, [
                'ContentType' => MimeTypes::forExtension($ext),
            ]);
            $this->forms->update($tenantId, $id, ['banner' => ['kind' => FormBanner::KIND_IMAGE, 'name' => $name]]);
        } catch (\Throwable $e) {
            error_log('[FormBannerApiHandler] upload failed: ' . $e->getMessage());

            return Response::error('Failed to store the picture', 500);
        }
        if (($previous['name'] ?? null) !== $name) {
            self::forget($this->storage, $tenantId, $previous);
        }

        $form = $this->forms->find($tenantId, $id);

        return Response::json(['data' => ['banner' => $form['banner'] ?? null]]);
    }

    /**
     * Delete an uploaded banner's file once nothing shows it. Best effort: a file
     * left behind costs storage, not correctness, so a failure is logged.
     *
     * @param array<string, mixed>|null $banner A stored banner from {@see FormRepository::storedBanner()}.
     */
    public static function forget(?StorageDriverInterface $storage, int $tenantId, ?array $banner): void
    {
        if ($storage === null || ($banner['kind'] ?? null) !== FormBanner::KIND_IMAGE) {
            return;
        }
        $key = StorageKey::build($tenantId, 'branding', (string) $banner['name']);
        try {
            if ($storage->exists($key)) {
                $storage->delete($key);
            }
        } catch (\Throwable $e) {
            error_log('[FormBannerApiHandler] could not delete ' . $key . ': ' . $e->getMessage());
        }
    }
}
