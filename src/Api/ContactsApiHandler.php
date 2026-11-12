<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Contacts\ContactRejectedException;
use Whity\Core\Contacts\ContactService;
use Whity\Core\Contacts\ContactTags;
use Whity\Core\Contacts\Import\ContactImportService;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Customers and suppliers, and importing them (WHIT-953, design 3.3 A8).
 *
 * PERMISSIONS
 * Reading on `contacts:read`; adding, changing, tagging and archiving on
 * `contacts:write`; imports on `contacts:import`.
 *
 * AGENTS
 * The routes are MCP tools. Adding a contact can be undone (it is archived),
 * so an agent may add one under its person's undo window; every other write,
 * and running or undoing an import, declares no undo and so is always a
 * proposal the person approves.
 *
 * Ids from another workspace answer 404, never 403.
 */
final class ContactsApiHandler
{
    private const MAX_PER_PAGE = 200;

    public function __construct(
        private readonly ContactService $contacts,
        private readonly ContactTags $tags,
        private readonly ContactImportService $imports,
        private readonly ?RoleChecker $roleChecker = null,
    ) {
    }

    public function list(Request $request): Response
    {
        return $this->run($request, 'list', function (int $tenantId) use ($request): Response {
            $q = self::query($request);
            $kind = $q['kind'] ?? null;
            if ($kind !== null && !in_array($kind, ['customer', 'supplier'], true)) {
                throw new ContactRejectedException('kind must be customer or supplier', 422, ['field' => 'kind']);
            }
            $page = max(1, (int) ($q['page'] ?? 1));
            $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($q['per_page'] ?? 50)));
            $filter = [
                'kind' => $kind,
                'tag_id' => isset($q['tag_id']) ? (int) $q['tag_id'] : null,
                'q' => $q['q'] ?? null,
                'archived' => ($q['archived'] ?? '') === 'true',
            ];
            $result = $this->contacts->list($tenantId, $filter, $perPage, ($page - 1) * $perPage, self::fieldFilters($request));

            return Response::json([
                'data' => $result['data'],
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPage,
                    'total' => $result['total'],
                    'totalPages' => (int) ceil($result['total'] / $perPage),
                ],
            ]);
        });
    }

    public function create(Request $request): Response
    {
        return $this->run($request, 'create', function (int $tenantId, ?int $actor) use ($request): Response {
            return Response::json(['data' => $this->contacts->create($tenantId, JsonBody::parsed($request), $actor, $this->permissions($tenantId, $actor))], 201);
        });
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        return $this->run($request, 'show', function (int $tenantId) use ($params): Response {
            return Response::json(['data' => $this->contacts->show($tenantId, (int) $params['id'])]);
        });
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        return $this->run($request, 'update', function (int $tenantId, ?int $actor) use ($request, $params): Response {
            return Response::json(['data' => $this->contacts->update($tenantId, (int) $params['id'], JsonBody::parsed($request), $this->permissions($tenantId, $actor))]);
        });
    }

    /** @param array<string, string> $params */
    public function archive(Request $request, array $params): Response
    {
        return $this->run($request, 'archive', function (int $tenantId) use ($params): Response {
            return Response::json(['data' => $this->contacts->archive($tenantId, (int) $params['id'], true)]);
        });
    }

    /** @param array<string, string> $params */
    public function restore(Request $request, array $params): Response
    {
        return $this->run($request, 'restore', function (int $tenantId) use ($params): Response {
            return Response::json(['data' => $this->contacts->archive($tenantId, (int) $params['id'], false)]);
        });
    }

    public function listTags(Request $request): Response
    {
        return $this->run($request, 'tags', function (int $tenantId): Response {
            return Response::json(['data' => $this->tags->listWithCounts($tenantId)]);
        });
    }

    // ── imports ─────────────────────────────────────────────────────────

    public function listImports(Request $request): Response
    {
        return $this->run($request, 'imports', function (int $tenantId): Response {
            return Response::json(['data' => $this->imports->recent($tenantId)]);
        });
    }

    /**
     * A spreadsheet as multipart `file`, or JSON: `file_name` with `text` (a
     * CSV's text) or `content_base64` (an Excel or CSV file's bytes).
     */
    public function upload(Request $request): Response
    {
        return $this->run($request, 'upload', function (int $tenantId, ?int $actor) use ($request): Response {
            [$fileName, $bytes] = self::file($request);

            return Response::json(['data' => $this->imports->upload($tenantId, $actor, $fileName, $bytes)], 201);
        });
    }

    /** @param array<string, string> $params */
    public function showImport(Request $request, array $params): Response
    {
        return $this->run($request, 'import', function (int $tenantId) use ($params): Response {
            return Response::json(['data' => $this->imports->show($tenantId, (int) $params['id'])]);
        });
    }

    /** @param array<string, string> $params */
    public function checkImport(Request $request, array $params): Response
    {
        return $this->run($request, 'check', function (int $tenantId) use ($request, $params): Response {
            return Response::json(['data' => $this->imports->check($tenantId, (int) $params['id'], JsonBody::parsed($request))]);
        });
    }

    /** @param array<string, string> $params */
    public function runImport(Request $request, array $params): Response
    {
        return $this->run($request, 'run', function (int $tenantId, ?int $actor) use ($params): Response {
            return Response::json(['data' => $this->imports->run($tenantId, (int) $params['id'], $actor)]);
        });
    }

    /** @param array<string, string> $params */
    public function undoImport(Request $request, array $params): Response
    {
        return $this->run($request, 'undo', function (int $tenantId) use ($params): Response {
            return Response::json(['data' => $this->imports->undo($tenantId, (int) $params['id'])]);
        });
    }

    /** @param array<string, string> $params */
    public function skippedCsv(Request $request, array $params): Response
    {
        return $this->run($request, 'skipped', function (int $tenantId) use ($params): Response {
            $csv = $this->imports->skippedCsv($tenantId, (int) $params['id']);

            return new Response(200, $csv, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="import-' . (int) $params['id'] . '-skipped.csv"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    /**
     * @param callable(int, ?int): Response $work
     */
    private function run(Request $request, string $area, callable $work): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }
            $user = $request->user;
            $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;

            return $work($tenantId, $actor);
        } catch (ContactRejectedException $e) {
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (\Throwable $e) {
            error_log('[ContactsApiHandler] ' . $area . ' failed: ' . $e->getMessage());

            return Response::error('Something went wrong with contacts. Try again.', 500);
        }
    }

    /** @return array{0: string, 1: string} */
    private static function file(Request $request): array
    {
        try {
            $files = $request->getUploadedFiles();
        } catch (\Throwable) {
            $files = [];
        }
        $file = $files['file'] ?? null;
        if ($file !== null) {
            $path = $file->getStreamPath();
            $bytes = is_file($path) ? file_get_contents($path) : false;
            if ($bytes === false) {
                throw new ContactRejectedException('The uploaded file could not be read', 400);
            }

            return [(string) ($file->getClientFilename() ?? 'import'), $bytes];
        }

        $body = JsonBody::parsed($request);
        $name = is_string($body['file_name'] ?? null) ? $body['file_name'] : 'import.csv';
        if (is_string($body['text'] ?? null)) {
            return [$name, $body['text']];
        }
        if (is_string($body['content_base64'] ?? null)) {
            $bytes = base64_decode($body['content_base64'], true);
            if ($bytes === false) {
                throw new ContactRejectedException('content_base64 is not base64', 422, ['field' => 'content_base64']);
            }

            return [$name, $bytes];
        }

        throw new ContactRejectedException('Send the spreadsheet as a file, or as text or content_base64', 422, ['field' => 'file']);
    }

    /**
     * The caller's permissions in this workspace, for fields that need one of
     * their own to change.
     *
     * @return callable(string): bool
     */
    private function permissions(int $tenantId, ?int $actor): callable
    {
        $checker = $this->roleChecker;

        return static fn (string $permission): bool => $checker !== null && $actor !== null
            && $checker->hasPermissionForProfile($actor, $permission, $tenantId);
    }

    /**
     * `field[<key>]=value` from the query string, as key => value.
     *
     * @return array<string, string>
     */
    private static function fieldFilters(Request $request): array
    {
        $raw = $_GET['field'] ?? null;
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            if (is_array($parsed['field'] ?? null)) {
                $raw = $parsed['field'];
            }
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
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
