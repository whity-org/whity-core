<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;

/**
 * A person's display name by profile id, for printing and exporting answers
 * that name people (WHIT-1162): people are shown by name, never by id.
 *
 * Remembers each name it reads, so an export of many rows naming the same
 * people asks once per person. Make one per request; it is not shared.
 */
final class ProfileNames
{
    /** @var array<int, ?string> */
    private array $known = [];

    public function __construct(private readonly PDO $db)
    {
    }

    public function __invoke(int $profileId): ?string
    {
        if (array_key_exists($profileId, $this->known)) {
            return $this->known[$profileId];
        }

        try {
            $stmt = $this->db->prepare('SELECT display_name FROM profiles WHERE id = :id');
            $stmt->execute([':id' => $profileId]);
            $name = $stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log('[ProfileNames] reading a name failed: ' . $e->getMessage());
            $name = null;
        }

        return $this->known[$profileId] = is_string($name) && $name !== '' ? $name : null;
    }
}
