<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Sdk\Contacts\Contact;
use Whity\Sdk\Contacts\Contacts;
use Whity\Sdk\Contacts\WorkspaceContacts;

/** {@see Contacts} pinned to the workspace the call runs in (SDK 1.161, D2). */
final class HostWorkspaceContacts implements WorkspaceContacts
{
    use PinnedWorkspace;

    private const SERVICE = 'Contacts';

    /** @param \Closure(): ?int $workspace */
    public function __construct(private readonly Contacts $contacts, \Closure $workspace)
    {
        $this->workspace = $workspace;
    }

    public function list(array $filter = [], int $limit = 50, int $offset = 0): array
    {
        return $this->contacts->list($this->workspace(), $filter, $limit, $offset);
    }

    public function get(int $contactId): ?Contact
    {
        return $this->contacts->get($this->workspace(), $contactId);
    }

    public function findByField(string $fieldKey, string $value): ?Contact
    {
        return $this->contacts->findByField($this->workspace(), $fieldKey, $value);
    }

    public function create(array $data, int $actingProfileId): Contact
    {
        return $this->contacts->create($this->workspace(), $data, $actingProfileId);
    }

    public function update(int $contactId, array $changes, int $actingProfileId): Contact
    {
        return $this->contacts->update($this->workspace(), $contactId, $changes, $actingProfileId);
    }
}
