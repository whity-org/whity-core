<?php

declare(strict_types=1);

namespace Whity\Sdk\Templates;

use Whity\Sdk\Setup\WorkspaceSetup;

/**
 * A {@see WorkspaceSetup} that changes nothing (SDK 1.169, WHIT-1409): the
 * preview of a workspace template.
 *
 * Reads go to the real routes, so the plan sees what the workspace really
 * has. Writes are recorded and answered as if they worked, with a made-up
 * negative id, unless `$mayWrite` says the person could not make that call;
 * then they are answered 403, as the real route would. Settings it was asked
 * to change read back changed, so the rest of the plan sees them.
 */
final class DryRunWorkspaceSetup implements WorkspaceSetup
{
    /** @var list<array{method: string, path: string, body: array<string, mixed>}> */
    private array $writes = [];

    /** @var array<string, mixed> */
    private array $settings = [];

    private int $nextId = -1;

    /** @param (\Closure(string, string): bool)|null $mayWrite may the person make this call (METHOD, path with ids) */
    public function __construct(
        private readonly WorkspaceSetup $real,
        private readonly ?\Closure $mayWrite = null,
    ) {
    }

    public function call(string $method, string $path, array $body = []): array
    {
        $method = strtoupper($method);
        if ($method === 'GET') {
            $result = $this->real->call($method, $path, $body);

            return $path === '/api/settings' ? $this->withSettings($result) : $result;
        }
        // A path holding an id this dry run made up checks as id 0.
        $checked = (string) preg_replace('#/-\d+(?=/|$)#', '/0', $path);
        if ($this->mayWrite !== null && !($this->mayWrite)($method, $checked)) {
            return ['status' => 403, 'body' => ['error' => 'You do not have permission to do this.']];
        }
        $this->writes[] = ['method' => $method, 'path' => $path, 'body' => $body];
        if ($method === 'PATCH' && $path === '/api/settings' && is_array($body['settings'] ?? null)) {
            $this->settings = array_merge($this->settings, $body['settings']);
        }
        if ($method === 'POST' && preg_match('#^/api/[a-z-]+(/[a-z-]+)?$#', $path) === 1) {
            return ['status' => 201, 'body' => ['data' => ['id' => $this->nextId--]]];
        }

        return ['status' => 200, 'body' => ['data' => []]];
    }

    /**
     * The writes the plan would make, in order.
     *
     * @return list<array{method: string, path: string, body: array<string, mixed>}>
     */
    public function writes(): array
    {
        return $this->writes;
    }

    /**
     * @param array{status: int, body: mixed} $result
     * @return array{status: int, body: mixed}
     */
    private function withSettings(array $result): array
    {
        if ($this->settings === [] || !is_array($result['body']) || !is_array($result['body']['data'] ?? null)) {
            return $result;
        }
        $data = $result['body']['data'];
        $data['effective'] = array_merge(is_array($data['effective'] ?? null) ? $data['effective'] : [], $this->settings);
        $overridden = is_array($data['overridden'] ?? null) ? $data['overridden'] : [];
        $data['overridden'] = array_values(array_unique([...$overridden, ...array_keys($this->settings)]));
        $result['body']['data'] = $data;

        return $result;
    }
}
