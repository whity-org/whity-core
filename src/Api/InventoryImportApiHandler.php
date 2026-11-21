<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Contacts\ContactRejectedException;
use Whity\Core\Inventory\Import\InventoryImports;
use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Importing items and opening stock from a spreadsheet (WHIT-1322, design
 * section 34 §6), under `/api/inventory/imports/{kind}` where kind is `items`
 * or `opening_stock`. The router gates every route on `inventory:write`;
 * opening stock also needs `stock:adjust`, as typing it by hand does.
 */
final class InventoryImportApiHandler
{
    public function __construct(
        private readonly InventoryImports $imports,
        private readonly ?RoleChecker $roleChecker = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        return $this->run($request, $params, fn (int $tenantId, string $kind): Response => Response::json(['data' => $this->imports->recent($tenantId, $kind)]));
    }

    /** @param array<string, string> $params */
    public function upload(Request $request, array $params): Response
    {
        return $this->run($request, $params, function (int $tenantId, string $kind, ?int $actor) use ($request): Response {
            [$fileName, $bytes] = self::file($request);

            return Response::json(['data' => $this->imports->upload($tenantId, $kind, $actor, $fileName, $bytes)], 201);
        });
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        return $this->run($request, $params, fn (int $tenantId, string $kind): Response => Response::json(['data' => $this->imports->show($tenantId, $kind, (int) $params['id'])]));
    }

    /** @param array<string, string> $params */
    public function check(Request $request, array $params): Response
    {
        return $this->run($request, $params, fn (int $tenantId, string $kind): Response => Response::json([
            'data' => $this->imports->check($tenantId, $kind, (int) $params['id'], JsonBody::parsed($request)),
        ]));
    }

    /** @param array<string, string> $params */
    public function runImport(Request $request, array $params): Response
    {
        return $this->run($request, $params, fn (int $tenantId, string $kind, ?int $actor): Response => Response::json([
            'data' => $this->imports->run($tenantId, $kind, (int) $params['id'], $actor),
        ]));
    }

    /** @param array<string, string> $params */
    public function undo(Request $request, array $params): Response
    {
        return $this->run($request, $params, fn (int $tenantId, string $kind, ?int $actor): Response => Response::json([
            'data' => $this->imports->undo($tenantId, $kind, (int) $params['id'], $actor),
        ]));
    }

    /** @param array<string, string> $params */
    public function skippedCsv(Request $request, array $params): Response
    {
        return $this->run($request, $params, function (int $tenantId, string $kind) use ($params): Response {
            $csv = $this->imports->skippedCsv($tenantId, $kind, (int) $params['id']);

            return new Response(200, $csv, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="import-' . (int) $params['id'] . '-skipped.csv"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    /**
     * @param array<string, string> $params
     * @param callable(int, string, ?int): Response $work
     */
    private function run(Request $request, array $params, callable $work): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                return Response::error('A workspace is needed for inventory', 403);
            }
            $user = $request->user;
            $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;
            $kind = (string) ($params['kind'] ?? '');
            if (!isset(InventoryImports::KINDS[$kind])) {
                return Response::error('Import items or opening_stock', 404);
            }
            if ($kind === 'opening_stock' && !$this->may($tenantId, $actor, CorePermissions::STOCK_ADJUST)) {
                return Response::error('Importing opening stock needs the permission to adjust stock', 403);
            }

            return $work($tenantId, $kind, $actor);
        } catch (InventoryRejectedException $e) {
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (ContactRejectedException $e) {
            // The spreadsheet reader answers with this one.
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (\Throwable $e) {
            error_log('[InventoryImportApiHandler] failed: ' . $e->getMessage());

            return Response::error('Something went wrong with the import. Try again.', 500);
        }
    }

    private function may(int $tenantId, ?int $actor, string $permission): bool
    {
        return $this->roleChecker !== null && $actor !== null && $this->roleChecker->hasPermissionForProfile($actor, $permission, $tenantId);
    }

    /** @return array{0: string, 1: string} the file's name and bytes */
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
                throw new InventoryRejectedException('The uploaded file could not be read', 400);
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
                throw InventoryRejectedException::field('content_base64', 'content_base64 is not base64');
            }

            return [$name, $bytes];
        }

        throw InventoryRejectedException::field('file', 'Send the spreadsheet as a file, as text or as content_base64');
    }
}
