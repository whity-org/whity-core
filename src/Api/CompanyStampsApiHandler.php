<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Document\Signing\CompanyStampRepository;
use Whity\Core\Document\Signing\StampImage;
use Whity\Core\Document\Signing\StampImageRejectedException;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * A workspace's company stamps (#1729):
 *
 *   GET  /api/document-stamps                    (documents:read)
 *   POST /api/document-stamps                    (documents:stamps)
 *   GET  /api/document-stamps/{id}               (documents:read)
 *   PATCH /api/document-stamps/{id}              (documents:stamps)
 *   PUT  /api/document-stamps/{id}/holders       (documents:stamps)
 *   POST /api/document-stamps/{id}/archive       (documents:stamps)
 *   POST /api/document-stamps/{id}/restore       (documents:stamps)
 *
 * A stamp is a name, the legal name it stands for, an image, and its holders
 * (named people or roles). Only a holder can apply it on a route's `stamp`
 * step. Applying it is an authorised person applying the stamp's image, not a
 * certified electronic seal.
 *
 * Anyone who reads documents can list the stamps, so a route's author can
 * choose one; only `documents:stamps` changes them. Archived, never deleted.
 */
final class CompanyStampsApiHandler
{
    private const MAX_HOLDERS = 200;

    public function __construct(private readonly CompanyStampRepository $stamps)
    {
    }

    public function list(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        parse_str((string) parse_url($request->getPath(), PHP_URL_QUERY), $query);
        $archived = ($query['archived'] ?? '') === 'true';

        return Response::json(['data' => $this->stamps->list($tenantId, $archived)]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $stamp = $this->stamps->find($tenantId, (int) ($params['id'] ?? 0));

        return $stamp === null ? Response::error('Stamp not found', 404) : Response::json(['data' => $stamp]);
    }

    public function create(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $body = JsonBody::parsed($request);

        $name = self::text($body['name'] ?? null, 200);
        $legalName = self::text($body['legal_name'] ?? null, 300);
        if ($name === null) {
            return Response::error('Give the stamp a name of 200 characters or fewer.', 422, ['field' => 'name']);
        }
        if ($legalName === null) {
            return Response::error('Give the legal name the stamp stands for, in 300 characters or fewer.', 422, ['field' => 'legal_name']);
        }
        try {
            $image = StampImage::normalise($body['image'] ?? null);
        } catch (StampImageRejectedException $e) {
            return Response::error($e->clientMessage, 422, ['field' => 'image']);
        }
        $holders = $this->holders($tenantId, $body['holders'] ?? ['people' => [], 'roles' => []]);
        if ($holders instanceof Response) {
            return $holders;
        }

        $id = $this->stamps->create($tenantId, $name, $legalName, $image, self::callerId($request));
        $this->stamps->setHolders($tenantId, $id, $holders[0], $holders[1]);

        return Response::json(['data' => $this->stamps->find($tenantId, $id)], 201);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $id = (int) ($params['id'] ?? 0);
        if ($this->stamps->find($tenantId, $id) === null) {
            return Response::error('Stamp not found', 404);
        }
        $body = JsonBody::parsed($request);

        $changes = [];
        if (array_key_exists('name', $body)) {
            $name = self::text($body['name'], 200);
            if ($name === null) {
                return Response::error('Give the stamp a name of 200 characters or fewer.', 422, ['field' => 'name']);
            }
            $changes['name'] = $name;
        }
        if (array_key_exists('legal_name', $body)) {
            $legalName = self::text($body['legal_name'], 300);
            if ($legalName === null) {
                return Response::error('Give the legal name the stamp stands for, in 300 characters or fewer.', 422, ['field' => 'legal_name']);
            }
            $changes['legal_name'] = $legalName;
        }
        if (array_key_exists('image', $body)) {
            try {
                $changes['image'] = StampImage::normalise($body['image']);
            } catch (StampImageRejectedException $e) {
                return Response::error($e->clientMessage, 422, ['field' => 'image']);
            }
        }
        $this->stamps->update($tenantId, $id, $changes);

        return Response::json(['data' => $this->stamps->find($tenantId, $id)]);
    }

    /** @param array<string, string> $params */
    public function setHolders(Request $request, array $params): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $id = (int) ($params['id'] ?? 0);
        if ($this->stamps->find($tenantId, $id) === null) {
            return Response::error('Stamp not found', 404);
        }
        $holders = $this->holders($tenantId, JsonBody::parsed($request));
        if ($holders instanceof Response) {
            return $holders;
        }
        $this->stamps->setHolders($tenantId, $id, $holders[0], $holders[1]);

        return Response::json(['data' => $this->stamps->find($tenantId, $id)]);
    }

    /** @param array<string, string> $params */
    public function archive(Request $request, array $params): Response
    {
        return $this->setArchived($params, true);
    }

    /** @param array<string, string> $params */
    public function restore(Request $request, array $params): Response
    {
        return $this->setArchived($params, false);
    }

    /** @param array<string, string> $params */
    private function setArchived(array $params, bool $archived): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $id = (int) ($params['id'] ?? 0);
        if ($this->stamps->find($tenantId, $id) === null) {
            return Response::error('Stamp not found', 404);
        }
        $this->stamps->setArchived($tenantId, $id, $archived);

        return Response::json(['data' => $this->stamps->find($tenantId, $id)]);
    }

    /**
     * `{people: [profile ids], roles: [role ids]}`, each a member or role of this workspace.
     *
     * @return array{0: list<int>, 1: list<int>}|Response
     */
    private function holders(int $tenantId, mixed $value): array|Response
    {
        if (!is_array($value)) {
            return Response::error("'holders' must be {people: [...], roles: [...]}.", 422, ['field' => 'holders']);
        }
        $people = $value['people'] ?? [];
        $roles = $value['roles'] ?? [];
        if (!is_array($people) || !is_array($roles) || !array_is_list($people) || !array_is_list($roles)) {
            return Response::error("'holders' must be {people: [...], roles: [...]}.", 422, ['field' => 'holders']);
        }
        foreach (array_merge($people, $roles) as $id) {
            if (!is_int($id) || $id < 1) {
                return Response::error('Holders are named by their ids.', 422, ['field' => 'holders']);
            }
        }
        /** @var list<int> $people */
        /** @var list<int> $roles */
        if (count($people) + count($roles) > self::MAX_HOLDERS) {
            return Response::error('A stamp can have at most 200 holders. Use a role instead.', 422, ['field' => 'holders']);
        }
        if (!$this->stamps->allMembers($tenantId, $people)) {
            return Response::error('Choose holders who are in this workspace.', 422, ['field' => 'holders']);
        }
        if (!$this->stamps->allRoles($tenantId, $roles)) {
            return Response::error('Choose roles this workspace has.', 422, ['field' => 'holders']);
        }

        return [array_values(array_unique($people)), array_values(array_unique($roles))];
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' || mb_strlen($value) > $max ? null : $value;
    }

    private static function callerId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
    }
}
