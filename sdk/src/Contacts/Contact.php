<?php

declare(strict_types=1);

namespace Whity\Sdk\Contacts;

/**
 * One customer or supplier (SDK 1.119), as `GET /api/contacts/{id}` answers.
 *
 * `fields` holds the workspace's own fields, key => value: text and dates as
 * strings (`2026-11-18`), numbers as strings, yes-or-no as bool, choose
 * several as a list of strings, a person as a profile id.
 */
final class Contact
{
    /**
     * @param list<string> $tags
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $isCustomer,
        public readonly bool $isSupplier,
        public readonly string $contactPerson = '',
        public readonly string $email = '',
        public readonly string $mobile = '',
        public readonly string $phone = '',
        public readonly string $taxNumber = '',
        public readonly string $paymentTerms = '',
        public readonly string $address = '',
        public readonly string $notes = '',
        public readonly ?int $profileId = null,
        public readonly array $tags = [],
        public readonly array $fields = [],
        public readonly bool $archived = false,
        public readonly string $createdAt = '',
        public readonly string $updatedAt = '',
    ) {
    }

    /** One custom field's value, or null when it has none. */
    public function field(string $key): mixed
    {
        return $this->fields[$key] ?? null;
    }

    /**
     * From the API shape (`GET /api/contacts/{id}`'s `data`).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $tags = [];
        foreach (is_array($data['tags'] ?? null) ? $data['tags'] : [] as $tag) {
            $name = is_array($tag) ? ($tag['name'] ?? null) : $tag;
            if (is_string($name)) {
                $tags[] = $name;
            }
        }
        $fields = $data['fields'] ?? [];
        if (is_object($fields)) {
            $fields = get_object_vars($fields);
        }
        $text = static fn (string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';

        return new self(
            id: is_numeric($data['id'] ?? null) ? (int) $data['id'] : 0,
            name: $text('name'),
            isCustomer: (bool) ($data['is_customer'] ?? false),
            isSupplier: (bool) ($data['is_supplier'] ?? false),
            contactPerson: $text('contact_person'),
            email: $text('email'),
            mobile: $text('mobile'),
            phone: $text('phone'),
            taxNumber: $text('tax_number'),
            paymentTerms: $text('payment_terms'),
            address: $text('address'),
            notes: $text('notes'),
            profileId: is_int($data['profile_id'] ?? null) ? $data['profile_id'] : null,
            tags: $tags,
            fields: is_array($fields) ? $fields : [],
            archived: (bool) ($data['archived'] ?? false),
            createdAt: $text('created_at'),
            updatedAt: $text('updated_at'),
        );
    }
}
