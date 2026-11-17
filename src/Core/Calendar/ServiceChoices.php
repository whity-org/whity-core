<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

/**
 * The services a booking of several is for, as the staff and public APIs
 * take them (WHIT-1173). Pure parsing; {@see BookingService} checks the rest.
 *
 * A body sends `services: [{service_id, profile_id?}, …]` in the order they
 * are done. A query sends `service_ids=4,7,9` and, optionally,
 * `profile_ids=11,0,12` (0 or blank: anyone for that service). A top-level
 * `profile_id` is the person for every service that names none, and
 * `same_person` asks for one person who does them all.
 */
final class ServiceChoices
{
    /**
     * @param array<string, mixed> $body
     * @return list<array{service_id: int, profile_id: ?int}>|null null when the body names no `services`
     */
    public static function fromBody(array $body): ?array
    {
        if (!array_key_exists('services', $body)) {
            return null;
        }
        $raw = $body['services'];
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new CalendarRejectedException('services is a list of {service_id, profile_id}', 422, ['field' => 'services']);
        }
        $everyone = is_int($body['profile_id'] ?? null) ? $body['profile_id'] : null;
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !is_int($entry['service_id'] ?? null)) {
                throw new CalendarRejectedException('Each of services needs a service_id', 422, ['field' => 'services']);
            }
            $person = $entry['profile_id'] ?? null;
            if ($person !== null && !is_int($person)) {
                throw new CalendarRejectedException('A profile_id in services must be a whole number, or null for anyone', 422, ['field' => 'services']);
            }
            $out[] = ['service_id' => $entry['service_id'], 'profile_id' => $person ?? $everyone];
        }
        self::assertSize($out);

        return $out;
    }

    /**
     * @param array<string, string> $query
     * @return list<array{service_id: int, profile_id: ?int}>|null null when the query names no `service_ids`
     */
    public static function fromQuery(array $query): ?array
    {
        $raw = trim($query['service_ids'] ?? '');
        if ($raw === '') {
            return null;
        }
        $services = explode(',', $raw);
        $people = isset($query['profile_ids']) && $query['profile_ids'] !== '' ? explode(',', $query['profile_ids']) : [];
        if ($people !== [] && count($people) !== count($services)) {
            throw new CalendarRejectedException('profile_ids must name one person (or 0 for anyone) for each of service_ids', 422);
        }
        $everyone = isset($query['profile_id']) && preg_match('/^\d{1,9}$/', $query['profile_id']) === 1 ? (int) $query['profile_id'] : null;
        $out = [];
        foreach ($services as $i => $id) {
            $id = trim($id);
            $person = trim($people[$i] ?? '');
            if (preg_match('/^\d{1,9}$/', $id) !== 1 || ($person !== '' && preg_match('/^\d{1,9}$/', $person) !== 1)) {
                throw new CalendarRejectedException('service_ids and profile_ids are comma-separated lists of ids', 422);
            }
            $out[] = ['service_id' => (int) $id, 'profile_id' => $person !== '' && $person !== '0' ? (int) $person : $everyone];
        }
        self::assertSize($out);

        return $out;
    }

    /** `same_person` from a body (true) or a query ("1", "true"). */
    public static function samePerson(mixed $raw): bool
    {
        return $raw === true || $raw === '1' || $raw === 'true';
    }

    /** @param list<array{service_id: int, profile_id: ?int}> $items */
    private static function assertSize(array $items): void
    {
        if (count($items) > BookingService::MAX_SERVICES) {
            throw new CalendarRejectedException('A booking can hold at most ' . BookingService::MAX_SERVICES . ' services.', 422);
        }
    }
}
