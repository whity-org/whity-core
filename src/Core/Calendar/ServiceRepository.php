<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;
use Whity\Core\Db\DbBool;
use Whity\Core\Form\LocalizedLabel;

/**
 * Services and who provides them (migration 248). Tenant first on every
 * method. Prices are integer minor units and only shown.
 */
final class ServiceRepository
{
    private const COLUMNS = 'id, name, description, duration_minutes, buffer_before_minutes, buffer_after_minutes,
                             price_minor, currency, bookable_online, active, sort_order';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $tenantId, bool $activeOnly = false, bool $onlineOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM calendar_services WHERE tenant_id = :tenant_id';
        if ($activeOnly) {
            $sql .= ' AND active = :active';
        }
        if ($onlineOnly) {
            $sql .= ' AND bookable_online = :online';
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY sort_order, id');
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        if ($activeOnly) {
            $stmt->bindValue(':active', true, PDO::PARAM_BOOL);
        }
        if ($onlineOnly) {
            $stmt->bindValue(':online', true, PDO::PARAM_BOOL);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $staff = $this->staffByService($tenantId);

        return array_values(array_map(fn (array $r): array => self::toApi($r, $staff[(int) $r['id']] ?? []), $rows));
    }

    /** @return array<string, mixed>|null */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM calendar_services WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::toApi($row, $this->staffByService($tenantId)[$id] ?? []);
    }

    /** @param array<string, mixed> $v validated values */
    public function insert(int $tenantId, array $v): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO calendar_services (tenant_id, name, description, duration_minutes, buffer_before_minutes,
                    buffer_after_minutes, price_minor, currency, bookable_online, active, sort_order)
             VALUES (:tenant_id, :name, :description, :duration, :before, :after, :price, :currency, :online, :active, :sort)
             RETURNING id'
        );
        $this->bind($stmt, $v);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $v validated values */
    public function update(int $tenantId, int $id, array $v): void
    {
        $stmt = $this->db->prepare(
            'UPDATE calendar_services
                SET name = :name, description = :description, duration_minutes = :duration,
                    buffer_before_minutes = :before, buffer_after_minutes = :after, price_minor = :price,
                    currency = :currency, bookable_online = :online, active = :active, sort_order = :sort,
                    updated_at = :now
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        $this->bind($stmt, $v);
        $stmt->bindValue(':now', Interval::toDb(new DateTimeImmutable()));
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @param list<int> $profileIds */
    public function replaceStaff(int $tenantId, int $serviceId, array $profileIds): void
    {
        $this->db->prepare('DELETE FROM calendar_service_staff WHERE tenant_id = :tenant_id AND service_id = :service_id')
            ->execute([':tenant_id' => $tenantId, ':service_id' => $serviceId]);
        $insert = $this->db->prepare(
            'INSERT INTO calendar_service_staff (tenant_id, service_id, profile_id) VALUES (:tenant_id, :service_id, :profile_id)'
        );
        foreach (array_unique($profileIds) as $profileId) {
            $insert->execute([':tenant_id' => $tenantId, ':service_id' => $serviceId, ':profile_id' => $profileId]);
        }
    }

    /** @return array<int, list<int>> service id => staff profile ids */
    private function staffByService(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT service_id, profile_id FROM calendar_service_staff WHERE tenant_id = :tenant_id ORDER BY profile_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int) $r['service_id']][] = (int) $r['profile_id'];
        }

        return $map;
    }

    /** @param array<string, mixed> $v */
    private function bind(\PDOStatement $stmt, array $v): void
    {
        $stmt->bindValue(':name', LocalizedLabel::encode($v['name']));
        $stmt->bindValue(':description', LocalizedLabel::encode($v['description']));
        $stmt->bindValue(':duration', $v['duration_minutes'], PDO::PARAM_INT);
        $stmt->bindValue(':before', $v['buffer_before_minutes'], PDO::PARAM_INT);
        $stmt->bindValue(':after', $v['buffer_after_minutes'], PDO::PARAM_INT);
        $stmt->bindValue(':price', $v['price_minor'], $v['price_minor'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':currency', $v['currency']);
        $stmt->bindValue(':online', $v['bookable_online'], PDO::PARAM_BOOL);
        $stmt->bindValue(':active', $v['active'], PDO::PARAM_BOOL);
        $stmt->bindValue(':sort', $v['sort_order'], PDO::PARAM_INT);
    }

    /**
     * @param array<string, mixed> $r
     * @param list<int> $staff
     * @return array<string, mixed>
     */
    private static function toApi(array $r, array $staff): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => LocalizedLabel::decode((string) $r['name']),
            'description' => LocalizedLabel::decode((string) $r['description']),
            'duration_minutes' => (int) $r['duration_minutes'],
            'buffer_before_minutes' => (int) $r['buffer_before_minutes'],
            'buffer_after_minutes' => (int) $r['buffer_after_minutes'],
            'price_minor' => $r['price_minor'] !== null ? (int) $r['price_minor'] : null,
            'currency' => trim((string) $r['currency']),
            'bookable_online' => DbBool::of($r['bookable_online']),
            'active' => DbBool::of($r['active']),
            'sort_order' => (int) $r['sort_order'],
            'staff_profile_ids' => $staff,
        ];
    }
}
