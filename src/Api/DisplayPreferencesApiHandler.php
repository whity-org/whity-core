<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * A person's display preferences (design decisions W.3, A.1, A.3).
 *
 *   GET /api/v1/me/display-preferences  — text size and Show technical details
 *   PUT /api/v1/me/display-preferences  — change either
 *
 * Session-gated and strictly self-scoped: the profile comes from the validated
 * token, never the body. No RBAC permission (self-service, like language).
 */
final class DisplayPreferencesApiHandler
{
    private const TEXT_SIZES = ['standard', 'larger'];

    public function __construct(
        private readonly TokenValidator $tokenValidator,
        private readonly PDO $db,
    ) {}

    /**
     * @param array<string, mixed> $params
     */
    public function get(Request $request, array $params = []): Response
    {
        $profileId = $this->resolveProfileId($request);
        if ($profileId === null) {
            return Response::error('Unauthenticated', 401);
        }

        $preferences = $this->read($profileId);
        if ($preferences === null) {
            return Response::error('Unauthenticated', 401);
        }

        return Response::json(['data' => $preferences], 200);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function update(Request $request, array $params = []): Response
    {
        $profileId = $this->resolveProfileId($request);
        if ($profileId === null) {
            return Response::error('Unauthenticated', 401);
        }

        $body = json_decode($request->getBody(), true);
        if (!is_array($body)) {
            return Response::error('A JSON object is required', 422);
        }

        $textSize = $body['textSize'] ?? null;
        $technical = $body['showTechnicalDetails'] ?? null;
        if ($textSize === null && $technical === null) {
            return Response::error('Nothing to change', 422);
        }
        if ($textSize !== null && !in_array($textSize, self::TEXT_SIZES, true)) {
            return Response::error('textSize must be standard or larger', 422);
        }
        if ($technical !== null && !is_bool($technical)) {
            return Response::error('showTechnicalDetails must be true or false', 422);
        }

        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        if ($textSize !== null) {
            $stmt = $this->db->prepare('UPDATE profiles SET text_size = ? WHERE id = ?');
            $stmt->execute([$textSize, $profileId]);
        }
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        if ($technical !== null) {
            $stmt = $this->db->prepare('UPDATE profiles SET show_technical_details = ? WHERE id = ?');
            $stmt->bindValue(1, $technical, PDO::PARAM_BOOL);
            $stmt->bindValue(2, $profileId, PDO::PARAM_INT);
            $stmt->execute();
        }

        return Response::json(['data' => $this->read($profileId)], 200);
    }

    /**
     * @return array{textSize: string, showTechnicalDetails: bool}|null
     */
    private function read(int $profileId): ?array
    {
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT text_size, show_technical_details FROM profiles WHERE id = ? LIMIT 1');
        $stmt->execute([$profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'textSize'             => in_array($row['text_size'], self::TEXT_SIZES, true) ? (string) $row['text_size'] : 'standard',
            'showTechnicalDetails' => filter_var($row['show_technical_details'], FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function resolveProfileId(Request $request): ?int
    {
        $claims = $this->tokenValidator->validateAccessToken();
        if ($claims === null) {
            $header = $request->getHeader('Authorization') ?? '';
            if (stripos($header, 'Bearer ') === 0) {
                $token = trim(substr($header, 7));
                if ($token !== '') {
                    $claims = $this->tokenValidator->validateAccessTokenFromBearer($token);
                }
            }
        }
        $profileId = $claims['profile_id'] ?? null;

        return is_int($profileId) && $profileId > 0 ? $profileId : null;
    }
}
