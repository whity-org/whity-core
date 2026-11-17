<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Security\GatedApproval;
use Whity\Core\Tenant\OwnershipException;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Tenant\WorkspaceOwnership;
use Whity\Http\JsonBody;

/**
 * Owners of a workspace, and offers of ownership (WHIT-1170, WHIT-1171).
 *
 * In the workspace the caller is signed in to (any active staff member may
 * read; only an owner may act):
 *   GET    /api/v1/workspace/owners                       → owners, open offers, whether I own it
 *   POST   /api/v1/workspace/owners/offers/identity-check  → { to_profile_id, kind }: start "Confirm it's you"
 *   POST   /api/v1/workspace/owners/offers                 → { to_profile_id, kind, leave_after?, code }
 *   DELETE /api/v1/workspace/owners/offers/{id}            → withdraw an open offer
 *   POST   /api/v1/workspace/owners/{profileId}/identity-check
 *   DELETE /api/v1/workspace/owners/{profileId}            → { code }: remove an owner, or stop being one
 *
 * The person offered, in whichever workspace they are signed in to (the
 * question is about them, so these are self-authenticating, like
 * /api/me/identities):
 *   GET  /api/v1/me/ownership-offers                 → my open offers
 *   GET  /api/v1/me/ownership-offers/{id}            → one of mine (open or decided)
 *   POST /api/v1/me/ownership-offers/{id}/identity-check
 *   POST /api/v1/me/ownership-offers/{id}/accept     → { code }
 *   POST /api/v1/me/ownership-offers/{id}/decline
 *
 * Making and accepting an offer are confirmed with GatedApproval
 * ({@see GatedApproval::WORKSPACE_OWNERSHIP}): the code is bound to exactly that
 * offer (or, before it exists, to that workspace, person and kind).
 */
final class WorkspaceOwnersApiHandler
{
    public function __construct(
        private readonly WorkspaceOwnership $ownership,
        private readonly TokenValidator $tokenValidator,
        private readonly ?GatedApproval $gate,
    ) {
    }

    public function list(Request $request): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;
        if (!$this->ownership->isStaff($tenantId, $profileId)) {
            return Response::error('Only people in this workspace can see its owners', 403);
        }
        $owners = $this->ownership->owners()->list($tenantId);
        $isOwner = in_array($profileId, array_column($owners, 'profile_id'), true);

