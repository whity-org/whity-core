<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Contacts\ContactRejectedException;
use Whity\Core\CustomFields\CustomFieldService;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * The workspace's own fields on its records (WHIT-954, design 3.3 A9,
 * `TplCustomFields`).
 *
 * PERMISSIONS
 * Listing a record type's fields needs that type's read permission (for
 * contacts, `contacts:read`), checked here because the type comes in the
 * query. Adding, changing and archiving fields needs `fields:manage`. Values
 * are read and written through the record's own API (`fields` on a contact).
 */
final class CustomFieldsApiHandler
{
    public function __construct(
        private readonly CustomFieldService $fields,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    public function recordTypes(Request $request): Response
    {
        return $this->run($request, 'types', function (int $tenantId, ?int $actor): Response {
            $visible = [];
            foreach ($this->fields->recordTypes($tenantId) as $type) {
                $def = $this->fields->type($type['key']);
                if ($this->can($tenantId, $actor, $def->readPermission)) {
                    $visible[] = $type;
                }
            }

            return Response::json(['data' => $visible]);
        });
    }

    public function list(Request $request): Response
    {
        return $this->run($request, 'list', function (int $tenantId, ?int $actor) use ($request): Response {
            $q = self::query($request);
            $recordType = $q['record_type'] ?? '';
            $type = $this->fields->type($recordType);
            if (!$this->can($tenantId, $actor, $type->readPermission)) {
                return Response::error('Insufficient permissions', 403, ['required' => $type->readPermission]);
            }

            return Response::json(['data' => $this->fields->definitions($tenantId, $recordType, ($q['archived'] ?? '') === 'true')]);
        });
    }

    public function create(Request $request): Response
    {
        return $this->run($request, 'create', function (int $tenantId, ?int $actor) use ($request): Response {
            return Response::json(['data' => $this->fields->create($tenantId, JsonBody::parsed($request), $actor)], 201);
        });
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        return $this->run($request, 'update', function (int $tenantId) use ($request, $params): Response {
            return Response::json(['data' => $this->fields->update($tenantId, (int) $params['id'], JsonBody::parsed($request))]);
        });
    }

    /** @param array<string, string> $params */
    public function archive(Request $request, array $params): Response
    {
        return $this->run($request, 'archive', function (int $tenantId) use ($params): Response {
            return Response::json(['data' => $this->fields->archive($tenantId, (int) $params['id'], true)]);
        });
    }

    /** @param array<string, string> $params */
    public function restore(Request $request, array $params): Response
    {
        return $this->run($request, 'restore', function (int $tenantId) use ($params): Response {
            return Response::json(['data' => $this->fields->archive($tenantId, (int) $params['id'], false)]);
        });
    }

    private function can(int $tenantId, ?int $actor, string $permission): bool
    {
        return $actor !== null && $this->roleChecker->hasPermissionForProfile($actor, $permission, $tenantId);
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
            error_log('[CustomFieldsApiHandler] ' . $area . ' failed: ' . $e->getMessage());

            return Response::error('Something went wrong with the fields. Try again.', 500);
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
