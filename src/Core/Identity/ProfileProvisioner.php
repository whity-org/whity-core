<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * Find-or-create the global profile that owns an email address.
 *
 * `profile_emails.email` is globally UNIQUE (ADR 0005 §2), so an email that
 * already has a profile REUSES it — the same person added to a second tenant is
 * one identity with two memberships, never two identities. Creating a duplicate
 * here would not merely be untidy: it would split that person's credentials and
 * token epoch across two rows, so a password change or a forced logout would
 * apply to only one of them.
 *
 * This lives outside the API handlers because BOTH the user-creation path and
 * tenant provisioning need it, and an identity written two slightly different
 * ways is an identity that eventually diverges.
 *
 * Every method here MUST run inside the caller's transaction. A profile without
 * its primary email, or an email without its profile, is a broken identity that
 * no later request can repair — so the caller owns the transaction boundary,
 * because only the caller knows what else has to land with it.
 */
final class ProfileProvisioner
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The profile id owning `$email`, creating the profile if there is none.
     *
     * The password hash is used ONLY when creating; an existing profile keeps
     * the credential it already had. Silently re-hashing someone's password
     * because an administrator typed one into a provisioning form would be a
     * credential reset disguised as a membership grant.
     *
     * @param string $email        The address, already validated by the caller.
     * @param string $passwordHash A hash for the profile, when one is created.
     * @return int The profile id, existing or new.
     */
    public function findOrCreate(string $email, string $passwordHash): int
    {
        $profileId = $this->verifiedOwner($email);
        if ($profileId !== null) {
            return $profileId;
        }

        // #2245: an address nobody has CONFIRMED belongs to nobody. Release an
        // unconfirmed claim before the new profile takes the address.
        $this->releaseUnconfirmedClaim($email);

        // Profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $profile = $this->db->prepare(
            'INSERT INTO profiles
                 (display_name, password_hash, two_factor_enabled,
                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, false, 0, 0, NOW(), NOW())'
        );
        $profile->execute([self::localPart($email), $passwordHash]);
        $newId = (int) $this->db->lastInsertId();

        // Profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2)
        $this->db->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, NOW())'
        )->execute([$newId, $email]);

        return $newId;
    }

    /**
     * The profile owning `$email` through a CONFIRMED address, or null (#2245).
     *
     * `profile_emails.email` is globally unique, but a row is only a CLAIM until
     * its owner follows the link sent to the address: anyone signed in can add
     * any address to their own account, unconfirmed. Joining that address to a
     * workspace, an invitation or a sign-up on the strength of such a row would
     * hand somebody else's membership to whoever typed it first. So every
     * identity-linking lookup answers only for confirmed rows.
     */
    public function verifiedOwner(string $email): ?int
    {
        // Profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2); UNIQUE(email)
        $stmt = $this->db->prepare('SELECT profile_id, verified FROM profile_emails WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false || !DbBool::of($row['verified'] ?? false)) {
            return null;
        }

        return (int) $row['profile_id'];
    }

    /**
     * Free an address for a new owner who has shown they hold it, or who an
     * administrator is creating (#2245).
     *
     * An unconfirmed SECONDARY address is a pending claim: it is removed (its
     * pending confirmation goes with it), so a squatter can neither take the
     * membership nor block the real owner. An unconfirmed PRIMARY address is
     * somebody's sign-in address that was never confirmed; deleting it would
     * break that account, so this refuses instead. A confirmed address is never
     * touched. Call only after {@see self::verifiedOwner()} returned null.
     *
     * @throws UnconfirmedEmailClaimException when the address is another
     *         account's unconfirmed sign-in address.
     */
    public function releaseUnconfirmedClaim(string $email): void
    {
        // Profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2)
        $stmt = $this->db->prepare('SELECT id, verified, is_primary FROM profile_emails WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || DbBool::of($row['verified'] ?? false)) {
            return;
        }
        if (DbBool::of($row['is_primary'] ?? false)) {
            throw new UnconfirmedEmailClaimException();
        }

        // Profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2)
        $this->db->prepare('DELETE FROM profile_emails WHERE id = ? AND verified = false AND is_primary = false')
            ->execute([(int) $row['id']]);
    }

    /**
     * The local part of an email, used as a new profile's display name.
     *
     * `strrpos` rather than `strpos`: the local part may itself contain a quoted
     * '@', and the domain may not, so the LAST one is the separator.
     */
    public static function localPart(string $email): string
    {
        $at = strrpos($email, '@');

        return $at !== false ? substr($email, 0, $at) : $email;
    }
}
