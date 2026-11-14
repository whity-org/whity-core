<?php

declare(strict_types=1);

namespace Tests\Sdk\Auth\Jwt;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Auth\Jwt\IssuerTokenVerifier;
use Whity\Sdk\Auth\Jwt\JwksCache;
use Whity\Sdk\Auth\Jwt\JwsVerifier;

/**
 * The plugin SDK's Whity ID token verifier (WHIT-1026): signature by key set,
 * discovery, key rotation, and the claim checks.
 */
final class IssuerTokenVerifierTest extends TestCase
{
    private const ISSUER = 'https://id.example.test';
    private const JWKS_URI = 'https://id.example.test/jwks';
    private const AUDIENCE = 'https://app.example.test/mcp';
    private const NOW = 1_800_000_000;

    private static \OpenSSLAsymmetricKey $rsa;
    private static \OpenSSLAsymmetricKey $ec;

    /** @var list<array<string, mixed>> */
    private array $keys = [];

    /** @var list<string> */
    private array $fetched = [];

    public static function setUpBeforeClass(): void
    {
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $ec = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($rsa, 'RSA key generation');
        self::assertNotFalse($ec, 'EC key generation');
        self::$rsa = $rsa;
        self::$ec = $ec;
    }

    protected function setUp(): void
    {
        $this->keys = [self::rsaJwk(self::$rsa, 'rsa-1'), self::ecJwk(self::$ec, 'ec-1')];
        $this->fetched = [];
    }

