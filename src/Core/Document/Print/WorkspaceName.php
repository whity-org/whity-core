<?php

declare(strict_types=1);

namespace Whity\Core\Document\Print;

use PDO;

/**
 * A workspace's own name, for a letterhead that has nothing better.
 *
 * The letterhead prints the company name, then the white-label site name. A
 * workspace that has set neither got Whity's default site name, so its invoice
 * said "Whity" at the top. The workspace's own name ("Example Bakery") is the
 * honest fallback: it is what the people issuing the document call it.
 */
final class WorkspaceName
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function of(int $tenantId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM tenants WHERE id = :id');
        $stmt->execute(['id' => $tenantId]);
        $name = $stmt->fetchColumn();

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
