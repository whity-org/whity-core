<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use PDO;
use Whity\Core\Document\Organizer\PdoSchemaPresence;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Settings\SettingsRegistry;

/**
 * Copies what the retired Salons plugin kept into core (WHIT-1334, design
 * section 35 §6, register SL.9): its walk-in queue, its commission rates, who
 * could use them, and the walk-ins switch for the workspaces that used it.
 *
 * Idempotent: a walk-in already copied (by `legacy_id`) or a rate already set
 * (same person and service) is left as it is, so it runs from the migration
 * and again from `whity-cli calendar:copy-salons` before Salons is removed,
 * picking up only what was added between. Does nothing where Salons never
 * ran: no `salons_*` table, nothing to copy. Salons' own tables are never
 * changed.
 *
 * Ids the plugin kept without foreign keys may point at rows since removed:
 * a walk-in whose service is gone is skipped and counted, and a missing
 * person, booking or contact becomes empty, so the copy never fails on old
 * data.
 */
final class SalonsCopy
{
    public const WALKINS = 'salons_walkins';
    public const RATES = 'salons_commission_rates';

    /** Salons' permission => core's. */
    private const GRANTS = [
        'salons:front_desk' => CorePermissions::CALENDAR_FRONT_DESK,
        'salons:commission' => CorePermissions::CALENDAR_COMMISSION,
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Copy everything not yet copied.
     *
     * @return array{walkins: int, walkins_skipped: int, rates: int, grants: int, switched_on: int}
     */
    public function run(): array
    {
        $presence = new PdoSchemaPresence($this->db);
        $out = ['walkins' => 0, 'walkins_skipped' => 0, 'rates' => 0, 'grants' => 0, 'switched_on' => 0];
        if ($presence->hasTable(self::WALKINS)) {
            [$out['walkins'], $out['walkins_skipped']] = $this->walkins();
            $out['switched_on'] = $this->switchOn();
        }
        if ($presence->hasTable(self::RATES)) {
            $out['rates'] = $this->rates();
        }
        $out['grants'] = $this->grants();

        return $out;
    }

    /**
     * Per workspace, how many rows Salons holds and how many core holds from it.
     *
     * @return array<int, array{walkins: int, walkins_copied: int, rates: int, rates_in_core: int}>
     */
    public function counts(): array
    {
        $presence = new PdoSchemaPresence($this->db);
        $by = function (string $sql): array {
            $out = [];
            foreach ($this->all($sql, PDO::FETCH_ASSOC) as $r) {
                if (is_array($r)) {
                    $out[(int) $r['tenant_id']] = (int) $r['n'];
                }
            }

            return $out;
        };
        $walkins = $copied = $rates = $inCore = [];
        if ($presence->hasTable(self::WALKINS)) {
            $walkins = $by('SELECT tenant_id, COUNT(*) AS n FROM salons_walkins GROUP BY tenant_id');
            // @tenant-guard-ignore: the copy runs once for the whole installation, reporting every workspace Salons ran in
            $copied = $by('SELECT tenant_id, COUNT(*) AS n FROM calendar_walkins WHERE legacy_id IS NOT NULL GROUP BY tenant_id');
        }
        if ($presence->hasTable(self::RATES)) {
            $rates = $by('SELECT tenant_id, COUNT(*) AS n FROM salons_commission_rates GROUP BY tenant_id');
            // @tenant-guard-ignore: the copy runs once for the whole installation, reporting every workspace Salons ran in
            $inCore = $by('SELECT tenant_id, COUNT(*) AS n FROM calendar_commission_rates GROUP BY tenant_id');
        }
        $tenants = array_unique([...array_keys($walkins), ...array_keys($copied), ...array_keys($rates), ...array_keys($inCore)]);
        sort($tenants);
        $out = [];
        foreach ($tenants as $t) {
            $out[$t] = [
                'walkins' => $walkins[$t] ?? 0,
                'walkins_copied' => $copied[$t] ?? 0,
                'rates' => $rates[$t] ?? 0,
                'rates_in_core' => $inCore[$t] ?? 0,
            ];
        }

        return $out;
    }

    /** @return array{int, int} copied, skipped */
    private function walkins(): array
    {
        $rows = $this->all(
            'SELECT s.*
               FROM salons_walkins s
              WHERE NOT EXISTS (SELECT 1 FROM calendar_walkins c WHERE c.tenant_id = s.tenant_id AND c.legacy_id = s.id)
              ORDER BY s.id'
        , PDO::FETCH_ASSOC);
        $insert = $this->db->prepare(
            'INSERT INTO calendar_walkins (tenant_id, name, phone, service_id, preferred_profile_id, note, status, added_at,
                    added_by_profile_id, assigned_at, assigned_profile_id, booking_id, contact_id, left_at, legacy_id)
             VALUES (:t, :name, :phone, :service, :preferred, :note, :status, :added_at,
                    :added_by, :assigned_at, :assigned, :booking, :contact, :left_at, :legacy)'
        );
        $copied = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            $tenantId = (int) $r['tenant_id'];
            if (!$this->exists('calendar_services', $tenantId, (int) $r['service_id']) || !$this->tenantExists($tenantId)) {
                $skipped++;
                continue;
            }
            $status = (string) $r['status'];
            $insert->execute([
                ':t' => $tenantId,
                ':name' => mb_substr((string) $r['name'], 0, 200),
                ':phone' => mb_substr((string) $r['phone'], 0, 40),
                ':service' => (int) $r['service_id'],
                ':preferred' => $this->profileOrNull($r['preferred_profile_id']),
                ':note' => mb_substr((string) $r['note'], 0, 500),
                // A row one desk had claimed when Salons stopped goes back in the queue.
                ':status' => $status === WalkinQueue::ASSIGNING ? WalkinQueue::WAITING : $status,
                ':added_at' => (string) $r['added_at'],
                ':added_by' => $this->profileOrNull($r['added_by_profile_id']),
                ':assigned_at' => $r['assigned_at'],
                ':assigned' => $this->profileOrNull($r['assigned_profile_id']),
                ':booking' => $this->rowOrNull('calendar_bookings', $tenantId, $r['booking_id']),
                ':contact' => $this->rowOrNull('contacts', $tenantId, $r['contact_id']),
                ':left_at' => $r['left_at'],
                ':legacy' => (int) $r['id'],
            ]);
            $copied++;
        }

