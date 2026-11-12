<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Document\SavedBlock\SavedBlockRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Saved blocks (editor M7; E.21-E.24, E.38; IMPLEMENTATION-NOTES 3.2):
 *
 *   GET    /api/saved-blocks                          documents:read
 *   POST   /api/saved-blocks                          documents:write (+ documents:publish for workspace)
 *   GET    /api/saved-blocks/{id}                     documents:read
 *   PATCH  /api/saved-blocks/{id}                     documents:write (owner, or documents:publish for workspace ones)
 *   DELETE /api/saved-blocks/{id}[?force=1]           documents:write (same; force needs documents:publish)
 *   GET    /api/saved-blocks/{id}/versions/{version}  documents:read
 *   GET    /api/saved-blocks/{id}/usage               documents:read
 *
 * A person sees their own `me` blocks and every `workspace` block; anyone
 * else's `me` block answers 404. Managing the workspace library (E.22, the
 * register's `documents:library:manage`, not minted yet) uses
 * `documents:publish` for now: sharing a block with the workspace, and
 * changing or deleting someone else's workspace block.
 *
 * Deleting archives: the row and its versions stay, so documents and
 * published templates using it still render (E.38). A synced block still
 * used answers 409 `in_use` unless a documents:publish holder sends force=1.
 */
final class SavedBlocksApiHandler
{
    private const MAX_NAME = 120;
    private const MAX_DESCRIPTION = 1000;
    private const MAX_BLOCKS = 200;
    private const MAX_BLOCKS_BYTES = 262144;
    private const MAX_KEYS = 200;
    private const MAX_KEY = 120;
    private const MAX_ID = 200;

    public function __construct(
        private readonly SavedBlockRepository $repo,
        private readonly DocumentSourcesApiHandler $sources,
        private readonly RoleChecker $roleChecker,
        private readonly ?AuditLoggerInterface $audit = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        $ctx = $this->context($request, CorePermissions::DOCUMENTS_READ);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        return Response::json(['data' => array_map(
            static fn (array $row): array => self::present($row),
            $this->repo->listVisible($tenantId, $callerId),
        )]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        $ctx = $this->context($request, CorePermissions::DOCUMENTS_WRITE);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId, $can] = $ctx;
        $body = JsonBody::parsed($request);

        $name = self::name($body['name'] ?? null);
        if ($name instanceof Response) {
            return $name;
        }
        $description = self::description($body['description'] ?? null);
        if ($description instanceof Response) {
            return $description;
        }
        $mode = $body['mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, SavedBlockRepository::MODES, true)) {
            return Response::error('mode must be synced or copy', 422);
        }
        $sharing = $body['sharing'] ?? SavedBlockRepository::SHARING_ME;
        if (!is_string($sharing) || !in_array($sharing, SavedBlockRepository::SHARINGS, true)) {
            return Response::error('sharing must be me or workspace', 422);
        }
        if ($sharing === SavedBlockRepository::SHARING_WORKSPACE && !$can(CorePermissions::DOCUMENTS_PUBLISH)) {
            return self::libraryRefused();
        }
        $blocks = self::blocks($body['blocks'] ?? null);
        if ($blocks instanceof Response) {
            return $blocks;
        }
        $keys = self::keys(self::keysField($body));
        if ($keys instanceof Response) {
            return $keys;
        }

        $id = $this->repo->create($tenantId, [
            'name'              => $name,
            'description'       => $description,
            'mode'              => $mode,
            'sharing'           => $sharing,
            'owner_profile_id'  => $callerId,
            'blocks'            => $blocks,
            'per_document_keys' => $keys,
        ]);
        $this->audit?->record('documents.saved_block.created', [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $callerId,
            'target_type'   => 'saved_block',
            'target_id'     => $id,
            'metadata'      => ['name' => $name, 'mode' => $mode, 'sharing' => $sharing, 'version' => 1, 'blocks' => count($blocks)],
        ]);
        $row = $this->repo->find($id, $tenantId);

        return Response::json(['data' => $row !== null ? self::present($row) : null], 201);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $found = $this->visible($request, $params, CorePermissions::DOCUMENTS_READ);
        if ($found instanceof Response) {
            return $found;
        }

        return Response::json(['data' => self::present($found[3])]);
    }

