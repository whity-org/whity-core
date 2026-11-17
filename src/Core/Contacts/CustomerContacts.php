<?php

declare(strict_types=1);

namespace Whity\Core\Contacts;

use PDO;

/**
 * Contacts as the one customer record (merge map, plumbing).
 *
 * A booking, a web chat visitor and a join request each name a customer in
 * their own words: a name, an email, a phone. This finds the contact they are,
 * by email first, then by mobile, then by the person's account, and creates one
 * when there is none, so a customer's page can show everything they did here.
 * The record keeps its own typed details as a snapshot of what was given then.
 *
 * Nothing to match on (no email, phone or account) finds nothing: a name alone
 * would merge different people.
 */
final class CustomerContacts
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** The contact for this customer, found or created; null when there is nothing to match on. */
    public function forCustomer(int $tenantId, string $name, string $email, string $phone, ?int $profileId = null): ?int
    {
        $email = strtolower(trim($email));
        $mobile = PhoneNumber::tidy($phone)['value'] ?? '';
        if ($email === '' && $mobile === '' && ($profileId === null || $profileId <= 0)) {
            return null;
        }

        $repo = new ContactRepository($this->db);
        $found = ($email !== '' ? $repo->findBy($tenantId, 'email', $email) : null)
            ?? ($mobile !== '' ? $repo->findBy($tenantId, 'mobile', $mobile) : null)
            ?? ($profileId !== null && $profileId > 0 ? $this->byProfile($tenantId, $profileId) : null);
        if ($found !== null) {
            return (int) $found['id'];
        }

        $name = trim($name) !== '' ? trim($name) : ($email !== '' ? $email : $mobile);
        if ($name === '') {
            return null;
        }

        return $repo->insert($tenantId, [
            'name' => mb_substr($name, 0, 200),
            'email' => mb_substr($email, 0, 320),
            'mobile' => mb_substr($mobile, 0, 40),
            'is_customer' => true,
        ] + ($profileId !== null && $profileId > 0 ? ['profile_id' => $profileId] : []), null);
    }

    /** @return array<string, mixed>|null */
    private function byProfile(int $tenantId, int $profileId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM contacts WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND archived_at IS NULL ORDER BY id LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
