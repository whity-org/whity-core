<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;

/**
 * What one person last chose on the calendar in one workspace (design 3.5:
 * CV.1 the sidebar kept open, CV.2 the panel's ticks, CV.4 Colour by, CV.7
 * the view and the date in view). Only that person reads or writes it.
 *
 * `clean()` is the one place the shape is decided: unknown keys are dropped,
 * values out of range fall back to "not chosen", so a stored row can never
 * hold something the screen does not understand.
 */
final class ViewPreferences
{
    public const VIEWS = ['day', 'four_days', 'week', 'month', 'year', 'three_days'];
    public const COLOUR_BY = ['person', 'service', 'room'];
    public const ALSO_SHOW = ['team_events', 'time_off', 'cancelled', 'meetings', 'tasks_due'];
    /** The most ids a hidden list may hold: far beyond any real team. */
    private const MAX_IDS = 500;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $profileId): array
    {
        $stmt = $this->db->prepare('SELECT choices FROM calendar_view_preferences WHERE tenant_id = :tenant_id AND profile_id = :profile_id');
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
        $raw = $stmt->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return self::clean(is_array($decoded) ? $decoded : []);
    }

    /**
     * Merge `$patch` into what is stored: a key left out keeps its value, a
     * key sent as null forgets it.
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function update(int $tenantId, int $profileId, array $patch): array
    {
        $current = $this->get($tenantId, $profileId);
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($current[$key]);
            } else {
                $current[$key] = $value;
            }
        }
        $next = self::clean($current);
        $stmt = $this->db->prepare(
            'INSERT INTO calendar_view_preferences (tenant_id, profile_id, choices, updated_at)
             VALUES (:tenant_id, :profile_id, :choices, :now)
             ON CONFLICT (tenant_id, profile_id) DO UPDATE SET choices = :choices_again, updated_at = :now_again'
        );
        $json = json_encode((object) $next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $now = Interval::toDb(new DateTimeImmutable());
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            ':choices' => $json,
            ':now' => $now,
            ':choices_again' => $json,
            ':now_again' => $now,
        ]);

        return $next;
    }

    /**
     * @param array<array-key, mixed> $in
     * @return array<string, mixed>
     */
    public static function clean(array $in): array
    {
        $out = [];
        if (is_string($in['view'] ?? null) && in_array($in['view'], self::VIEWS, true)) {
            $out['view'] = $in['view'];
        }
        if (is_string($in['colour_by'] ?? null) && in_array($in['colour_by'], self::COLOUR_BY, true)) {
            $out['colour_by'] = $in['colour_by'];
        }
        if (is_string($in['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date']) === 1) {
            $out['date'] = $in['date'];
        }
        foreach (['hidden_people', 'hidden_rooms', 'hidden_services'] as $key) {
            if (is_array($in[$key] ?? null)) {
                $ids = array_values(array_unique(array_filter($in[$key], static fn (mixed $v): bool => is_int($v) && $v > 0)));
                $out[$key] = array_slice($ids, 0, self::MAX_IDS);
            }
        }
        if (is_array($in['also_show'] ?? null)) {
            $out['also_show'] = array_values(array_intersect(self::ALSO_SHOW, $in['also_show']));
        }
        if (is_bool($in['sidebar_open'] ?? null)) {
            $out['sidebar_open'] = $in['sidebar_open'];
        }

        return $out;
    }
}