    private function verifier(?string $jwksUri = self::JWKS_URI, int $minRefresh = 0): IssuerTokenVerifier
    {
        $fetch = function (string $url): ?array {
            $this->fetched[] = $url;
            return match ($url) {
                self::JWKS_URI => ['keys' => $this->keys],
                'https://id.example.test/.well-known/oauth-authorization-server' => ['issuer' => self::ISSUER, 'jwks_uri' => self::JWKS_URI],
                default => null,
            };
        };
        $clock = static fn (): int => self::NOW;
        $cache = new JwksCache(static fn (string $uri): array => $fetch($uri) ?? [], 3600, $minRefresh, $clock);

        return new IssuerTokenVerifier(self::ISSUER, $cache, $fetch, $jwksUri, clock: $clock);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function claims(array $overrides = []): array
    {
        return array_merge([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'person-1',
            'iat' => self::NOW - 10,
            'exp' => self::NOW + 300,
        ], $overrides);
    }

    public function testValidRs256TokenReturnsItsClaims(): void
    {
        $token = self::sign(self::claims(), 'RS256', 'rsa-1', self::$rsa);

        $claims = $this->verifier()->verify($token, self::AUDIENCE);

        self::assertNotNull($claims);
        self::assertSame('person-1', $claims['sub']);
    }

    public function testValidEs256TokenIsAccepted(): void
    {
        $this->keys[1]['alg'] = 'ES256';
        $token = self::sign(self::claims(), 'ES256', 'ec-1', self::$ec);

        self::assertNotNull($this->verifier()->verify($token, self::AUDIENCE));
    }

    public function testEcKeyWithoutAlgIsNotUsedForEs256(): void
    {
        unset($this->keys[1]['alg']);
        $token = self::sign(self::claims(), 'ES256', 'ec-1', self::$ec);

        self::assertNull($this->verifier()->verify($token, self::AUDIENCE));
    }

    public function testAudienceListContainingUsIsAccepted(): void
    {
        $token = self::sign(self::claims(['aud' => ['other', self::AUDIENCE]]), 'RS256', 'rsa-1', self::$rsa);

        self::assertNotNull($this->verifier()->verify($token, self::AUDIENCE));
    }

    public function testAudienceClosureDecides(): void
    {
        $token = self::sign(self::claims(['aud' => 'https://app.example.test/other']), 'RS256', 'rsa-1', self::$rsa);

        self::assertNotNull($this->verifier()->verify($token, static fn (array $auds): bool => in_array('https://app.example.test/other', $auds, true)));
        self::assertNull($this->verifier()->verify($token, static fn (array $auds): bool => false));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badClaims(): iterable
    {
        yield 'wrong issuer' => [['iss' => 'https://evil.example.test']];
        yield 'wrong audience' => [['aud' => 'https://other.example.test']];
        yield 'expired' => [['exp' => self::NOW - 31]];
        yield 'no exp' => [['exp' => null]];
        yield 'not yet valid' => [['nbf' => self::NOW + 31]];
        yield 'issued in the future' => [['iat' => self::NOW + 31]];
        yield 'exp not a number' => [['exp' => (string) (self::NOW + 300)]];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('badClaims')]
    public function testBadClaimsAreRefused(array $override): void
    {
        $claims = self::claims($override);
        $claims = array_filter($claims, static fn ($v): bool => $v !== null);
        $token = self::sign($claims, 'RS256', 'rsa-1', self::$rsa);

        self::assertNull($this->verifier()->verify($token, self::AUDIENCE));
    }

    public function testLeewayAcceptsSmallClockSkew(): void
    {
        $token = self::sign(self::claims(['exp' => self::NOW - 20, 'nbf' => self::NOW + 20]), 'RS256', 'rsa-1', self::$rsa);

        self::assertNotNull($this->verifier()->verify($token, self::AUDIENCE));
    }

    public function testTamperedPayloadIsRefused(): void
    {
        $token = self::sign(self::claims(), 'RS256', 'rsa-1', self::$rsa);
        [$h, , $s] = explode('.', $token);
        $forged = $h . '.' . self::b64(json_encode(self::claims(['sub' => 'someone-else']), JSON_THROW_ON_ERROR)) . '.' . $s;

        self::assertNull($this->verifier()->verify($forged, self::AUDIENCE));
    }

    public function testSymmetricAlgorithmsAreRefusedEvenWithAMatchingOctKey(): void
    {
        $secret = 'shared-secret';
        $this->keys[] = ['kty' => 'oct', 'kid' => 'hs', 'alg' => 'HS256', 'k' => self::b64($secret)];
        $header = self::b64((string) json_encode(['alg' => 'HS256', 'kid' => 'hs', 'typ' => 'JWT']));
        $body = self::b64((string) json_encode(self::claims()));
        $token = $header . '.' . $body . '.' . self::b64(hash_hmac('sha256', $header . '.' . $body, $secret, true));

        self::assertNull($this->verifier()->verify($token, self::AUDIENCE));
    }

    public function testNoneAlgorithmIsRefused(): void
    {
        $header = self::b64((string) json_encode(['alg' => 'none', 'kid' => 'rsa-1']));
        $token = $header . '.' . self::b64((string) json_encode(self::claims())) . '.' . self::b64('x');

        self::assertNull($this->verifier()->verify($token, self::AUDIENCE));
    }

    public function testTokenAlgMustEqualTheKeyAlg(): void
    {
        $this->keys[0]['alg'] = 'RS512';
        $token = self::sign(self::claims(), 'RS256', 'rsa-1', self::$rsa);

        self::assertNull($this->verifier()->verify($token, self::AUDIENCE));
    }

    public function testTokenWithoutKidIsRefused(): void
    {
        $token = self::sign(self::claims(), 'RS256', null, self::$rsa);

        self::assertNull($this->verifier()->verify($token, self::AUDIENCE));
    }

    public function testEncryptionKeyIsNotUsedToVerify(): void
    {
        $this->keys[0]['use'] = 'enc';
        $token = self::sign(self::claims(), 'RS256', 'rsa-1', self::$rsa);

        self::assertNull($this->verifier()->verify($token, self::AUDIENCE));
    }

    public function testTypeIsCheckedWhenAskedFor(): void
    {
        $at = self::sign(self::claims(), 'RS256', 'rsa-1', self::$rsa, 'at+jwt');
        $plain = self::sign(self::claims(), 'RS256', 'rsa-1', self::$rsa, 'JWT');

        self::assertNotNull($this->verifier()->verify($at, self::AUDIENCE, 'at+jwt'));
        self::assertNotNull($this->verifier()->verify($at, self::AUDIENCE, 'application/at+jwt'));
        self::assertNull($this->verifier()->verify($plain, self::AUDIENCE, 'at+jwt'));
    }

    public function testForeignIssuerCostsNoFetch(): void
    {
        $token = self::sign(self::claims(['iss' => 'https://evil.example.test']), 'RS256', 'rsa-1', self::$rsa);

        self::assertNull($this->verifier()->verify($token, self::AUDIENCE));
        self::assertSame([], $this->fetched);
    }

    public function testKeySetIsDiscoveredFromIssuerMetadata(): void
    {
        $token = self::sign(self::claims(), 'RS256', 'rsa-1', self::$rsa);

        self::assertNotNull($this->verifier(null)->verify($token, self::AUDIENCE));
        self::assertSame('https://id.example.test/.well-known/oauth-authorization-server', $this->fetched[0]);
    }

    public function testRotatedKeyIsFetchedOnce(): void
    {
        $verifier = $this->verifier();
        self::assertNotNull($verifier->verify(self::sign(self::claims(), 'RS256', 'rsa-1', self::$rsa), self::AUDIENCE));

        $this->keys[] = self::rsaJwk(self::$rsa, 'rsa-2');
        self::assertNotNull($verifier->verify(self::sign(self::claims(), 'RS256', 'rsa-2', self::$rsa), self::AUDIENCE));
        self::assertSame([self::JWKS_URI, self::JWKS_URI], $this->fetched);
    }

    public function testUnknownKidDoesNotRefetchWithinTheMinimumInterval(): void
    {
        $verifier = $this->verifier(self::JWKS_URI, 60);
        self::assertNotNull($verifier->verify(self::sign(self::claims(), 'RS256', 'rsa-1', self::$rsa), self::AUDIENCE));

        self::assertNull($verifier->verify(self::sign(self::claims(), 'RS256', 'made-up', self::$rsa), self::AUDIENCE));
        self::assertNull($verifier->verify(self::sign(self::claims(), 'RS256', 'made-up-2', self::$rsa), self::AUDIENCE));
        self::assertSame([self::JWKS_URI], $this->fetched);
    }

    public function testMalformedTokensAreRefused(): void
    {
        foreach (['', 'a.b', 'a.b.c', 'not a jwt', '...'] as $token) {
            self::assertNull($this->verifier()->verify($token, self::AUDIENCE), $token);
            self::assertNull(JwsVerifier::verify($token, ['keys' => $this->keys]), $token);
        }
    }

    /** @param array<string, mixed> $claims */
    private static function sign(array $claims, string $alg, ?string $kid, \OpenSSLAsymmetricKey $key, ?string $typ = null): string
    {
        $header = ['alg' => $alg];
        if ($kid !== null) {
            $header['kid'] = $kid;
        }
        if ($typ !== null) {
            $header['typ'] = $typ;
        }
        $input = self::b64((string) json_encode($header)) . '.' . self::b64((string) json_encode($claims));
        $digest = ['256' => OPENSSL_ALGO_SHA256, '384' => OPENSSL_ALGO_SHA384, '512' => OPENSSL_ALGO_SHA512][substr($alg, 2)];
        openssl_sign($input, $signature, $key, $digest);
        if (str_starts_with($alg, 'ES')) {
            $signature = self::derToJose((string) $signature, 32);
        }

        return $input . '.' . self::b64((string) $signature);
    }

    /** @return array<string, mixed> */
    private static function rsaJwk(\OpenSSLAsymmetricKey $key, string $kid): array
    {
        $d = openssl_pkey_get_details($key);
        self::assertIsArray($d);

        return ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'n' => self::b64($d['rsa']['n']), 'e' => self::b64($d['rsa']['e'])];
    }

    /** @return array<string, mixed> */
    private static function ecJwk(\OpenSSLAsymmetricKey $key, string $kid): array
    {
        $d = openssl_pkey_get_details($key);
        self::assertIsArray($d);
        $pad = static fn (string $v): string => str_pad($v, 32, "\x00", STR_PAD_LEFT);

        return ['kty' => 'EC', 'kid' => $kid, 'crv' => 'P-256', 'alg' => 'ES256', 'x' => self::b64($pad($d['ec']['x'])), 'y' => self::b64($pad($d['ec']['y']))];
    }

    private static function derToJose(string $der, int $length): string
    {
        $offset = 2;
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $parts[] = str_pad(ltrim(substr($der, $offset + 2, $len), "\x00"), $length, "\x00", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $parts[0] . $parts[1];
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
