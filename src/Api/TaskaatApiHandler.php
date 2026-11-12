<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Audit\AuditLogger;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Taskaat\TaskaatConnection;
use Whity\Core\Taskaat\TaskaatException;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;

/**
 * The workspace's connection to Taskaat (A10, T.3).
 *
 * - GET    /api/taskaat/connection        (tasks:read)   whether this workspace is connected.
 * - POST   /api/taskaat/connect           (tasks:manage) Taskaat's consent page to send the person to.
 * - POST   /api/taskaat/connect/complete  (tasks:manage) finish with the code Taskaat sent back.
 * - DELETE /api/taskaat/connection        (tasks:manage) forget the connection.
 *
 * Connecting is a person's act: an agent's token is refused, since it would hand the agent a
 * credential to another service.
 */
final class TaskaatApiHandler
{
    public function __construct(
        private readonly TaskaatConnection $connection,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function status(Request $request, array $params = []): Response
    {
        return $this->run($request, false, fn (int $tenantId): Response => Response::json(['data' => $this->connection->status($tenantId)]));
    }

    /** @param array<string, string> $params */
    public function connect(Request $request, array $params = []): Response
    {
        return $this->run($request, true, function (int $tenantId, ?int $actor): Response {
            $url = $this->connection->start($tenantId, $actor);

            return Response::json(['data' => ['authorize_url' => $url]]);
        });
    }

    /** @param array<string, string> $params */
    public function complete(Request $request, array $params = []): Response
    {
        return $this->run($request, true, function (int $tenantId, ?int $actor) use ($request): Response {
            $body = json_decode($request->getBody(), true);
            $code = is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : '';
            $state = is_array($body) && is_string($body['state'] ?? null) ? $body['state'] : '';
            if ($code === '' || $state === '' || strlen($code) > 2048 || strlen($state) > 512) {
                return Response::error('code and state are required.', 422);
            }

            $status = $this->connection->complete($tenantId, $code, $state, $actor);
            $this->auditLogger?->record('taskaat.connected', [
                'target_type' => 'taskaat',
                'metadata' => ['taskaat_url' => $status['taskaat_url'] ?? null],
            ]);

            return Response::json(['data' => $status]);
        });
    }

    /** @param array<string, string> $params */
    public function disconnect(Request $request, array $params = []): Response
    {
        return $this->run($request, true, function (int $tenantId): Response {
            $this->connection->disconnect($tenantId);
            $this->auditLogger?->record('taskaat.disconnected', ['target_type' => 'taskaat']);

            return Response::json(['data' => $this->connection->status($tenantId)]);
        });
    }

    /**
     * @param callable(int, ?int): Response $work
     */
    private function run(Request $request, bool $peopleOnly, callable $work): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }
        $user = $request->user;
        if ($peopleOnly && is_object($user) && ($user->principal_kind ?? null) === McpPrincipal::KIND_AGENT) {
            return Response::error('Only a person can connect the workspace to Taskaat.', 403);
        }
        $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;

        try {
            return $work($tenantId, $actor);
        } catch (TaskaatException $e) {
            return Response::error($e->getMessage(), $e->status);
        } catch (\Throwable $e) {
            error_log('[TaskaatApiHandler] failed: ' . $e->getMessage());

            return Response::error('Taskaat could not be reached. Try again in a minute.', 500);
        }
    }
}
