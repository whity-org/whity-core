<?php

declare(strict_types=1);

namespace Whity\Sdk\Auth\Jwt;

/**
 * Checks a compact JWS signature against a JSON Web Key Set (SDK 1.106).
 *
 * Signature only: claims (`iss`, `aud`, `exp`, ...) are the caller's, or
 * {@see IssuerTokenVerifier}'s. Needs nothing but PHP's openssl extension.
 *
 * - Asymmetric algorithms only: RS256/384/512 and ES256/384/512. `none` and
 *   every `HS*` are refused, so a token cannot pick a shared-secret algorithm
 *   and a public key is never used as an HMAC secret.
 * - The header must name a `kid`, and the key with that `kid` is used.
 * - The key's own `alg` must equal the token's. A key without `alg` is taken
 *   as RS256 (the default the host used before, with firebase/php-jwt).
 * - The key type must fit the algorithm (RSA for RS*, EC on the matching curve
 *   for ES*), and `use`, when present, must be `sig`. Symmetric (`oct`) keys
 *   in a set are never used.
 */
final class JwsVerifier
{
    public const ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512'];

    private const DIGESTS = [
        'RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512,
        'ES256' => OPENSSL_ALGO_SHA256, 'ES384' => OPENSSL_ALGO_SHA384, 'ES512' => OPENSSL_ALGO_SHA512,
    ];

    /** Curve name, curve OID (DER) and coordinate length in bytes, per ES algorithm. */
    private const CURVES = [
        'ES256' => ['P-256', "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07", 32],
        'ES384' => ['P-384', "\x06\x05\x2b\x81\x04\x00\x22", 48],
        'ES512' => ['P-521', "\x06\x05\x2b\x81\x04\x00\x23", 66],
    ];

    private function __construct()
    {
    }

    /**
     * The decoded header and claims when the signature is valid, else null.
     *
     * @param array<string, mixed> $jwks       A key set (`{"keys": [...]}`).
     * @param list<string>         $algorithms Algorithms accepted (a subset of {@see ALGORITHMS}).
     * @return array{header: array<string, mixed>, claims: array<string, mixed>}|null
     */
    public static function verify(string $token, array $jwks, array $algorithms = self::ALGORITHMS): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $header = self::decodeJson($parts[0]);
        $claims = self::decodeJson($parts[1]);
        $signature = self::base64UrlDecode($parts[2]);
        if ($header === null || $claims === null || $signature === null) {
            return null;
        }

        $alg = $header['alg'] ?? null;
        if (!is_string($alg) || !isset(self::DIGESTS[$alg]) || !in_array($alg, $algorithms, true)) {
            return null;
        }
        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '') {
            return null;
        }

        $jwk = self::findKey($jwks, $kid);
        if ($jwk === null || ($jwk['alg'] ?? 'RS256') !== $alg || ($jwk['use'] ?? 'sig') !== 'sig') {
            return null;
        }

        $key = str_starts_with($alg, 'RS') ? self::rsaKey($jwk) : self::ecKey($jwk, $alg);
        if ($key === null) {
            return null;
        }
        if (isset(self::CURVES[$alg])) {
            $signature = self::joseToDer($signature, self::CURVES[$alg][2]);
            if ($signature === null) {
                return null;
            }
        }

        if (openssl_verify($parts[0] . '.' . $parts[1], $signature, $key, self::DIGESTS[$alg]) !== 1) {
            return null;
        }

        return ['header' => $header, 'claims' => $claims];
    }

    /**
     * The header of a compact JWS, decoded but NOT verified. Use it only to
     * choose what to check (for example which key to fetch), never to accept.
     *
     * @return array<string, mixed>|null
     */
    public static function unverifiedHeader(string $token): ?array
    {
        $parts = explode('.', $token);

        return count($parts) === 3 ? self::decodeJson($parts[0]) : null;
    }

    /**
     * The claims of a compact JWS, decoded but NOT verified. Same caution as
     * {@see unverifiedHeader()}.
     *
     * @return array<string, mixed>|null
     */
    public static function unverifiedClaims(string $token): ?array
    {
        $parts = explode('.', $token);

        return count($parts) === 3 ? self::decodeJson($parts[1]) : null;
    }

    /**
     * @param array<string, mixed> $jwks
     * @return array<string, mixed>|null
     */
    private static function findKey(array $jwks, string $kid): ?array
    {
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys)) {
            return null;
        }
        foreach ($keys as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid && in_array($key['kty'] ?? null, ['RSA', 'EC'], true)) {
                /** @var array<string, mixed> $key */
                return $key;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $jwk */
    private static function rsaKey(array $jwk): ?\OpenSSLAsymmetricKey
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || !is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null)) {
            return null;
        }
        $n = self::base64UrlDecode($jwk['n']);
        $e = self::base64UrlDecode($jwk['e']);
        if ($n === null || $e === null) {
            return null;
        }

        // SubjectPublicKeyInfo { AlgorithmIdentifier { rsaEncryption, NULL }, BIT STRING RSAPublicKey }
        $rsaPublicKey = self::der(0x30, self::derInteger($n) . self::derInteger($e));
        $algorithm = self::der(0x30, self::der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");

        return self::publicKey(self::der(0x30, $algorithm . self::der(0x03, "\x00" . $rsaPublicKey)));
    }

    /** @param array<string, mixed> $jwk */
    private static function ecKey(array $jwk, string $alg): ?\OpenSSLAsymmetricKey
    {
        [$crv, $curveOid, $length] = self::CURVES[$alg];
        if (($jwk['kty'] ?? null) !== 'EC' || ($jwk['crv'] ?? null) !== $crv
            || !is_string($jwk['x'] ?? null) || !is_string($jwk['y'] ?? null)) {
            return null;
        }
        $x = self::base64UrlDecode($jwk['x']);
        $y = self::base64UrlDecode($jwk['y']);
        if ($x === null || $y === null || strlen($x) !== $length || strlen($y) !== $length) {
            return null;
        }

        // SubjectPublicKeyInfo { AlgorithmIdentifier { id-ecPublicKey, curve }, BIT STRING 04||x||y }
        $algorithm = self::der(0x30, self::der(0x06, "\x2a\x86\x48\xce\x3d\x02\x01") . $curveOid);

        return self::publicKey(self::der(0x30, $algorithm . self::der(0x03, "\x00\x04" . $x . $y)));
    }

    private static function publicKey(string $spki): ?\OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);

        return $key === false ? null : $key;
    }

    /** JOSE ECDSA signatures are r||s at a fixed length; openssl wants DER. */
    private static function joseToDer(string $signature, int $length): ?string
    {
        if (strlen($signature) !== 2 * $length) {
            return null;
        }

        return self::der(0x30, self::derInteger(substr($signature, 0, $length)) . self::derInteger(substr($signature, $length)));
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::der(0x02, $bytes);
    }

    private static function der(int $tag, string $value): string
    {
        $length = strlen($value);
        if ($length < 0x80) {
            return chr($tag) . chr($length) . $value;
        }
        $encoded = ltrim(pack('N', $length), "\x00");

        return chr($tag) . chr(0x80 | strlen($encoded)) . $encoded . $value;
    }

    /** @return array<string, mixed>|null */
    private static function decodeJson(string $segment): ?array
    {
        $json = self::base64UrlDecode($segment);
        if ($json === null) {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