        return Response::json(['data' => [
            'owners' => array_map(static fn (array $o): array => [
                'profile_id' => $o['profile_id'],
                'name'       => $o['display_name'],
                'email'      => $o['email'],
                'since'      => $o['since'],
            ], $owners),
            // Only owners see the open offers, and who they could offer it to.
            'offers'     => $isOwner ? array_map([self::class, 'offerOut'], $this->ownership->openForTenant($tenantId)) : [],
            'candidates' => $isOwner ? $this->ownership->candidates($tenantId) : [],
            'is_owner' => $isOwner,
        ]])->withHeaders(['Cache-Control' => 'no-store']);
    }

    public function offerIdentityCheck(Request $request): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;
        $input = $this->offerInput(JsonBody::parsed($request));
        if ($input instanceof Response) {
            return $input;
        }
        [$toId, $kind] = $input;
        try {
            $this->ownership->checkOffer($tenantId, $profileId, $toId);
        } catch (OwnershipException $e) {
            return self::refused($e);
        }
        return GatedApprovalHttp::start($this->gate, $tenantId, $profileId, GatedApproval::WORKSPACE_OWNERSHIP, self::offerSubject($tenantId, $toId, $kind));
    }

    public function offer(Request $request): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;
        $body = JsonBody::parsed($request);
        $input = $this->offerInput($body);
        if ($input instanceof Response) {
            return $input;
        }
        [$toId, $kind] = $input;
        $leaveAfter = ($body['leave_after'] ?? false) === true;

        try {
            $this->ownership->checkOffer($tenantId, $profileId, $toId);
        } catch (OwnershipException $e) {
            return self::refused($e);
        }
        $method = GatedApprovalHttp::confirm($this->gate, $tenantId, $profileId, GatedApproval::WORKSPACE_OWNERSHIP, self::offerSubject($tenantId, $toId, $kind), $body);
        if ($method instanceof Response) {
            return $method;
        }
        try {
            $offer = $this->ownership->offer($tenantId, $profileId, $toId, $kind, $leaveAfter);
        } catch (OwnershipException $e) {
            return self::refused($e);
        }
        return Response::json(['data' => self::offerOut($offer)], 201);
    }

    /** @param array<string, string> $params */
    public function cancel(Request $request, array $params = []): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;
        try {
            $done = $this->ownership->cancel($tenantId, (int) ($params['id'] ?? 0), $profileId);
        } catch (OwnershipException $e) {
            return self::refused($e);
        }
        return $done
            ? Response::json([], 204)
            : Response::error('That offer is no longer open', 409, ['code' => OwnershipException::NOT_OPEN]);
    }

    /** @param array<string, string> $params */
    public function removeIdentityCheck(Request $request, array $params = []): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;
        $target = (int) ($params['profileId'] ?? 0);
        $owners = array_column($this->ownership->owners()->list($tenantId), 'profile_id');
        if (!in_array($profileId, $owners, true)) {
            return self::refused(new OwnershipException(OwnershipException::NOT_OWNER));
        }
        if (!in_array($target, $owners, true)) {
            return self::refused(new OwnershipException(OwnershipException::NOT_FOUND));
        }
        if (count($owners) <= 1) {
            return self::refused(new OwnershipException(OwnershipException::LAST_OWNER));
        }
        return GatedApprovalHttp::start($this->gate, $tenantId, $profileId, GatedApproval::WORKSPACE_OWNERSHIP, self::removeSubject($tenantId, $target));
    }

    /**
     * Remove an owner, or stop being one (WHIT-1171). Body: { code }.
     *
     * @param array<string, string> $params
     */
    public function removeOwner(Request $request, array $params = []): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;
        $target = (int) ($params['profileId'] ?? 0);
        if (!$this->ownership->owners()->isOwner($tenantId, $profileId)) {
            return self::refused(new OwnershipException(OwnershipException::NOT_OWNER));
        }
        $method = GatedApprovalHttp::confirm($this->gate, $tenantId, $profileId, GatedApproval::WORKSPACE_OWNERSHIP, self::removeSubject($tenantId, $target), JsonBody::parsed($request));
        if ($method instanceof Response) {
            return $method;
        }
        try {
            $this->ownership->removeOwner($tenantId, $profileId, $target);
        } catch (OwnershipException $e) {
            return self::refused($e);
        }
        return Response::json([], 204);
    }

    public static function removeSubject(int $tenantId, int $profileId): string
    {
        return 'remove-' . $tenantId . '-' . $profileId;
    }

    public function mine(Request $request): Response
    {
        $profileId = $this->person($request);
        if ($profileId === null) {
            return Response::error('Authentication required', 401);
        }
        return Response::json(['data' => array_map([self::class, 'offerOut'], $this->ownership->openForPerson($profileId))])
            ->withHeaders(['Cache-Control' => 'no-store']);
    }

    /** @param array<string, string> $params */
    public function showMine(Request $request, array $params = []): Response
    {
        $offer = $this->myOffer($request, $params);
        if ($offer instanceof Response) {
            return $offer;
        }
        return Response::json(['data' => self::offerOut($offer)])->withHeaders(['Cache-Control' => 'no-store']);
    }

    /** @param array<string, string> $params */
    public function acceptIdentityCheck(Request $request, array $params = []): Response
    {
        $offer = $this->myOffer($request, $params);
        if ($offer instanceof Response) {
            return $offer;
        }
        if ($offer['status'] !== WorkspaceOwnership::STATUS_OPEN) {
            return Response::error('That offer is no longer open', 409, ['code' => OwnershipException::NOT_OPEN]);
        }
        return GatedApprovalHttp::start($this->gate, (int) $offer['tenant_id'], (int) $offer['to_profile_id'], GatedApproval::WORKSPACE_OWNERSHIP, self::acceptSubject((int) $offer['id']));
    }

    /** @param array<string, string> $params */
    public function accept(Request $request, array $params = []): Response
    {
        $offer = $this->myOffer($request, $params);
        if ($offer instanceof Response) {
            return $offer;
        }
        if ($offer['status'] !== WorkspaceOwnership::STATUS_OPEN) {
            return Response::error('That offer is no longer open', 409, ['code' => OwnershipException::NOT_OPEN]);
        }
        $method = GatedApprovalHttp::confirm(
            $this->gate,
            (int) $offer['tenant_id'],
            (int) $offer['to_profile_id'],
            GatedApproval::WORKSPACE_OWNERSHIP,
            self::acceptSubject((int) $offer['id']),
            JsonBody::parsed($request)
        );
        if ($method instanceof Response) {
            return $method;
        }
        try {
            $accepted = $this->ownership->accept((int) $offer['id'], (int) $offer['to_profile_id']);
        } catch (OwnershipException $e) {
            return self::refused($e);
        }
        return Response::json(['data' => self::offerOut($accepted)]);
    }

    /** @param array<string, string> $params */
    public function decline(Request $request, array $params = []): Response
    {
        $offer = $this->myOffer($request, $params);
        if ($offer instanceof Response) {
            return $offer;
        }
        try {
            $done = $this->ownership->decline((int) $offer['id'], (int) $offer['to_profile_id']);
        } catch (OwnershipException $e) {
            return self::refused($e);
        }
        return $done
            ? Response::json([], 204)
            : Response::error('That offer is no longer open', 409, ['code' => OwnershipException::NOT_OPEN]);
    }

    public static function offerSubject(int $tenantId, int $toId, string $kind): string
    {
        return 'offer-' . $tenantId . '-' . $toId . '-' . $kind;
    }

    public static function acceptSubject(int $offerId): string
    {
        return 'accept-' . $offerId;
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private static function offerOut(array $offer): array
    {
        return [
            'id'             => $offer['id'],
            'tenant_id'      => $offer['tenant_id'],
            'workspace_name' => $offer['tenant_name'],
            'kind'           => $offer['kind'],
            'from'           => ['profile_id' => $offer['from_profile_id'], 'name' => $offer['from_name']],
            'to'             => ['profile_id' => $offer['to_profile_id'], 'name' => $offer['to_name']],
            'leave_after'    => $offer['leave_after'],
            'status'         => $offer['status'],
            'expires_at'     => $offer['expires_at'],
            'created_at'     => $offer['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: string}|Response
     */
    private function offerInput(array $body): array|Response
    {
        $toId = $body['to_profile_id'] ?? null;
        $kind = $body['kind'] ?? null;
        if (!is_int($toId) || $toId <= 0) {
            return Response::error('Choose a person', 422, ['code' => 'person_required']);
        }
        if (!in_array($kind, [WorkspaceOwnership::KIND_TRANSFER, WorkspaceOwnership::KIND_CO_OWNER], true)) {
            return Response::error('Choose whether to hand it over or add a co-owner', 422, ['code' => OwnershipException::INVALID]);
        }
        return [$toId, $kind];
    }

    private static function refused(OwnershipException $e): Response
    {
        $words = [
            OwnershipException::SELF          => 'You already own it',
            OwnershipException::NOT_OWNER     => 'Only an owner can do this',
            OwnershipException::NOT_MEMBER    => 'That person isn\'t an active member of this workspace',
            OwnershipException::ALREADY_OWNER => 'That person already owns this workspace',
            OwnershipException::PENDING       => 'That person already has an offer waiting',
            OwnershipException::NOT_FOUND     => 'Offer not found',
            OwnershipException::NOT_OPEN      => 'That offer is no longer open',
            OwnershipException::EXPIRED       => 'That offer has expired',
            OwnershipException::LAST_OWNER    => 'A workspace always keeps at least one owner',
        ];
        return Response::error($words[$e->reason] ?? 'Not possible', $e->status(), ['code' => $e->reason]);
    }

    /**
     * The person's own offer, or 404 (another person's offer looks the same as
     * none at all).
     *
     * @param array<string, string> $params
     * @return array<string, mixed>|Response
     */
    private function myOffer(Request $request, array $params): array|Response
    {
        $profileId = $this->person($request);
        if ($profileId === null) {
            return Response::error('Authentication required', 401);
        }
        $offer = $this->ownership->find((int) ($params['id'] ?? 0));
        if ($offer === null || $offer['to_profile_id'] !== $profileId) {
            return Response::error('Offer not found', 404, ['code' => OwnershipException::NOT_FOUND]);
        }
        return $offer;
    }

    /** @return array{0: int, 1: int}|Response */
    private function caller(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        $actor = $request->user;
        $profileId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($tenantId === null || $profileId === null) {
            return Response::error('Unauthenticated', 401);
        }
        if ($tenantId === 0) {
            return Response::error('The platform workspace has no owners', 422, ['code' => 'system_tenant']);
        }
        return [$tenantId, $profileId];
    }

    private function person(Request $request): ?int
    {
        // The router authenticates these routes; the token is the fallback.
        $actor = $request->user;
        if (is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) && $actor->profile_id > 0) {
            return $actor->profile_id;
        }
        $claims = $this->tokenValidator->validateAccessToken();
        if ($claims === null) {
            $header = $request->getHeader('Authorization') ?? '';
            if (stripos($header, 'Bearer ') === 0) {
                $claims = $this->tokenValidator->validateAccessTokenFromBearer(substr($header, 7));
            }
        }
        $profileId = $claims['profile_id'] ?? null;
        return is_int($profileId) && $profileId > 0 ? $profileId : null;
    }
}
