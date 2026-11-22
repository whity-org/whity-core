<?php

declare(strict_types=1);

namespace Whity\Auth;

use PDO;

/**
 * Lifecycle for interactive login sessions (WC-f-sessions-table).
 *
 * A "session" is a refresh-token FAMILY: one `sessions` row that records the
 * CURRENT access + refresh jti for an interactive login, rotated in place on
 * every refresh. This gives users a list of their active sessions (user-agent,
 * IP, created / last-seen) and per-session revocation.
 *
 * Revocation reuses the shared `revoked_tokens` jti keyspace: it blacklists the
 * session's current access + refresh jti — which validateAccessToken's existing
 * isTokenRevoked check already consults — so the live access token dies
 * immediately, and stamps revoked_at on the row. No change to the hot
 * validation path and no new token claim.
 *
 * Native-device credentials are NOT tracked here (they live in `devices`,
 * migration 044, with their own list); this is interactive logins only.
 *
 * Worker-safe: no static/global state — all state is per-call on the stack.
 * Every method is best-effort where it must never break auth: a session-table
 * failure must not stop a login/refresh, so the AuthHandler call sites guard
 * accordingly.
 */
final class SessionService
{
    public function __construct(private readonly PDO $db) {}

    /**
     * Record a NEW interactive session (on login / tenant-selection completion).
     *
     * @param string $expiresAt 'Y-m-d H:i:s' of the refresh token's expiry.
     */
    public function start(
        int $profileId,
        int $tenantId,
        string $accessJti,
        string $refreshJti,
        string $expiresAt,
        ?string $userAgent,
        ?string $ip,
        ?string $family = null,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO sessions
                 (profile_id, tenant_id, refresh_jti, access_jti, user_agent, ip_address, expires_at, family)
             VALUES (:profile_id, :tenant_id, :refresh_jti, :access_jti, :user_agent, :ip, :expires_at, :family)'
        );
        $stmt->execute([
            ':profile_id'  => $profileId,
            ':tenant_id'   => $tenantId,
            ':refresh_jti' => $refreshJti,
            ':access_jti'  => $accessJti,
            ':user_agent'  => $userAgent !== null ? substr($userAgent, 0, 512) : null,
            ':ip'          => $ip !== null ? substr($ip, 0, 45) : null,
            ':expires_at'  => $expiresAt,
            ':family'      => $family,
        ]);
    }

    /** How long a just-replaced refresh token may race its successor (#2264). */
    public const REUSE_GRACE_SECONDS = 30;

    /**
     * The family id of the live session whose current access or refresh jti is
     * $jti, or null when there is none (or it predates families).
     */
    public function familyOf(string $jti): ?string
    {
        // @tenant-guard-ignore: matched by the caller's own current jti (access OR refresh), unique platform-wide.
        $stmt = $this->db->prepare(
            'SELECT family FROM sessions
              WHERE (refresh_jti = :jti OR access_jti = :jti) AND revoked_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([':jti' => $jti]);
        $family = $stmt->fetchColumn();

        return is_string($family) && $family !== '' ? $family : null;
    }

    /**
     * A refresh token that was already rotated away came back (#2264, #2191).
     *
     * Its session is found by the token's `fam` claim, or, for a token minted
     * before families, by being the session's previous refresh jti. If it is the
     * token the last rotation replaced and that rotation was under
     * REUSE_GRACE_SECONDS ago, it is two tabs racing: 'grace', nothing changes.
     * Any other reuse means two holders of one family, so the whole session is
     * revoked (both current jtis blacklisted, the row stamped): 'revoked'.
     * 'already_revoked' and 'unknown' change nothing.
     *
     * @return 'grace'|'revoked'|'already_revoked'|'unknown'
     */
    public function onRefreshReuse(string $jti, ?string $family, ?int $now = null): string
    {
        $now ??= time();

        if ($family !== null && $family !== '') {
            // @tenant-guard-ignore: family is a 128-bit random id carried by the caller's own signed refresh token, unique platform-wide.
            $stmt = $this->db->prepare(
                'SELECT id, access_jti, refresh_jti, expires_at, previous_refresh_jti, rotated_at, revoked_at
                   FROM sessions WHERE family = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$family]);
        } else {
            // @tenant-guard-ignore: matched by a refresh jti from the caller's own signed token; jtis are unique platform-wide.
            $stmt = $this->db->prepare(
                'SELECT id, access_jti, refresh_jti, expires_at, previous_refresh_jti, rotated_at, revoked_at
                   FROM sessions WHERE previous_refresh_jti = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$jti]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 'unknown';
        }
        if ($row['revoked_at'] !== null) {
            return 'already_revoked';
        }

        $rotatedAt = is_string($row['rotated_at']) ? strtotime($row['rotated_at']) : false;
        if ($row['previous_refresh_jti'] === $jti
            && $rotatedAt !== false
            && $now - $rotatedAt <= self::REUSE_GRACE_SECONDS
        ) {
            return 'grace';
        }

        $this->blacklistAndStamp(
            (int) $row['id'],
            $row['access_jti'] !== null ? (string) $row['access_jti'] : null,
            (string) $row['refresh_jti'],
            (string) $row['expires_at']
        );

        return 'revoked';
    }

    /**
     * Rotate an existing session in place: the family whose CURRENT access OR
     * refresh jti is $matchJti adopts the freshly-minted access + refresh jtis
     * and its last_seen / expiry are bumped. Returns false when no active row
     * matched (a session that predates this feature, or was already revoked) —
     * the caller keeps working; it simply is not (yet) listed.
     *
     * Matching on EITHER jti is deliberate: the refresh flow presents a refresh
     * token (so $matchJti is the current refresh_jti), while the re-mint flows
     * (switch-tenant / password-change / logout-others) authenticate with an
     * access token (so $matchJti is the current access_jti). Both jtis are
     * 128-bit-random platform-wide unique, so this never rotates the wrong row.
     *
     * The access token being replaced is revoked too (WHIT-905). Otherwise it kept
     * working until it expired, so signing out, which revokes only the tokens it
     * is handed, left an older access token alive for up to 15 minutes.
     *
     * The row moves to the workspace the new tokens act in (#2218): after a
     * switch-tenant it kept the sign-in workspace, so the person's own session
     * vanished from GET /api/v1/me/sessions in the new one and could not be
     * revoked there. Null keeps the row's workspace.
     *
     * @param string $matchJti  The caller's CURRENT access or refresh jti.
     * @param string $expiresAt 'Y-m-d H:i:s' of the new refresh token's expiry.
     */
    public function rotate(
        string $matchJti,
        string $newAccessJti,
        string $newRefreshJti,
        string $expiresAt,
        ?int $tenantId = null,
        ?string $family = null,
    ): bool {
        // @tenant-guard-ignore: matched by the caller's own current jti (access OR refresh), unique platform-wide.
        $previous = $this->db->prepare(
            'SELECT access_jti, refresh_jti FROM sessions
              WHERE (refresh_jti = :match OR access_jti = :match) AND revoked_at IS NULL'
        );
        $previous->execute([':match' => $matchJti]);
        $previousRow = $previous->fetch(PDO::FETCH_ASSOC);
        if (!is_array($previousRow)) {
            return false;
        }
        $replacedAccessJti = $previousRow['access_jti'];
        $replacedRefreshJti = (string) $previousRow['refresh_jti'];

        // Matched by the caller's own current jti (access OR refresh) — each is a platform-wide UNIQUE handle, so it cannot touch another tenant's session row.
        $stmt = $this->db->prepare(
            'UPDATE sessions
                SET refresh_jti = :new_refresh, access_jti = :new_access,
                    last_seen_at = NOW(), expires_at = :expires_at,
                    previous_refresh_jti = :replaced_refresh, rotated_at = :rotated_at'
                . ($tenantId !== null ? ', tenant_id = :tenant_id' : '')
                . ($family !== null ? ', family = :family' : '') . '
              WHERE refresh_jti = :replaced_refresh AND revoked_at IS NULL'
        );
        // Matched on the refresh jti just read, so of two requests racing on one
        // token only the first rotates; the other gets false (#2264).
        $params = [
            ':new_refresh'      => $newRefreshJti,
            ':new_access'       => $newAccessJti,
            ':expires_at'       => $expiresAt,
            ':replaced_refresh' => $replacedRefreshJti,
            ':rotated_at'       => date('Y-m-d H:i:s'),
        ];
        if ($family !== null) {
            $params[':family'] = $family;
        }
        if ($tenantId !== null) {
            $params[':tenant_id'] = $tenantId;
        }
        $stmt->execute($params);
        $rotated = $stmt->rowCount() > 0;

        if ($rotated && is_string($replacedAccessJti) && $replacedAccessJti !== '' && $replacedAccessJti !== $newAccessJti) {
            // Kept until well past any access token's 15-minute life, then swept.
            $this->db->prepare(
                'INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?) ON CONFLICT (jti) DO NOTHING'
            )->execute([$replacedAccessJti, date('Y-m-d H:i:s', time() + 3600)]);
        }

        return $rotated;
    }

    /**
     * Whether $refreshJti is the CURRENT refresh jti of a session that is not
     * revoked and not expired. handleRefresh() requires it (#2265), so a
     * refresh token rotates only inside a recorded, revocable session.
     */
    public function isLiveRefresh(string $refreshJti): bool
    {
        // @tenant-guard-ignore: refresh_jti is a 128-bit random handle unique platform-wide, carried by the caller's own validated refresh token.
        $stmt = $this->db->prepare(
            'SELECT 1 FROM sessions
              WHERE refresh_jti = ? AND revoked_at IS NULL AND expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute([$refreshJti]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * List a profile+tenant's active (non-revoked, non-expired) sessions, newest
     * first, flagging the caller's own current session (matched by access jti).
     *
     * @return list<array<string, mixed>>
     */
    public function listForProfile(int $profileId, int $tenantId, ?string $currentAccessJti): array
    {
        $stmt = $this->db->prepare("
            SELECT s.id, s.access_jti, s.user_agent, s.ip_address, s.created_at, s.last_seen_at, s.expires_at
            FROM   sessions s
            WHERE  s.profile_id = ?
              AND  s.tenant_id = ?
              AND  s.revoked_at IS NULL
              AND  s.expires_at > NOW()
            ORDER BY s.last_seen_at DESC
        ");
        $stmt->execute([$profileId, $tenantId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(static function (array $row) use ($currentAccessJti): array {
            $accessJti = $row['access_jti'] !== null ? (string) $row['access_jti'] : null;
            $userAgent = $row['user_agent'] !== null ? (string) $row['user_agent'] : null;
            return [
                'id'           => (int) $row['id'],
                'user_agent'   => $userAgent,
                // Friendly label parsed from the UA ("Chrome on Windows") for the
                // sessions/devices UI, so it need not render the raw string (WC-b3330495).
                'device'       => \Whity\Core\Http\DeviceLabel::fromUserAgent($userAgent),
                'ip_address'   => $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
                'created_at'   => (string) $row['created_at'],
                'last_seen_at' => (string) $row['last_seen_at'],
                'expires_at'   => (string) $row['expires_at'],
                'current'      => $currentAccessJti !== null && $accessJti === $currentAccessJti,
            ];
        }, $rows));
    }

    /**
     * Revoke one session by id (ownership-gated): blacklist its current jtis and
     * stamp revoked_at. Returns false when the id does not exist, is already
     * revoked, or belongs to a different profile/tenant.
     */
    public function revokeById(int $id, int $profileId, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT access_jti, refresh_jti, expires_at FROM sessions
             WHERE id = ? AND profile_id = ? AND tenant_id = ? AND revoked_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$id, $profileId, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }

        $this->blacklistAndStamp(
            $id,
            $row['access_jti'] !== null ? (string) $row['access_jti'] : null,
            (string) $row['refresh_jti'],
            (string) $row['expires_at']
        );

        return true;
    }

    /**
     * Revoke every active session for a profile+tenant EXCEPT the caller's own,
     * identified by its current access jti ($keepAccessJti). Blacklists each
     * revoked session's jtis. Returns the number of sessions revoked. Used by the
     * "revoke all other sessions" API and, for list accuracy, by logout-others.
     */
    public function revokeAllExcept(int $profileId, int $tenantId, string $keepAccessJti): int
    {
        $stmt = $this->db->prepare(
            'SELECT id, access_jti, refresh_jti, expires_at FROM sessions
             WHERE profile_id = ? AND tenant_id = ? AND revoked_at IS NULL
               AND (access_jti IS NULL OR access_jti != ?)'
        );
        $stmt->execute([$profileId, $tenantId, $keepAccessJti]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $this->blacklistAndStamp(
                (int) $row['id'],
                $row['access_jti'] !== null ? (string) $row['access_jti'] : null,
                (string) $row['refresh_jti'],
                (string) $row['expires_at']
            );
        }

        return count($rows);
    }

    /**
     * Revoke every active session a profile holds, in every workspace. Used
     * when the account is deactivated (#2238): deactivation is a global switch
     * on the profile, so its sessions end everywhere. Returns the number revoked.
     */
    public function revokeAllForProfile(int $profileId): int
    {
        // @tenant-guard-ignore: deactivation is a GLOBAL profile switch (ADR 0005 §1); every session of this one profile ends, whatever workspace it is in.
        $stmt = $this->db->prepare(
            'SELECT id, access_jti, refresh_jti, expires_at FROM sessions
             WHERE profile_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$profileId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $this->blacklistAndStamp(
                (int) $row['id'],
                $row['access_jti'] !== null ? (string) $row['access_jti'] : null,
                (string) $row['refresh_jti'],
                (string) $row['expires_at']
            );
        }

        return count($rows);
    }

    /**
     * Revoke the session whose CURRENT access or refresh jti is $jti: both of
     * its jtis are listed in revoked_tokens and the row is stamped. Used on
     * sign-out (#2264), so signing out with only the access token also ends the
     * paired 7-day refresh token, and the session leaves the list. Returns false
     * when no live row matched.
     */
    public function revokeByJti(string $jti): bool
    {
        // @tenant-guard-ignore: matched by a jti the caller presented on sign-out; jtis are 128-bit random handles unique platform-wide.
        $stmt = $this->db->prepare(
            'SELECT id, access_jti, refresh_jti, expires_at FROM sessions
              WHERE (access_jti = :jti OR refresh_jti = :jti) AND revoked_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([':jti' => $jti]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }

        $this->blacklistAndStamp(
            (int) $row['id'],
            $row['access_jti'] !== null ? (string) $row['access_jti'] : null,
            (string) $row['refresh_jti'],
            (string) $row['expires_at']
        );

        return true;
    }

    /**
     * Blacklist a session's current jti(s) into the shared revoked_tokens table
     * (so validateAccessToken rejects them immediately) and stamp revoked_at.
     */
    private function blacklistAndStamp(int $id, ?string $accessJti, string $refreshJti, string $expiresAt): void
    {
        $ins = $this->db->prepare(
            'INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?) ON CONFLICT (jti) DO NOTHING'
        );
        if ($accessJti !== null && $accessJti !== '') {
            $ins->execute([$accessJti, $expiresAt]);
        }
        $ins->execute([$refreshJti, $expiresAt]);

        // @tenant-guard-ignore: $id was resolved from a profile_id+tenant_id-scoped SELECT by the caller (revokeById / revokeAllExcept), so this by-id update is already tenant-bounded.
        $this->db->prepare('UPDATE sessions SET revoked_at = NOW() WHERE id = ?')->execute([$id]);
    }
}
