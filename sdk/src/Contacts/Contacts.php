<?php

declare(strict_types=1);

namespace Whity\Sdk\Contacts;

/**
 * Core's customers and suppliers, for a plugin (SDK 1.119, WHIT-1209, #2131).
 *
 * A host service, resolved from the container:
 *
 *     $contacts = \Whity\app(\Whity\Sdk\Contacts\Contacts::class);
 *     $supplier = $contacts?->findByField($tenantId, 'keyhub.supplier_code', 'DK-0042');
 *
 * It lets a plugin keep its own records linked to a core contact instead of
 * keeping a second address book. KeyHub, for example, keeps its component
 * suppliers as contacts with `is_supplier` and its own fields
 * ({@see \Whity\Sdk\PluginContactFieldsInterface}).
 *
 * WHICH WORKSPACE. Pass the workspace the caller is already authorized in:
 * a route handler's `Request::getWorkspaceId()`, an MCP tool's
 * `McpToolContext::$tenantId`, a job's own workspace. Never a workspace id
 * from a request body.
 *
 * READS are not permission-checked: the plugin is trusted with the workspace's
 * contacts, as it is with its own tables. Show a person only what their own
 * permissions allow (`contacts:read`).
 *
 * WRITES act as `$actingProfileId` and go through exactly what
 * `POST /api/contacts` and `PATCH /api/contacts/{id}` do: the same
 * validation; the person must be in the workspace and hold `contacts:write`
 * (and a field's own edit permission, when it has one); and the contact
 * records who added it. No HTTP call and no session are needed, so a job can
 * write as the person who set it up.
 *
 * ERRORS. Anything a caller can fix (a missing name, a bad email, a field
 * that isn't there, a person without the permission) throws
 * {@see ContactRejected} with a status like the route's (403, 404, 422).
 *
 * Absent (null from the container) on a host older than 1.119; check before
 * use.
 */
interface Contacts
{
    /** The most `list()` returns at once. */
    public const MAX_LIMIT = 200;

    /**
     * The workspace's live (not archived) contacts, by name.
     *
     * `field` filters on custom fields, key => value, as `field[<key>]=value`
     * does on `GET /api/contacts`: a field matches its whole value, ignoring
     * case; a choose-several field matches one of its choices. `q` searches
     * names, email and phone numbers as the contacts list does.
     *
     * @param array{is_customer?: bool, is_supplier?: bool, tag?: string, q?: string, field?: array<string, string>} $filter
     * @return list<Contact>
     * @throws ContactRejected when a `field` key isn't one of the workspace's fields
     */
    public function list(int $tenantId, array $filter = [], int $limit = 50, int $offset = 0): array;

    /** The contact, archived or not; null when it isn't in this workspace. */
    public function get(int $tenantId, int $contactId): ?Contact;

    /**
     * The first live contact (lowest id) whose custom field `$fieldKey`
     * equals `$value` (ignoring case), e.g. a plugin's supplier code. Null
     * when none, or when the workspace has no such field.
     */
    public function findByField(int $tenantId, string $fieldKey, string $value): ?Contact;

    /**
     * Adds a contact. `$data` is the body of `POST /api/contacts`: `name`
     * (required), `contact_person`, `email`, `mobile`, `phone`, `tax_number`,
     * `payment_terms`, `address`, `notes`, `is_customer` (default true),
     * `is_supplier`, `tags` (names) and `fields` (key => value).
     *
     * @param array<string, mixed> $data
     * @throws ContactRejected
     */
    public function create(int $tenantId, array $data, int $actingProfileId): Contact;

    /**
     * Changes a contact. Only the keys in `$changes` change; in `fields`, a
     * null value clears that field and keys left out are untouched.
     *
     * @param array<string, mixed> $changes
     * @throws ContactRejected
     */
    public function update(int $tenantId, int $contactId, array $changes, int $actingProfileId): Contact;
}
