<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\Issuing\ActingAs;
use Whity\Core\Document\Issuing\DocumentIssueRefused;
use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\Print\InventoryDocuments;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Printing inventory's papers (IV.20, WHIT-1234).
 *
 *  - `POST /api/inventory/purchase-orders/{id}/print` and
 *    `POST /api/inventory/receipts/{id}/print` issue the purchase order or the
 *    goods received note, `{lang: "en" | "ar"}`, and answer with its PDF. The
 *    document is kept like any other, and its id is in `X-Document-Id`. When
 *    this instance can't make PDFs, the document is still issued and the
 *    answer is JSON naming it, with 202.
 *  - `GET /api/inventory/print-templates` names the workspace's templates by
 *    document and language (seeding any it lacks), so the screens can print
 *    labels through the label sheet's own route and open a template to change.
 */
final class InventoryPrintApiHandler
{
    public function __construct(
        private readonly InventoryDocuments $documents,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    public function templates(Request $request): Response
    {
        return $this->run($request, function (int $tenantId): Response {
            return Response::json(['data' => $this->documents->templates($tenantId)]);
        });
    }

    /** @param array<string, string> $params */
    public function printPurchaseOrder(Request $request, array $params): Response
    {
        return $this->run($request, function (int $tenantId, int $profileId) use ($request, $params): Response {
            return $this->answer($this->documents->printOrder($this->actor($tenantId, $profileId), (int) $params['id'], self::lang($request)));
        });
    }

    /** @param array<string, string> $params */
    public function printReceipt(Request $request, array $params): Response
    {
        return $this->run($request, function (int $tenantId, int $profileId) use ($request, $params): Response {
            return $this->answer($this->documents->printReceipt($this->actor($tenantId, $profileId), (int) $params['id'], self::lang($request)));
        });
    }

    /**
     * @param array{document_id: int, number: string|null, title: string, replayed: bool, pdf: array{bytes: string, content_type: string}|null} $printed
     */
    private function answer(array $printed): Response
    {
        if ($printed['pdf'] === null) {
            return Response::json(['data' => [
                'document_id' => $printed['document_id'],
                'number' => $printed['number'],
                'title' => $printed['title'],
                'pdf' => false,
            ]], 202);
        }
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $printed['number'] ?? $printed['title']), '-');

        return new Response(200, $printed['pdf']['bytes'], [
            'Content-Type' => $printed['pdf']['content_type'],
            'Content-Disposition' => 'inline; filename="' . ($name !== '' ? $name : 'document') . '.pdf"',
            'X-Document-Id' => (string) $printed['document_id'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function actor(int $tenantId, int $profileId): ActingAs
    {
        $checker = $this->roleChecker;

        return new ActingAs(
            $tenantId,
            $profileId,
            static fn (string $permission): bool => $checker->hasPermissionForProfile($profileId, $permission, $tenantId),
        );
    }

    private static function lang(Request $request): string
    {
        $lang = JsonBody::parsed($request)['lang'] ?? 'en';
        if (!in_array($lang, ['en', 'ar'], true)) {
            throw InventoryRejectedException::field('lang', 'lang is "en" or "ar"');
        }

        return $lang;
    }

    /** @param callable(int, int): Response $work */
    private function run(Request $request, callable $work): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                return Response::error('A workspace is needed for inventory', 403);
            }
            $user = $request->user;
            $profileId = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;
            if ($profileId === null) {
                return Response::error('Printing needs a person signed in', 403);
            }

            return $work($tenantId, $profileId);
        } catch (InventoryRejectedException $e) {
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (DocumentIssueRefused $e) {
            return Response::error($e->clientMessage, $e->status, ['code' => $e->reason] + $e->details);
        } catch (\Throwable $e) {
            error_log('[InventoryPrintApiHandler] printing failed: ' . $e->getMessage());

            return Response::error('Something went wrong while printing. Try again.', 500);
        }
    }
}