    /**
     * Rename, describe, share, or change the blocks. A blocks change bumps
     * the version and keeps the old one (E.38); the same blocks again change
     * nothing.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $found = $this->visible($request, $params, CorePermissions::DOCUMENTS_WRITE);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $can, $row] = $found;
        if (!self::mayManage($row, $callerId, $can)) {
            return self::libraryRefused();
        }
        if ($row['archived_at'] !== null) {
            return Response::error('This saved block was deleted', 409, ['code' => 'archived']);
        }
        $body = JsonBody::parsed($request);

        if (array_key_exists('mode', $body) && $body['mode'] !== $row['mode']) {
            return Response::error('mode can\'t change; insert a copy or detach it in the document instead', 422);
        }
        $name = array_key_exists('name', $body) ? self::name($body['name']) : $row['name'];
        if ($name instanceof Response) {
            return $name;
        }
        $description = array_key_exists('description', $body) ? self::description($body['description']) : $row['description'];
        if ($description instanceof Response) {
            return $description;
        }
        $sharing = $body['sharing'] ?? $row['sharing'];
        if (!is_string($sharing) || !in_array($sharing, SavedBlockRepository::SHARINGS, true)) {
            return Response::error('sharing must be me or workspace', 422);
        }
        if ($sharing === SavedBlockRepository::SHARING_WORKSPACE && $row['sharing'] !== $sharing && !$can(CorePermissions::DOCUMENTS_PUBLISH)) {
            return self::libraryRefused();
        }
        $blocks = null;
        if (array_key_exists('blocks', $body)) {
            $blocks = self::blocks($body['blocks']);
            if ($blocks instanceof Response) {
                return $blocks;
            }
        }
        $keys = $row['per_document_keys'];
        $keysField = self::keysField($body);
        if ($keysField !== null) {
            $keys = self::keys($keysField);
            if ($keys instanceof Response) {
                return $keys;
            }
        }
        $expected = $body['version'] ?? null;
        if ($expected !== null && (!is_int($expected) || $expected !== $row['version'])) {
            return Response::error('Someone changed this saved block; reload it', 409, ['code' => 'changed', 'version' => $row['version']]);
        }

        $id = (int) $row['id'];
        $changed = [];
        $version = (int) $row['version'];
        if ($blocks !== null && $blocks !== $row['blocks']) {
            $next = $this->repo->replaceBlocks($id, $tenantId, $version, $blocks, $keys);
            if ($next === null) {
                return Response::error('Someone changed this saved block; reload it', 409, ['code' => 'changed']);
            }
            $version = $next;
            $changed[] = 'blocks';
            if ($keys !== $row['per_document_keys']) {
                $changed[] = 'per_document_keys';
            }
        } elseif ($keys !== $row['per_document_keys']) {
            $this->repo->updatePerDocumentKeys($id, $tenantId, $keys);
            $changed[] = 'per_document_keys';
        }
        // After the blocks, so a lost race (409 above) changes nothing at all.
        if ($name !== $row['name'] || $description !== $row['description'] || $sharing !== $row['sharing']) {
            $this->repo->updateDetails($id, $tenantId, ['name' => $name, 'description' => $description, 'sharing' => $sharing]);
            foreach (['name' => $name, 'description' => $description, 'sharing' => $sharing] as $field => $value) {
                if ($value !== $row[$field]) {
                    $changed[] = $field;
                }
            }
        }

        if ($changed !== []) {
            $this->audit?->record('documents.saved_block.updated', [
                'tenant_id'     => $tenantId,
                'actor_user_id' => $callerId,
                'target_type'   => 'saved_block',
                'target_id'     => $id,
                'metadata'      => ['name' => $name, 'mode' => $row['mode'], 'sharing' => $sharing, 'changed' => $changed, 'version' => $version],
            ]);
        }
        $fresh = $this->repo->find($id, $tenantId);

        return Response::json(['data' => $fresh !== null ? self::present($fresh) : null]);
    }

    /**
     * Archives the block. A synced block still used by documents answers 409
     * in_use with the count, unless a documents:publish holder sends force=1.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $found = $this->visible($request, $params, CorePermissions::DOCUMENTS_WRITE);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $can, $row] = $found;
        if (!self::mayManage($row, $callerId, $can)) {
            return self::libraryRefused();
        }
        if ($row['archived_at'] !== null) {
            return Response::error('This saved block was deleted already', 404);
        }
        $id = (int) $row['id'];
        $force = in_array(self::queryParam($request, 'force'), ['1', 'true'], true);
        $usedIn = $this->repo->countUses($id, $tenantId);
        if ($row['mode'] === SavedBlockRepository::SYNCED && $usedIn > 0) {
            if (!$force) {
                return Response::error(
                    "This synced block is used in {$usedIn} " . ($usedIn === 1 ? 'document' : 'documents'),
                    409,
                    ['code' => 'in_use', 'used_in' => $usedIn],
                );
            }
            if (!$can(CorePermissions::DOCUMENTS_PUBLISH)) {
                return Response::error('Deleting a synced block that is still used needs the workspace library permission', 403, ['required' => CorePermissions::DOCUMENTS_PUBLISH]);
            }
        }
        if (!$this->repo->archive($id, $tenantId)) {
            return Response::error('This saved block was deleted already', 404);
        }
        $this->audit?->record('documents.saved_block.archived', [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $callerId,
            'target_type'   => 'saved_block',
            'target_id'     => $id,
            'metadata'      => ['name' => $row['name'], 'mode' => $row['mode'], 'sharing' => $row['sharing'], 'used_in' => $usedIn, 'forced' => $force && $usedIn > 0],
        ]);

        return Response::json(['data' => ['id' => $id, 'archived' => true, 'usedIn' => $usedIn]]);
    }

    /** @param array<string, string> $params */
    public function version(Request $request, array $params): Response
    {
        $found = $this->visible($request, $params, CorePermissions::DOCUMENTS_READ);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, , , $row] = $found;
        $version = $this->repo->findVersion((int) $row['id'], $tenantId, (int) ($params['version'] ?? 0));
        if ($version === null) {
            return Response::error('Version not found', 404);
        }

