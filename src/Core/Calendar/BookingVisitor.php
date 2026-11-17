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
 * Neither a profile nor a Whity ID sign-in carries a phone number yet, so
 * `phone` is null until one does.
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
        $name = mb_substr(trim($this->displayName($profileId)), 0, 200);
        if ($name === '' && $email === '') {
            return null;
        }

        return ['name' => $name, 'email' => $email, 'phone' => null];
    }

    private function displayName(int $profileId): string
    {
        // profiles is a sanctioned GLOBAL identity table (ADR 0005 §1, SanctionedGlobalTables); the id is the caller's own, from their validated session token.
        $stmt = $this->db->prepare('SELECT display_name FROM profiles WHERE id = ?');
        $stmt->execute([$profileId]);
        $name = $stmt->fetchColumn();

        return is_string($name) ? $name : '';
    }
}
