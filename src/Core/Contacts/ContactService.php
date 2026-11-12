<?php

declare(strict_types=1);

namespace Whity\Core\Contacts;

use Whity\Core\Db\DbBool;

/**
 * Customers and suppliers (WHIT-953, design 3.3 A8): validation, tags, the
 * link to a person's profile, and the shape the API answers with.
 *
 * A contact is a customer, a supplier or both; it needs a name and at least
 * one of the two. When it has no `profile_id` and its email is the verified
 * email of someone in the workspace, it is linked to that person, so the
 * contact page can show their messages and bookings (which keep their own
 * `customer_profile_id`; nothing is moved).
 */
final class ContactService
{
    public const MAX_TAGS = 20;

    /** @var list<callable(int, array<string, mixed>, array<string, mixed>): array<string, mixed>> */
    private array $decorators = [];

    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly ContactTags $tags,
    ) {
    }

    /**
     * Something that adds to the contact the API answers with, such as the
     * custom fields (WHIT-954). Called with (tenantId, contact row, api shape).
     *
     * @param callable(int, array<string, mixed>, array<string, mixed>): array<string, mixed> $decorator
     */
    public function addDecorator(callable $decorator): void
    {
        $this->decorators[] = $decorator;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> the new contact, API shape
     */
    public function create(int $tenantId, array $input, ?int $actor): array
    {
        $fields = $this->validate($tenantId, $input, null);
        if (!array_key_exists('name', $fields) || $fields['name'] === '') {
            throw new ContactRejectedException('A contact needs a name', 422, ['field' => 'name']);
        }
        $fields += ['is_customer' => true, 'is_supplier' => false];
        if (!$fields['is_customer'] && !$fields['is_supplier']) {
            throw new ContactRejectedException('A contact is a customer, a supplier, or both', 422, ['field' => 'is_customer']);
        }
        if (($fields['profile_id'] ?? null) === null) {
            $fields['profile_id'] = $this->contacts->memberProfileByEmail($tenantId, (string) ($fields['email'] ?? ''));
        }
        $id = $this->contacts->insert($tenantId, $fields, $actor);
        if (isset($input['tags'])) {
            $this->tags->replace($tenantId, $id, self::tagNames($input['tags']));
        }

        return $this->show($tenantId, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> the contact, API shape
     */
    public function update(int $tenantId, int $id, array $input): array
    {
        $row = $this->contacts->find($tenantId, $id) ?? throw ContactRejectedException::notFound('Contact');
        $fields = $this->validate($tenantId, $input, $row);
        if (array_key_exists('name', $fields) && $fields['name'] === '') {
            throw new ContactRejectedException('A contact needs a name', 422, ['field' => 'name']);
        }
        $customer = DbBool::of($fields['is_customer'] ?? $row['is_customer']);
        $supplier = DbBool::of($fields['is_supplier'] ?? $row['is_supplier']);
        if (!$customer && !$supplier) {
            throw new ContactRejectedException('A contact is a customer, a supplier, or both', 422, ['field' => 'is_customer']);
        }
        $this->contacts->update($tenantId, $id, $fields);
        if (isset($input['tags'])) {
            $this->tags->replace($tenantId, $id, self::tagNames($input['tags']));
        }

        return $this->show($tenantId, $id);
    }

    /** @return array<string, mixed> */
    public function show(int $tenantId, int $id): array
    {
        $row = $this->contacts->find($tenantId, $id) ?? throw ContactRejectedException::notFound('Contact');
        $tags = $this->contacts->tagsFor($tenantId, [$id]);
        $api = self::toApi($row, $tags[$id] ?? []);
        $profileId = $row['profile_id'] === null ? null : (int) $row['profile_id'];
        $api['profile'] = $profileId === null ? null : ['id' => $profileId, 'display_name' => $this->contacts->profileName($profileId) ?? ''];

        return $this->decorate($tenantId, $row, $api);
    }

    /**
     * @param array{kind?: ?string, tag_id?: ?int, q?: ?string, archived?: bool, ids?: ?list<int>} $filter
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function list(int $tenantId, array $filter, int $limit, int $offset): array
    {
        $rows = $this->contacts->list($tenantId, $filter, $limit, $offset);
        $tags = $this->contacts->tagsFor($tenantId, array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $data = [];
        foreach ($rows as $row) {
            $data[] = $this->decorate($tenantId, $row, self::toApi($row, $tags[(int) $row['id']] ?? []));
        }

        return ['data' => $data, 'total' => $this->contacts->count($tenantId, $filter)];
    }

    /** @return array<string, mixed> */
    public function archive(int $tenantId, int $id, bool $archived): array
    {
        if (!$this->contacts->setArchived($tenantId, $id, $archived)) {
            throw ContactRejectedException::notFound('Contact');
        }

        return $this->show($tenantId, $id);
    }

    /**
     * Checks and tidies what a caller sent. Only the keys present are returned.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    public function validate(int $tenantId, array $input, ?array $current, ?string $countryCode = null): array
    {
        $fields = [];
        foreach (ContactRepository::TEXT_FIELDS as $field => $max) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if ($value === null) {
                $value = '';
            }
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw new ContactRejectedException($field . ' must be text', 422, ['field' => $field]);
            }
            $value = trim((string) $value);
            if (mb_strlen($value) > $max) {
                throw new ContactRejectedException($field . ' is too long; the limit is ' . $max . ' characters', 422, ['field' => $field]);
            }
            $fields[$field] = $value;
        }
        if (isset($fields['email']) && $fields['email'] !== '' && filter_var($fields['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new ContactRejectedException('That email address doesn\'t look right', 422, ['field' => 'email']);
        }
        foreach (['mobile', 'phone'] as $field) {
            if (!isset($fields[$field])) {
                continue;
            }
            $tidy = PhoneNumber::tidy($fields[$field], $countryCode);
            if ($tidy === null) {
                throw new ContactRejectedException('That ' . $field . ' number doesn\'t look right', 422, ['field' => $field]);
            }
            $fields[$field] = $tidy['value'];
        }
        foreach (['is_customer', 'is_supplier'] as $flag) {
            if (array_key_exists($flag, $input)) {
                if (!is_bool($input[$flag])) {
                    throw new ContactRejectedException($flag . ' must be true or false', 422, ['field' => $flag]);
                }
                $fields[$flag] = $input[$flag];
            }
        }
        if (array_key_exists('profile_id', $input)) {
            $profileId = $input['profile_id'];
            if ($profileId === null) {
                $fields['profile_id'] = null;
            } elseif (!is_int($profileId) || $profileId <= 0 || !$this->contacts->isMemberProfile($tenantId, $profileId)) {
                throw new ContactRejectedException('That person is not in this workspace', 422, ['field' => 'profile_id']);
            } else {
                $fields['profile_id'] = $profileId;
            }
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    public static function tagNames(mixed $tags): array
    {
        if (!is_array($tags) || !array_is_list($tags)) {
            throw new ContactRejectedException('tags must be a list of names', 422, ['field' => 'tags']);
        }
        if (count($tags) > self::MAX_TAGS) {
            throw new ContactRejectedException('A contact can have at most ' . self::MAX_TAGS . ' tags', 422, ['field' => 'tags']);
        }
        $names = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                throw new ContactRejectedException('tags must be a list of names', 422, ['field' => 'tags']);
            }
            $clean = ContactTags::clean($tag);
            $names[mb_strtolower($clean)] ??= $clean;
        }

        return array_values($names);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{id: int, name: string}> $tags
     * @return array<string, mixed>
     */
    public static function toApi(array $row, array $tags): array
    {
        $api = ['id' => (int) $row['id']];
        foreach (array_keys(ContactRepository::TEXT_FIELDS) as $field) {
            $api[$field] = (string) $row[$field];
        }
        $api['is_customer'] = DbBool::of($row['is_customer']);
        $api['is_supplier'] = DbBool::of($row['is_supplier']);
        $api['profile_id'] = $row['profile_id'] === null ? null : (int) $row['profile_id'];
        $api['tags'] = $tags;
        $api['archived'] = $row['archived_at'] !== null;
        $api['created_at'] = (string) $row['created_at'];
        $api['updated_at'] = (string) $row['updated_at'];

        return $api;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $api
     * @return array<string, mixed>
     */
    private function decorate(int $tenantId, array $row, array $api): array
    {
        foreach ($this->decorators as $decorator) {
            $api = $decorator($tenantId, $row, $api);
        }

        return $api;
    }
}
