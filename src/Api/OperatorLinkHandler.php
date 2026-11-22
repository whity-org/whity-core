<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Http\JsonBody;
use Whity\Core\Identity\OperatorSignIn;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Store\SharedStoreInterface;

/**
 * POST /api/auth/operator-link — open the one-time sign-in link that
 * `whity-cli auth operator-link <email>` printed (WHIT-1350).
 *
 * The link opens a page that posts the token here, so a chat app previewing
 * the link can't use it up or receive the session. The token is checked,
 * spent, and turned into a session in the system workspace for that operator
 * only. Every opening is audited, refused ones too. The answer sends the page
 * to Settings › Security, where the operator links Whity ID.
 *
 * Works whether or not password sign-in is on: it is the way back in when it
 * is off.
 */
final class OperatorLinkHandler
{
    /** Where the page goes after signing in. */
    public const NEXT = '/settings/security?link=whity_id';

    public function __construct(
        private readonly PDO $pdo,
        private readonly JwtParser $jwt,
        private readonly SharedStoreInterface $store,
        private readonly AuthHandler $auth,
        private readonly AuditLoggerInterface $audit,
    ) {
    }

    public function open(Request $request): Response
    {
        $token = JsonBody::parsed($request)['token'] ?? null;
        if (!is_string($token) || $token === '') {
            return $this->refused('invalid', null);
        }

        $result = OperatorSignIn::consumeLink($this->pdo, $this->jwt, $this->store, $token);
        if (isset($result['refused'])) {
            return $this->refused($result['refused'], $result['profile_id'] ?? null);
        }
        $profileId = $result['profile_id'];

        $session = $this->auth->completeFederatedLogin($profileId, $this->emailOf($profileId), $request, 0, false);
        if ($session->getStatusCode() !== 200) {
            return $this->refused('not_operator', $profileId);
        }

        $this->audit->record(OperatorSignIn::AUDIT_USED, [
            'tenant_id' => 0,
            'actor_user_id' => $profileId,
            'target_type' => 'profile',
            'target_id' => $profileId,
        ]);

        $body = json_decode($session->getBody(), true);
        $data = is_array($body) ? $body : [];
        $data['next'] = self::NEXT;

        return new Response(200, (string) json_encode($data), $session->getHeaders());
    }

    private function refused(string $reason, ?int $profileId): Response
    {
        $this->audit->record(OperatorSignIn::AUDIT_REFUSED, [
            'tenant_id' => 0,
            'target_type' => 'profile',
            'target_id' => $profileId,
            'metadata' => ['reason' => $reason, 'stage' => 'open'],
        ]);

        // One answer for every refusal: the page says the link has expired or
        // was used, and how to get a new one.
        return Response::error(
            'This sign-in link has expired or was already used. Ask for a new one from the server: whity-cli auth operator-link <email>.',
            410,
            ['code' => 'operator_link_' . $reason],
        );
    }

    private function emailOf(int $profileId): string
    {
        $stmt = $this->pdo->prepare('SELECT email FROM profile_emails WHERE profile_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1');
        $stmt->execute([$profileId]);
        $email = $stmt->fetchColumn();

        return is_string($email) ? $email : '';
    }
}
