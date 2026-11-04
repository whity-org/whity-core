<?php

declare(strict_types=1);

namespace Whity\Sdk\Storage;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * The generic upload and download endpoint for a plugin's files (SDK 1.63,
 * WHIT-886). A plugin wires it into its own routes, so the plugin whose route
 * answers is the plugin whose files are touched, and no request can name
 * another:
 *
 *     $files = new PluginFileController(\Whity\app(PluginFiles::class)->for($this));
 *     return [
 *         ['method' => 'GET',    'path' => '/api/reports/files',         'handler' => [$files, 'list'],     'requiredPermission' => 'reports:read'],
 *         ['method' => 'POST',   'path' => '/api/reports/files/{name}',  'handler' => [$files, 'upload'],   'requiredPermission' => 'reports:write'],
 *         ['method' => 'GET',    'path' => '/api/reports/files/{name}',  'handler' => [$files, 'download'], 'requiredPermission' => 'reports:read'],
 *         ['method' => 'DELETE', 'path' => '/api/reports/files/{name}',  'handler' => [$files, 'delete'],   'requiredPermission' => 'reports:write'],
 *     ];
 *
 * The routes are FLAT on purpose: `{name}` is one file name at the top of the
 * plugin's space, so the path cannot carry a sub-path or a traversal. Code that
 * needs folders uses {@see PluginFileStore} directly.
 *
 * The permission is the plugin's to declare on its routes, as for any route.
 * This controller does not check it. Uploads are capped at
 * {@see self::MAX_UPLOAD_BYTES}, the content type is kept only when it is a
 * well-formed `type/subtype`, and a download is sent as an attachment with
 * `nosniff`, so a stored file cannot be run in the browser.
 */
final class PluginFileController
{
    /** The largest accepted upload, in bytes (10 MiB). */
    public const MAX_UPLOAD_BYTES = 10_485_760;

    private const DEFAULT_TYPE = 'application/octet-stream';

    public function __construct(private readonly PluginFileStore $files)
    {
    }

    /** POST `/…/{name}`: store the request body as the named file. */
    public function upload(Request $request, array $params): Response
    {
        $name = (string) ($params['name'] ?? '');
        $body = $request->getBody();
        if (strlen($body) > self::MAX_UPLOAD_BYTES) {
            return Response::error('File is larger than the 10 MiB limit', 413);
        }

        try {
            $this->files->put($name, $body, $this->contentType($request->getHeader('Content-Type')));
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 400);
        } catch (\LogicException) {
            return Response::error('No workspace is in scope for this request', 403);
        } catch (\Throwable) {
            return Response::error('File could not be stored', 500);
        }

        return Response::json([
            'name' => $name,
            'size' => strlen($body),
            'content_type' => $this->contentType($request->getHeader('Content-Type')),
        ], 201);
    }

    /** GET `/…/{name}`: the named file as an attachment. */
    public function download(Request $request, array $params): Response
    {
        try {
            $file = $this->files->get((string) ($params['name'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 400);
        } catch (\LogicException) {
            return Response::error('No workspace is in scope for this request', 403);
        } catch (\Throwable) {
            return Response::error('File could not be read', 500);
        }

        if ($file === null) {
            return Response::error('File not found', 404);
        }

        $name = str_replace(['"', "\r", "\n"], '', $file->path);

        return new Response(200, $file->contents, [
            'Content-Type' => $file->contentType,
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** GET `/…`: the names of the plugin's files in this workspace. */
    public function list(Request $request, array $params): Response
    {
        try {
            return Response::json(['data' => $this->files->list()], 200);
        } catch (\LogicException) {
            return Response::error('No workspace is in scope for this request', 403);
        } catch (\Throwable) {
            return Response::error('Files could not be listed', 500);
        }
    }

    /** DELETE `/…/{name}`: remove the named file; deleting a missing one is not an error. */
    public function delete(Request $request, array $params): Response
    {
        try {
            $this->files->delete((string) ($params['name'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 400);
        } catch (\LogicException) {
            return Response::error('No workspace is in scope for this request', 403);
        } catch (\Throwable) {
            return Response::error('File could not be deleted', 500);
        }

        return new Response(204, '');
    }

    /** The client's type when it is a well-formed `type/subtype`, else octet-stream. */
    private function contentType(?string $header): string
    {
        $type = strtolower(trim(explode(';', (string) $header)[0]));

        return preg_match('#^[a-z0-9][a-z0-9.+-]{0,63}/[a-z0-9][a-z0-9.+-]{0,63}$#', $type) === 1
            ? $type
            : self::DEFAULT_TYPE;
    }
}
