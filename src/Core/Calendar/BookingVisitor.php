<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use PDO;

/**
 * The signed-in person looking at a public booking page, as their own Whity
 * account describes them (WHIT-1179): their name and email, so the page can
 * fill those fields for them. They stay editable, and what the person sends
 * is what the booking uses.
 *
 * ONLY THEIR OWN ACCOUNT
 * The name comes from their profile (a sanctioned global identity table, ADR
 * 0005) and the email from their own session token. Nothing is read from any
 * workspace: not the workspace whose page this is, and not the contacts,
 * people or bookings of any other workspace they belong to. A person who is
 * already a member or a contact of the workspace gets the same answer, from
 * their own account, so the page behaves the same for everyone signed in.
 *
 * PHONE
 * The person's own phone from their profile (WHIT-1181), E.164, or null.
 */
final class BookingVisitor
{
    /**
     * @param \Closure(): (array<string, mixed>|null) $claims the visitor's validated
     *        access-token claims (the session cookie), or null when signed out
     */
    public function __construct(
        private readonly \Closure $claims,
        private readonly PDO $db,
    ) {
    }

    /** @return array{name: string, email: string, phone: string|null}|null null when signed out */
    public function current(): ?array
    {
        $claims = ($this->claims)();
        if (!is_array($claims)) {
            return null;
        }
        $profileId = $claims['profile_id'] ?? null;
        if (!is_int($profileId) || $profileId <= 0) {
            return null;
        }

        $email = is_string($claims['email'] ?? null) ? strtolower(trim($claims['email'])) : '';
        if ($email !== '' && (mb_strlen($email) > 320 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $email = '';
        }
        // profiles is a sanctioned GLOBAL identity table (ADR 0005 §1, SanctionedGlobalTables); the id is the caller's own, from their validated session token.
        $stmt = $this->db->prepare('SELECT display_name, phone FROM profiles WHERE id = ?');
        $stmt->execute([$profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = is_array($row) ? $row : [];

        $name = mb_substr(trim(is_string($row['display_name'] ?? null) ? $row['display_name'] : ''), 0, 200);
        $phone = is_string($row['phone'] ?? null) && $row['phone'] !== '' ? $row['phone'] : null;
        if ($name === '' && $email === '' && $phone === null) {
            return null;
        }

        return ['name' => $name, 'email' => $email, 'phone' => $phone];
    }
}