        return [$copied, $skipped];
    }

    private function rates(): int
    {
        $rows = $this->all(
            'SELECT s.tenant_id, s.profile_id, s.service_id, s.percent_bp, s.updated_at, s.updated_by_profile_id
               FROM salons_commission_rates s
              WHERE NOT EXISTS (SELECT 1 FROM calendar_commission_rates c
                                 WHERE c.tenant_id = s.tenant_id AND c.profile_id = s.profile_id AND c.service_id = s.service_id)
              ORDER BY s.id'
        , PDO::FETCH_ASSOC);
        $insert = $this->db->prepare(
            'INSERT INTO calendar_commission_rates (tenant_id, profile_id, service_id, percent_bp, updated_at, updated_by_profile_id)
             VALUES (:t, :p, :s, :bp, :at, :by)'
        );
        $copied = 0;
        foreach ($rows as $r) {
            $tenantId = (int) $r['tenant_id'];
            if ($this->profileOrNull($r['profile_id']) === null || !$this->tenantExists($tenantId)) {
                continue;
            }
            $insert->execute([
                ':t' => $tenantId,
                ':p' => (int) $r['profile_id'],
                ':s' => (int) $r['service_id'],
                ':bp' => max(0, min(10000, (int) $r['percent_bp'])),
                ':at' => (string) $r['updated_at'],
                ':by' => $this->profileOrNull($r['updated_by_profile_id']),
            ]);
            $copied++;
        }

        return $copied;
    }

    /** Every role that held a Salons permission gets core's. */
    private function grants(): int
    {
        $granted = 0;
        $insert = $this->db->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             SELECT rp.role_id, core.id, CURRENT_TIMESTAMP
               FROM role_permissions rp
               JOIN permissions old ON old.id = rp.permission_id AND old.name = :old
               JOIN permissions core ON core.name = :core
              WHERE NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = rp.role_id AND x.permission_id = core.id)'
        );
        foreach (self::GRANTS as $old => $core) {
            $insert->execute([':old' => $old, ':core' => $core]);
            $granted += $insert->rowCount();
        }

        return $granted;
    }

    /** Walk-ins on for every workspace that had walk-ins or Salons turned on, unless it chose already. */
    private function switchOn(): int
    {
        $tenants = [];
        foreach ($this->all('SELECT DISTINCT tenant_id FROM salons_walkins', PDO::FETCH_COLUMN) as $t) {
            $tenants[(int) $t] = true;
        }
        if ((new PdoSchemaPresence($this->db))->hasTable('tenant_code_plugins')) {
            // @tenant-guard-ignore: finds every workspace that had Salons turned on, across the installation, to turn walk-ins on there
            $on = $this->db->prepare('SELECT tenant_id FROM tenant_code_plugins WHERE plugin_name = :p AND enabled = :on');
            $on->bindValue(':p', 'Salons');
            $on->bindValue(':on', true, PDO::PARAM_BOOL);
            $on->execute();
            foreach ($on->fetchAll(PDO::FETCH_COLUMN) as $t) {
                $tenants[(int) $t] = true;
            }
        }
        $switched = 0;
        $insert = $this->db->prepare(
            'INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at)
             SELECT :t, :key, :value, CURRENT_TIMESTAMP
              WHERE NOT EXISTS (SELECT 1 FROM tenant_settings WHERE tenant_id = :t2 AND setting_key = :key2)'
        );
        foreach (array_keys($tenants) as $tenantId) {
            if (!$this->tenantExists($tenantId)) {
                continue;
            }
            $insert->execute([':t' => $tenantId, ':key' => SettingsRegistry::CALENDAR_WALKINS_ENABLED, ':value' => 'true', ':t2' => $tenantId, ':key2' => SettingsRegistry::CALENDAR_WALKINS_ENABLED]);
            $switched += $insert->rowCount();
        }

        return $switched;
    }

    /** @return list<mixed> */
    private function all(string $sql, int $mode): array
    {
        $stmt = $this->db->query($sql);

        return $stmt === false ? [] : array_values($stmt->fetchAll($mode));
    }

    private function tenantExists(int $tenantId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM tenants WHERE id = :id');
        $stmt->execute([':id' => $tenantId]);

        return $stmt->fetchColumn() !== false;
    }

    private function profileOrNull(mixed $id): ?int
    {
        if ($id === null || !is_numeric($id)) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT 1 FROM profiles WHERE id = :id');
        $stmt->execute([':id' => (int) $id]);

        return $stmt->fetchColumn() !== false ? (int) $id : null;
    }

    /** @param 'calendar_bookings'|'contacts' $table */
    private function rowOrNull(string $table, int $tenantId, mixed $id): ?int
    {
        if ($id === null || !is_numeric($id)) {
            return null;
        }

        return $this->exists($table, $tenantId, (int) $id) ? (int) $id : null;
    }

    /** @param 'calendar_services'|'calendar_bookings'|'contacts' $table */
    private function exists(string $table, int $tenantId, int $id): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM {$table} WHERE tenant_id = :t AND id = :id");
        $stmt->execute([':t' => $tenantId, ':id' => $id]);

        return $stmt->fetchColumn() !== false;
    }
}
