<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;
use Whity\Auth\JwtParser;
use Whity\Core\Store\SharedStoreInterface;

/**
 * Platform operators and their way in when password sign-in is off (WHIT-1350).
 *
 * Operators are the active members of the system workspace (tenant 0), the same
 * rule every instance-wide surface uses, leaving out service principals
 * (`auth_method = 'service'`, such as the CLI's), which never sign in. With
 * `auth.password_sign_in` off they can only sign in through the operator's identity provider, so an operator with
 * no global-trust link to it (`external_identities.provider_id IS NULL`) has no
 * way in at all. The provider links a first sign-in only by a verified email
 * match, so an operator whose provider address differs never links by signing in.
 *
 * Two things here close that:
 *
 * - {@see unlinked()} names those operators, so turning password sign-in off
 *   can refuse, and `password-sign-in status` can show them.
 * - {@see issueLink()} / {@see consumeLink()}: a sign-in link printed by
 *   `whity-cli auth operator-link <email>` on the server. It is good for
 *   {@see LINK_TTL} seconds, once, for one operator with no link, and every
 *   step is audited. It opens a system-workspace session for that operator so
 *   they can link their provider from Settings › Security, which links whatever
 *   the provider's address is.
 *
 * Why a link from the shell rather than letting operators keep a password: the
 * link needs a shell on the server, which already holds every secret, so it adds
 * no new way in for anyone who doesn't. A password kept for operators would leave
 * the public sign-in form open on the most powerful accounts, for as long as one
 * of them stays unlinked.
 */
final class OperatorSignIn
{
    /** How long a printed link works, in seconds. */
    public const LINK_TTL = 900;

    /** The signed token's type, so no other token can stand in for it. */
    public const TOKEN_TYPE = 'operator_link';

    /** Audit actions. */
    public const AUDIT_ISSUED = 'auth.operator_link.issued';
    public const AUDIT_USED = 'auth.operator_link.used';
    /**
     * A link that was refused. Named like a failed password reset: an opening
     * is refused before anyone is known, so the row names no person and sits
     * with the system workspace, like every other failed sign-in.
     */
    public const AUDIT_REFUSED = 'auth.operator_link.failed';

    private const SYSTEM_TENANT_ID = 0;

    /**
     * Active, not deactivated operators with no global-trust provider link.
     *
     * @return list<array{profile_id: int, email: string, display_name: string}>
     */
    public static function unlinked(PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            "SELECT p.id AS profile_id, p.display_name,
                    (SELECT pe.email FROM profile_emails pe
                      WHERE pe.profile_id = p.id
                      ORDER BY pe.is_primary DESC, pe.id ASC LIMIT 1) AS email
               FROM memberships m
               JOIN profiles p ON p.id = m.profile_id
              WHERE m.tenant_id = :tenant
                AND m.status = 'active'
                AND COALESCE(p.status, 'active') <> 'inactive'
                AND COALESCE(p.auth_method, '') <> 'service'
                AND NOT EXISTS (
                    SELECT 1 FROM external_identities ei
                     WHERE ei.profile_id = p.id AND ei.provider_id IS NULL
                )
              ORDER BY p.id ASC"
        );
        $stmt->execute([':tenant' => self::SYSTEM_TENANT_ID]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'profile_id' => (int) $row['profile_id'],
                'email' => (string) ($row['email'] ?? ''),
                'display_name' => (string) ($row['display_name'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The refusal to show when turning password sign-in off would leave these
     * operators with no way in, or null when every operator has a link.
     */
    public static function unlinkedProblem(PDO $pdo): ?string
    {
        $unlinked = self::unlinked($pdo);
        if ($unlinked === []) {
            return null;
        }

        return 'These platform operators have no Whity ID linked and could not sign in: '
            . self::names($unlinked)
            . '. Each of them can link it in Settings › Security first.';
    }

    /**
     * @param list<array{profile_id: int, email: string, display_name: string}> $operators
     */
    public static function names(array $operators): string
    {
        return implode(', ', array_map(
            static fn (array $o): string => $o['email'] !== '' ? $o['email'] : ('profile ' . $o['profile_id']),
            $operators,
        ));
    }

    /**
     * The operator a given address belongs to, or null when it is nobody's, or
     * the person is not an active operator.
     *
     * @return array{profile_id: int, email: string, display_name: string, linked: bool}|null
     */
    public static function findOperatorByEmail(PDO $pdo, string $email): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT p.id AS profile_id, p.display_name, pe.email,
                    EXISTS (SELECT 1 FROM external_identities ei
                             WHERE ei.profile_id = p.id AND ei.provider_id IS NULL) AS linked
               FROM profile_emails pe
               JOIN profiles p ON p.id = pe.profile_id
               JOIN memberships m ON m.profile_id = p.id
                AND m.tenant_id = :tenant AND m.status = 'active'
              WHERE LOWER(pe.email) = LOWER(:email)
                AND COALESCE(p.status, 'active') <> 'inactive'
                AND COALESCE(p.auth_method, '') <> 'service'
              LIMIT 1"
        );
        $stmt->execute([':tenant' => self::SYSTEM_TENANT_ID, ':email' => trim($email)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'profile_id' => (int) $row['profile_id'],
            'email' => (string) $row['email'],
            'display_name' => (string) ($row['display_name'] ?? ''),
            'linked' => (bool) $row['linked'],
        ];
    }

    public static function isUnlinkedOperator(PDO $pdo, int $profileId): bool
    {
        foreach (self::unlinked($pdo) as $operator) {
            if ($operator['profile_id'] === $profileId) {
                return true;
            }
        }

        return false;
    }

    /** A signed, short-lived token naming one operator. */
    public static function issueLink(JwtParser $jwt, int $profileId): string
    {
        return $jwt->create(['profile_id' => $profileId], self::LINK_TTL, self::TOKEN_TYPE);
    }

    /**
     * Check a token and use it up. Returns the operator's profile id, or a
     * reason it can't be used: `invalid` (not ours, wrong type, or expired),
     * `used` (somebody already opened it), `not_operator` (no longer an active
     * operator, or now has a link and should sign in with the provider).
     *
     * @return array{profile_id: int}|array{refused: 'invalid'|'used'|'not_operator', profile_id?: int}
     */
    public static function consumeLink(PDO $pdo, JwtParser $jwt, SharedStoreInterface $store, string $token, ?int $now = null): array
    {
        $now ??= time();
        $claims = strlen($token) > 2048 ? null : $jwt->parse($token);
        if (!is_array($claims)
            || ($claims['type'] ?? null) !== self::TOKEN_TYPE
            || !is_int($claims['profile_id'] ?? null)
            || !is_string($claims['jti'] ?? null)
            || !is_int($claims['iat'] ?? null)
            // The parser allows a little clock skew; the link never outlives its 15 minutes.
            || $now - $claims['iat'] > self::LINK_TTL
            || $claims['iat'] > $now + 60
        ) {
            return ['refused' => 'invalid'];
        }
        $profileId = $claims['profile_id'];

        // Single use: the first opening counts 1, any other more. Kept past the
        // token's own lifetime so the count can't expire before the token does.
        if ($store->increment('operator_link:' . $claims['jti'], self::LINK_TTL + 300) !== 1) {
            return ['refused' => 'used', 'profile_id' => $profileId];
        }

        if (!self::isUnlinkedOperator($pdo, $profileId)) {
            return ['refused' => 'not_operator', 'profile_id' => $profileId];
        }

        return ['profile_id' => $profileId];
    }
}
