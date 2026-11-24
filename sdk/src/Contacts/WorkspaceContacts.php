<?php

declare(strict_types=1);

namespace Whity\Sdk\Contacts;

/**
 * Core's customers and suppliers in the workspace the call runs in (SDK 1.161,
 * decision D2). Get it from {@see \Whity\Sdk\Context\PluginScope::contacts()}:
 *
 *     $ctx = \Whity\app(\Whity\Sdk\PluginContext::class)->for($this);
 *     $supplier = $ctx->contacts()->findByField('keyhub.supplier_code', 'DK-0042');
 *
 * The same service as {@see Contacts}, without the workspace argument: the
 * host uses the workspace the request or job runs in, so a call can't name
 * another. Reads, writes, permissions and refusals are exactly as
 * {@see Contacts} documents them. Without a workspace every call throws
 * \RuntimeException.
 */
interface WorkspaceContacts
{
    /**
     * The workspace's live (not archived) contacts, by name.
     *
     * @param array{is_customer?: bool, is_supplier?: bool, tag?: string, q?: string, field?: array<string, string>} $filter
     * @return list<Contact>
     * @throws ContactRejected when a `field` key isn't one of the workspace's fields
     */
    public function list(array $filter = [], int $limit = 50, int $offset = 0): array;

    /** The contact, archived or not; null when it isn't in this workspace. */
    public function get(int $contactId): ?Contact;

    /** The first live contact whose custom field `$fieldKey` equals `$value` (ignoring case), or null. */
    public function findByField(string $fieldKey, string $value): ?Contact;

    /**
     * Adds a contact as `$actingProfileId`; `$data` is the body of `POST /api/contacts`.
     *
     * @param array<string, mixed> $data
     * @throws ContactRejected
     */
    public function create(array $data, int $actingProfileId): Contact;

    /**
     * Changes a contact as `$actingProfileId`; only the keys in `$changes` change.
     *
     * @param array<string, mixed> $changes
     * @throws ContactRejected
     */
    public function update(int $contactId, array $changes, int $actingProfileId): Contact;
}
