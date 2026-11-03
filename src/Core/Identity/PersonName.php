<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * The name a person appears under.
 *
 * `profiles.display_name` is often empty: it defaults to '' and accounts created by
 * an administrator never set it. The screens must still show a name and never an
 * id, so an empty one falls back to the email's local part, which is what the users
 * API has always shown.
 */
final class PersonName
{
    private function __construct()
    {
    }

    public static function of(string $displayName, string $email): string
    {
        $displayName = trim($displayName);
        if ($displayName !== '') {
            return $displayName;
        }
        $localPart = strstr($email, '@', true);

        return $localPart !== false && $localPart !== '' ? $localPart : $email;
    }
}
