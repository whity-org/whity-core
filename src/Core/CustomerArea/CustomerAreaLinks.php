<?php

declare(strict_types=1);

namespace Whity\Core\CustomerArea;

use PDO;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * "See it in your account" links for emails to customers (WHIT-1428; design
 * section 41, CA.8).
 *
 * A link is given only when the customer area is on, the workspace has a
 * handle, and the address the email goes to is a VERIFIED address of someone
 * with an active membership in the workspace: the same rule the area uses to
 * link contacts. Anyone else gets the email as before, token link and all.
 * Nobody is ever pushed to make an account.
 */
final class CustomerAreaLinks
{
    public function __construct(
        private readonly PDO $db,
        private readonly SettingsService $settings,
        private readonly TenantHostRepository $tenants,
        /** The web app's origin, e.g. https://app.whity.dev. */
        private readonly string $base,
    ) {
    }

    /**
     * The account page for `$path` (`bookings/12`, `bills/quotes/7`), or null.
     *
     * @return array{label: string, url: string}|null
     */
    public function forEmail(int $tenantId, string $email, string $path, bool $arabic): ?array
    {
        $email = strtolower(trim($email));
        if ($tenantId <= 0 || $email === '' || trim($this->base) === '') {
            return null;
        }
        try {
            if (($this->settings->effective($tenantId)[SettingsRegistry::CUSTOMER_AREA_ENABLED] ?? 'true') !== 'true') {
                return null;
            }
            $handle = $this->tenants->findSlugById($tenantId);
            if ($handle === null || $handle === '' || !$this->hasAccount($tenantId, $email)) {
                return null;
            }
        } catch (\Throwable) {
            // A link is a courtesy: the email goes out without it.
            return null;
        }

        return [
            'label' => $arabic ? 'اعرضه في حسابك' : 'See it in your account',
            'url' => rtrim($this->base, '/') . '/@' . rawurlencode($handle) . '/' . ltrim($path, '/'),
        ];
    }

    private function hasAccount(int $tenantId, string $email): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM profile_emails e
               JOIN memberships m ON m.profile_id = e.profile_id AND m.tenant_id = :tenant_id AND m.status = 'active'
              WHERE LOWER(e.email) = :email AND e.verified = TRUE
              LIMIT 1"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':email' => $email]);

        return $stmt->fetchColumn() !== false;
    }
}
