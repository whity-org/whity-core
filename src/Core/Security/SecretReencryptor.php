<?php

declare(strict_types=1);

namespace Whity\Core\Security;

use PDO;
use PDOException;

/**
 * Move every stored secret onto the key ring's current key (merge map
 * plumbing, WHIT-1047), after a rotation: `whity-cli secrets reencrypt`.
 *
 * For each place in {@see SecretLocations}: a value already on the current
 * key is left alone; one under an older key in the ring is decrypted and
 * written back under the current one; one that doesn't decrypt is counted and
 * left exactly as it is, never destroyed. Each write only lands if the value
 * is still the one read (compare and set), so a secret someone changes while
 * this runs, such as a mail refresh token being rotated, keeps their change.
 * A place whose table isn't there (an older or newer schema) is skipped.
 *
 * Safe to run any number of times. With `$dryRun` it counts and writes nothing.
 */
final class SecretReencryptor
{
    public function __construct(
        private readonly PDO $db,
        private readonly EncryptedSecretStore $secrets,
    ) {
    }

    /**
     * @param list<SecretRef>|null $locations Defaults to {@see SecretLocations::all()}.
     * @return list<array{label: string, moved: int, current: int, failed: int, missing: bool}>
     */
    public function run(bool $dryRun = false, ?array $locations = null): array
    {
        $report = [];
        foreach ($locations ?? SecretLocations::all() as $ref) {
            $line = ['label' => $ref->label, 'moved' => 0, 'current' => 0, 'failed' => 0, 'missing' => false];
            try {
                foreach ($this->values($ref) as [$id, $stored]) {
                    $outcome = $this->one($ref, $id, $stored, $dryRun);
                    $line[$outcome]++;
                }
            } catch (PDOException) {
                $line['missing'] = true;
            }
            $report[] = $line;
        }

        return $report;
    }

    /** @return 'moved'|'current'|'failed' */
    private function one(SecretRef $ref, int|string $id, string $stored, bool $dryRun): string
    {
        $ciphertext = $stored;
        $json = null;
        if ($ref->kind === SecretRef::INSTANCE_SETTING_JSON_FIELD) {
            $json = json_decode($stored, true);
            if (!is_array($json) || !is_string($json[$ref->field] ?? null)) {
                return 'failed';
            }
            $ciphertext = $json[$ref->field];
        }

        try {
            $plain = $this->secrets->decrypt($ciphertext);
        } catch (\Throwable) {
            return 'failed';
        }
        if ($this->secrets->isEncryptedWithCurrentKey($ciphertext)) {
            return 'current';
        }
        if ($dryRun) {
            return 'moved';
        }

        $fresh = $this->secrets->encrypt($plain);
        if ($json !== null) {
            $json[$ref->field] = $fresh;
            $fresh = (string) json_encode($json, JSON_UNESCAPED_SLASHES);
        }
        // @tenant-guard-ignore: instance maintenance after a key rotation; one row by its id, and only while it still holds what was read
        $stmt = $this->db->prepare(
            "UPDATE {$ref->table} SET {$ref->column} = :fresh WHERE {$ref->idColumn} = :id AND {$ref->column} = :stored"
        );
        $stmt->execute([':fresh' => $fresh, ':id' => $id, ':stored' => $stored]);

        return $stmt->rowCount() === 1 ? 'moved' : 'current';
    }

    /** @return list<array{0: int|string, 1: string}> */
    private function values(SecretRef $ref): array
    {
        // @tenant-guard-ignore: instance maintenance after a key rotation; every workspace's secrets move to the new key, each row found by its own id
        $sql = match ($ref->kind) {
            SecretRef::COLUMN => "SELECT {$ref->idColumn} AS id, {$ref->column} AS value FROM {$ref->table} WHERE {$ref->column} IS NOT NULL AND {$ref->column} <> ''",
            SecretRef::WORKSPACE_SETTING => "SELECT id, value FROM tenant_settings WHERE setting_key LIKE :key AND value <> ''",
            default => "SELECT id, value FROM app_settings WHERE setting_key = :key AND value IS NOT NULL AND value <> ''",
        };
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ref->kind === SecretRef::COLUMN ? [] : [':key' => $ref->key]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return array_values(array_map(
            static fn (array $r): array => [is_numeric($r['id']) ? (int) $r['id'] : (string) $r['id'], (string) $r['value']],
            $rows,
        ));
    }
}
