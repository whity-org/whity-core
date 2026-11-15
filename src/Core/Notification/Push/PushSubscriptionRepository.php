<?php

declare(strict_types=1);

namespace Whity\Core\Notification\Push;

use PDO;

/**
 * The devices people turned push notifications on for (WHIT-1073). Every
 * statement binds the tenant; the person's own calls bind the profile too.
 *
 * @phpstan-type Subscription array{
 *   id: int, tenant_id: int, profile_id: int, endpoint: string, p256dh: string, auth: string,
 *   user_agent: string, failures: int, last_success_at: string|null, last_failure_at: string|null, created_at: string
 * }
 */
final class PushSubscriptionRepository
{
    /** A person's devices in one workspace; past this the oldest goes. */
    public const MAX_PER_PERSON = 20;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Store this browser for this person, or refresh it. The same browser
     * signing in as someone else in the same workspace moves to them: a shared
     * phone gets the notifications of whoever turned them on last, never both.
     *
     * @return int The row id.
     */
    public function upsert(int $tenantId, int $profileId, string $endpoint, string $p256dh, string $auth, string $userAgent): int
    {
        $hash = PushEndpoint::hash($endpoint);
        $userAgent = mb_substr($userAgent, 0, 512);

        $existing = $this->idForEndpoint($tenantId, $hash);
        if ($existing !== null) {
            $stmt = $this->db->prepare(
                'UPDATE push_subscriptions
                    SET profile_id = :profile_id, endpoint = :endpoint, p256dh = :p256dh, auth = :auth,
                        user_agent = :user_agent, failures = 0, last_failure_at = NULL, updated_at = NOW()
                  WHERE id = :id AND tenant_id = :tenant_id'
            );
            $stmt->execute([
                ':profile_id' => $profileId, ':endpoint' => $endpoint, ':p256dh' => $p256dh, ':auth' => $auth,
                ':user_agent' => $userAgent, ':id' => $existing, ':tenant_id' => $tenantId,
            ]);
            $this->trim($tenantId, $profileId);

            return $existing;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO push_subscriptions (tenant_id, profile_id, endpoint, endpoint_hash, p256dh, auth, user_agent)
             VALUES (:tenant_id, :profile_id, :endpoint, :hash, :p256dh, :auth, :user_agent)
             ON CONFLICT (tenant_id, endpoint_hash) DO NOTHING'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId, ':profile_id' => $profileId, ':endpoint' => $endpoint, ':hash' => $hash,
            ':p256dh' => $p256dh, ':auth' => $auth, ':user_agent' => $userAgent,
        ]);
        // A concurrent subscribe of the same browser may have won the insert.
        $id = $this->idForEndpoint($tenantId, $hash) ?? throw new \RuntimeException('Push subscription was not stored.');
        $this->trim($tenantId, $profileId);

        return $id;
    }

    /**
     * One person's devices, newest first.
     *
     * @return list<Subscription>
     */
    public function listForProfile(int $tenantId, int $profileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM push_subscriptions WHERE tenant_id = :tenant_id AND profile_id = :profile_id ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return array_values(array_map(self::row(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** Whether this person has this browser on. */
    public function findForProfileByEndpoint(int $tenantId, int $profileId, string $endpoint): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM push_subscriptions WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND endpoint_hash = :hash'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId, ':hash' => PushEndpoint::hash($endpoint)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Remove one of the person's own devices. Returns whether it was theirs. */
    public function deleteForProfile(int $tenantId, int $profileId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM push_subscriptions WHERE id = :id AND tenant_id = :tenant_id AND profile_id = :profile_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return $stmt->rowCount() === 1;
    }

    /** Remove a device the push service says is gone (404/410). */
    public function delete(int $tenantId, int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM push_subscriptions WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
    }

    public function markSuccess(int $tenantId, int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE push_subscriptions SET failures = 0, last_success_at = NOW() WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
    }

    public function markFailure(int $tenantId, int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE push_subscriptions SET failures = failures + 1, last_failure_at = NOW() WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
    }

    private function idForEndpoint(int $tenantId, string $hash): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM push_subscriptions WHERE tenant_id = :tenant_id AND endpoint_hash = :hash');
        $stmt->execute([':tenant_id' => $tenantId, ':hash' => $hash]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Keep a person's newest MAX_PER_PERSON devices. */
    private function trim(int $tenantId, int $profileId): void
    {
        $rows = $this->listForProfile($tenantId, $profileId);
        foreach (array_slice($rows, self::MAX_PER_PERSON) as $old) {
            $this->delete($tenantId, $old['id']);
        }
    }

    /**
     * @param array<string, mixed> $r
     * @return Subscription
     */
    private static function row(array $r): array
    {
        return [
            'id'              => (int) $r['id'],
            'tenant_id'       => (int) $r['tenant_id'],
            'profile_id'      => (int) $r['profile_id'],
            'endpoint'        => (string) $r['endpoint'],
            'p256dh'          => (string) $r['p256dh'],
            'auth'            => (string) $r['auth'],
            'user_agent'      => (string) $r['user_agent'],
            'failures'        => (int) $r['failures'],
            'last_success_at' => $r['last_success_at'] !== null ? (string) $r['last_success_at'] : null,
            'last_failure_at' => $r['last_failure_at'] !== null ? (string) $r['last_failure_at'] : null,
            'created_at'      => (string) $r['created_at'],
        ];
    }
}
