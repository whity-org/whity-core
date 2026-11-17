<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\TokenValidator;
use Whity\Core\Identity\PersonalPhone;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * A person's own phone number (WHIT-1181).
 *
 *   GET /api/v1/me/phone  {data: {phone: "+962791234567" | null}}
 *   PUT /api/v1/me/phone  {phone: "+962791234567" | null}; null or "" removes it
 *
 * Session-gated and strictly self-scoped: the profile comes from the validated
 * token, never the body. No RBAC permission and no current password, like the
 * display preferences: it is the person's own optional detail, not a
 * credential, so it also works when password sign-in is off (Whity ID only).
 */
final class MyPhoneApiHandler
{
    private const INVALID = 'Enter the number with its country code, such as +962 79 123 4567';

    public function __construct(
        private readonly TokenValidator $tokenValidator,
        private readonly PersonalPhone $phones,
    ) {
    }

    /** @param array<string, mixed> $params */
    public function get(Request $request, array $params = []): Response
    {
        $profileId = $this->profileId($request);
        if ($profileId === null) {
            return Response::error('Unauthenticated', 401);
        }

        return Response::json(['data' => ['phone' => $this->phones->of($profileId)]])
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    /** @param array<string, mixed> $params */
    public function update(Request $request, array $params = []): Response
    {
        $profileId = $this->profileId($request);
        if ($profileId === null) {
            return Response::error('Unauthenticated', 401);
        }

        $body = json_decode($request->getBody(), true);
        $raw = is_array($body) && array_key_exists('phone', $body) ? $body['phone'] : false;
        if ($raw !== null && !is_string($raw)) {
            return Response::error(self::INVALID, 422, ['field' => 'phone']);
        }
        $e164 = null;
        if (is_string($raw) && trim($raw) !== '') {
            $e164 = PersonalPhone::normalize($raw);
            if ($e164 === null) {
                return Response::error(self::INVALID, 422, ['field' => 'phone']);
            }
        }

        $this->phones->setByPerson($profileId, $e164);

        return Response::json(['data' => ['phone' => $e164]])
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    private function profileId(Request $request): ?int
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
