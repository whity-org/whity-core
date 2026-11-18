<?php

declare(strict_types=1);

namespace Whity\Core\Contacts;

use Whity\Core\CustomFields\CustomFieldService;
use Whity\Sdk\Contacts\Contact;
use Whity\Sdk\Contacts\ContactRejected;
use Whity\Sdk\Contacts\Contacts;

/**
 * The host's {@see Contacts} (SDK 1.119, WHIT-1209, #2131): core's contacts
 * for a plugin, with no HTTP call and no session.
 *
 * Writes go through the same {@see ContactService} the routes use, so the
 * validation, tags, custom fields and `created_by_profile_id` are the same as
 * `POST /api/contacts` and `PATCH /api/contacts/{id}`. What the route's
 * middleware checks is checked here instead: the acting profile must be in the
 * workspace and hold `contacts:write`, by the same RoleChecker; a field with
 * its own edit permission asks for that too.
 *
 * Reads are the plugin's own business (as with its own tables): not
 * permission-checked.
 */
final class HostContacts implements Contacts
{
    private const SYSTEM_TENANT_ID = 0;

    /**
     * @param \Closure(int, string, int): bool $hasPermission profile id, permission, tenant id
     */
    public function __construct(
        private readonly ContactService $contacts,
        private readonly ContactRepository $repository,
        private readonly ContactTags $tags,
        private readonly CustomFieldService $fields,
        private readonly \Closure $hasPermission,
    ) {
    }

    public function list(int $tenantId, array $filter = [], int $limit = 50, int $offset = 0): array
    {
        if (!self::isWorkspace($tenantId)) {
            return [];
        }
        $query = ['archived' => false, 'extra' => []];
        foreach (['is_customer', 'is_supplier'] as $flag) {
            if (isset($filter[$flag])) {
                $query['extra'][] = ['c.' . $flag . ' = ' . ($filter[$flag] ? 'TRUE' : 'FALSE'), []];
            }
        }
        $tag = trim($filter['tag'] ?? '');
        if ($tag !== '') {
            $tagId = $this->tags->find($tenantId, ContactTags::clean($tag));
            if ($tagId === null) {
                return [];
            }
            $query['tag_id'] = $tagId;
        }
        if (isset($filter['q'])) {
            $query['q'] = $filter['q'];
        }
        $fieldFilters = [];
        foreach ($filter['field'] ?? [] as $key => $value) {
            $fieldFilters[(string) $key] = $value;
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        try {
            $result = $this->contacts->list($tenantId, $query, $limit, max(0, $offset), $fieldFilters);
        } catch (ContactRejectedException $e) {
            throw self::rejected($e);
        }

        return array_map(static fn (array $row): Contact => Contact::fromArray($row), $result['data']);
    }

    public function get(int $tenantId, int $contactId): ?Contact
    {
        if (!self::isWorkspace($tenantId) || $contactId <= 0) {
            return null;
        }
        try {
            return Contact::fromArray($this->contacts->show($tenantId, $contactId));
        } catch (ContactRejectedException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw self::rejected($e);
        }
    }

    public function findByField(int $tenantId, string $fieldKey, string $value): ?Contact
    {
        if (!self::isWorkspace($tenantId) || trim($value) === '') {
            return null;
        }
        try {
            $predicates = $this->fields->filters($tenantId, ContactService::RECORD_TYPE, 'c', [$fieldKey => trim($value)]);
        } catch (ContactRejectedException) {
            // No such field in this workspace: nobody has that value.
            return null;
        }
        $id = $this->repository->firstId($tenantId, ['archived' => false, 'extra' => $predicates]);

        return $id === null ? null : $this->get($tenantId, $id);
    }

    public function create(int $tenantId, array $data, int $actingProfileId): Contact
    {
        $can = $this->writer($tenantId, $actingProfileId);
        try {
            return Contact::fromArray($this->contacts->create($tenantId, $data, $actingProfileId, $can));
        } catch (ContactRejectedException $e) {
            throw self::rejected($e);
        }
    }

    public function update(int $tenantId, int $contactId, array $changes, int $actingProfileId): Contact
    {
        $can = $this->writer($tenantId, $actingProfileId);
        try {
            return Contact::fromArray($this->contacts->update($tenantId, $contactId, $changes, $can));
        } catch (ContactRejectedException $e) {
            throw self::rejected($e);
        }
    }

    /**
     * The route's own gate: someone in this workspace with `contacts:write`.
     * Returns their permissions, for fields that need one of their own.
     *
     * @return \Closure(string): bool
     */
    private function writer(int $tenantId, int $profileId): \Closure
    {
        if (!self::isWorkspace($tenantId)) {
            throw new ContactRejected('A workspace is needed to change contacts.', 403);
        }
        if ($profileId <= 0 || !$this->repository->isMemberProfile($tenantId, $profileId)) {
            throw new ContactRejected('That person is not in this workspace.', 403);
        }
        $check = $this->hasPermission;
        if (!$check($profileId, \Whity\Core\RBAC\CorePermissions::CONTACTS_WRITE, $tenantId)) {
            throw new ContactRejected('That person can\'t change contacts here (contacts:write).', 403);
        }

        return static fn (string $permission): bool => $check($profileId, $permission, $tenantId);
    }

    private static function isWorkspace(int $tenantId): bool
    {
        return $tenantId > self::SYSTEM_TENANT_ID;
    }

    private static function rejected(ContactRejectedException $e): ContactRejected
    {
        $field = $e->extra['field'] ?? null;

        return new ContactRejected($e->clientMessage, $e->status, is_string($field) ? $field : null);
    }
}