        return Response::json(['data' => [
            'savedBlockId' => $version['saved_block_id'],
            'version'      => $version['version'],
            'blocks'       => $version['blocks'],
            'createdAt'    => self::iso($version['created_at']),
        ]]);
    }

    /**
     * The documents using the block that the caller can see. `usedIn` on the
     * block counts them all; `hidden` says how many this list leaves out.
     *
     * @param array<string, string> $params
     */
    public function usage(Request $request, array $params): Response
    {
        $found = $this->visible($request, $params, CorePermissions::DOCUMENTS_READ);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, , $row] = $found;
        $documents = [];
        $hidden = 0;
        foreach ($this->repo->uses((int) $row['id'], $tenantId) as $use) {
            $source = $this->sources->visibleRow($use['source_id'], $tenantId, $callerId);
            if ($source === null) {
                $hidden++;
                continue;
            }
            $documents[] = [
                'id'      => (int) $source['id'],
                'title'   => (string) ($source['title'] ?? ''),
                'kind'    => (string) ($source['kind'] ?? ''),
                'version' => $use['version'],
            ];
        }

        return Response::json(['data' => $documents, 'meta' => ['total' => count($documents) + $hidden, 'hidden' => $hidden]]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * The tenant, the caller and their permission check, when they hold $permission.
     *
     * @return array{0: int, 1: int, 2: callable(string, int|null=): bool}|Response
     */
    private function context(Request $request, string $permission): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $user = $request->user;
        $callerId = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }
        $can = ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId);
        if (!$can($permission)) {
            return Response::error('Insufficient permissions', 403, ['required' => $permission]);
        }

        return [$tenantId, $callerId, $can];
    }

    /**
     * The saved block, archived or not, when the caller may see it: their own,
     * or shared with the workspace. Anything else answers 404.
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: callable(string, int|null=): bool, 3: array<string, mixed>}|Response
     */
    private function visible(Request $request, array $params, string $permission): array|Response
    {
        $ctx = $this->context($request, $permission);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId, $can] = $ctx;
        $row = $this->repo->find((int) ($params['id'] ?? 0), $tenantId);
        if ($row === null || ($row['sharing'] !== SavedBlockRepository::SHARING_WORKSPACE && $row['owner_profile_id'] !== $callerId)) {
            return Response::error('Saved block not found', 404);
        }

        return [$tenantId, $callerId, $can, $row];
    }

    /**
     * The owner manages their own blocks; someone else's workspace block
     * needs the workspace library permission (documents:publish for now).
     *
     * @param array<string, mixed>           $row
     * @param callable(string, int|null=): bool $can
     */
    private static function mayManage(array $row, int $callerId, callable $can): bool
    {
        return $row['owner_profile_id'] === $callerId || $can(CorePermissions::DOCUMENTS_PUBLISH);
    }

    private static function libraryRefused(): Response
    {
        return Response::error(
            'Managing the workspace library needs permission to publish documents',
            403,
            ['required' => CorePermissions::DOCUMENTS_PUBLISH],
        );
    }

    /**
     * The block as the editor contract's SavedBlock, with its dates.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $owner = $row['owner_profile_id'] !== null
            ? ['kind' => 'person', 'id' => $row['owner_profile_id'], 'name' => (string) ($row['owner_name'] ?? '')]
            : null;

        return [
            'id'              => $row['id'],
            'name'            => $row['name'],
            'description'     => $row['description'],
            'mode'            => $row['mode'],
            'sharing'         => $row['sharing'],
            'owner'           => $owner,
            'blocks'          => $row['blocks'],
            'perDocumentKeys' => $row['per_document_keys'],
            'version'         => $row['version'],
            'usedIn'          => $row['used_in'],
            'archivedAt'      => $row['archived_at'] !== null ? self::iso((string) $row['archived_at']) : null,
            'createdAt'       => self::iso((string) $row['created_at']),
            'updatedAt'       => self::iso((string) $row['updated_at']),
        ];
    }

    private static function name(mixed $value): string|Response
    {
        $name = is_string($value) ? trim($value) : '';
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            return Response::error('name must be a non-empty string of at most ' . self::MAX_NAME . ' characters', 422);
        }

        return $name;
    }

    private static function description(mixed $value): string|null|Response
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > self::MAX_DESCRIPTION) {
            return Response::error('description must be a string of at most ' . self::MAX_DESCRIPTION . ' characters, or null', 422);
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * The contract Block[]: a non-empty list of at most 200 objects, each
     * with a string id and type, at most 256 KiB encoded.
     *
     * @return list<array<string, mixed>>|Response
     */
    private static function blocks(mixed $value): array|Response
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            return Response::error('blocks must be a non-empty list of blocks', 422);
        }
        if (count($value) > self::MAX_BLOCKS) {
            return Response::error('blocks may hold at most ' . self::MAX_BLOCKS . ' blocks', 422);
        }
        $out = [];
        foreach ($value as $i => $block) {
            if (!is_array($block) || ($block !== [] && array_is_list($block))) {
                return Response::error("blocks[{$i}] must be an object", 422);
            }
            $id = $block['id'] ?? null;
            $type = $block['type'] ?? null;
            if (!is_string($id) || $id === '' || strlen($id) > self::MAX_ID || !is_string($type) || $type === '' || strlen($type) > self::MAX_ID) {
                return Response::error("blocks[{$i}] must have a string id and type", 422);
            }
            /** @var array<string, mixed> $block */
            $out[] = $block;
        }
        $encoded = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > self::MAX_BLOCKS_BYTES) {
            return Response::error('blocks must be at most 256 KiB', 422);
        }

        return $out;
    }

    /**
     * The keys of the parts filled per document (E.23): a list of distinct
     * non-empty strings. Null and absent are an empty list.
     *
     * @return list<string>|Response
     */
    private static function keys(mixed $value): array|Response
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_KEYS) {
            return Response::error('per_document_keys must be a list of at most ' . self::MAX_KEYS . ' keys', 422);
        }
        $out = [];
        foreach ($value as $key) {
            if (!is_string($key) || $key === '' || strlen($key) > self::MAX_KEY) {
                return Response::error('per_document_keys must be a list of non-empty strings of at most ' . self::MAX_KEY . ' characters', 422);
            }
            $out[$key] = true;
        }

        return array_keys($out);
    }

    /**
     * per_document_keys, or the contract's perDocumentKeys. Null when neither is sent.
     *
     * @param array<string, mixed> $body
     */
    private static function keysField(array $body): mixed
    {
        if (array_key_exists('per_document_keys', $body)) {
            return $body['per_document_keys'] ?? [];
        }
        if (array_key_exists('perDocumentKeys', $body)) {
            return $body['perDocumentKeys'] ?? [];
        }

        return null;
    }

    private static function iso(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return $value;
        }
    }

    /** A query-string parameter, read the way DocumentSourcesApiHandler reads one. */
    private static function queryParam(Request $request, string $name): ?string
    {
        $value = isset($_GET[$name]) && is_string($_GET[$name]) ? $_GET[$name] : null;
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $parsed = [];
            parse_str($query, $parsed);
            if (isset($parsed[$name]) && is_string($parsed[$name])) {
                $value = $parsed[$name];
            }
        }

        return $value;
    }
}
