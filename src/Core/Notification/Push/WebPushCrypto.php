<?php

declare(strict_types=1);

namespace Whity\Core\Notification\Push;

/**
 * Message encryption for Web Push (RFC 8291, `aes128gcm` from RFC 8188), on
 * ext-openssl alone (WHIT-1074).
 *
 * Every message is encrypted to the one browser it is for: a fresh P-256 key
 * pair per message, ECDH with the browser's `p256dh` key, HKDF mixed with the
 * browser's `auth` secret, then AES-128-GCM. The push service in between sees
 * only ciphertext and the endpoint.
 *
 * One record only: a push message is at most 4096 bytes on the wire, so the
 * whole payload fits in the single (last) record, padded to a fixed size so
 * the length doesn't hint at what's inside.
 */
final class WebPushCrypto
{
    /** The record size written in the header; one record holds the whole message. */
    public const RECORD_SIZE = 4096;

    /** Push services accept at least 4096 bytes of body; the header takes 86. */
    public const MAX_PLAINTEXT = 3993;

    /** SubjectPublicKeyInfo prefix for an uncompressed P-256 point (id-ecPublicKey, prime256v1). */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * The encrypted body to POST to the endpoint.
     *
     * @param string $uaPublic  The browser's p256dh, raw 65 bytes.
     * @param string $authSecret The browser's auth, raw 16 bytes.
     * @param int    $padTo     Pad the plaintext to at least this many bytes.
     */
    public static function encrypt(string $plaintext, string $uaPublic, string $authSecret, int $padTo = 0, ?string $salt = null, ?\OpenSSLAsymmetricKey $ephemeral = null): string
    {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) !== 16) {
            throw new \InvalidArgumentException('Not a browser push key.');
        }
        $padTo = min(max($padTo, strlen($plaintext)), self::MAX_PLAINTEXT);
        if (strlen($plaintext) > self::MAX_PLAINTEXT) {
            throw new \InvalidArgumentException('A push message can hold at most ' . self::MAX_PLAINTEXT . ' bytes.');
        }

        $ephemeral ??= self::newKey();
        $details = openssl_pkey_get_details($ephemeral);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('Could not read the message key.');
        }
        $asPublic = VapidKeys::uncompressedPoint($details['ec']['x'], $details['ec']['y']);
        $salt ??= random_bytes(16);

        $ecdh = self::ecdh($ephemeral, $uaPublic);
        [$cek, $nonce] = self::keys($ecdh, $authSecret, $uaPublic, $asPublic, $salt);

        // The last (and only) record ends with 0x02, then zero padding.
        $record = $plaintext . "\x02" . str_repeat("\0", $padTo - strlen($plaintext));
        $tag = '';
        $cipher = openssl_encrypt($record, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Could not encrypt the push message.');
        }

        return $salt . pack('N', self::RECORD_SIZE) . chr(65) . $asPublic . $cipher . $tag;
    }

    /**
     * Decrypt a body (the browser's side). Used by tests and by nothing in
     * production; kept here so both directions are read side by side.
     *
     * @param \OpenSSLAsymmetricKey $uaPrivate The browser's private key.
     */
    public static function decrypt(string $body, \OpenSSLAsymmetricKey $uaPrivate, string $authSecret): string
    {
        if (strlen($body) < 16 + 4 + 1 + 65 + 16) {
            throw new \InvalidArgumentException('Too short to be a push message.');
        }
        $salt = substr($body, 0, 16);
        $idLength = ord($body[20]);
        $asPublic = substr($body, 21, $idLength);
        $payload = substr($body, 21 + $idLength);

        $details = openssl_pkey_get_details($uaPrivate);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('Not a P-256 key.');
        }
        $uaPublic = VapidKeys::uncompressedPoint($details['ec']['x'], $details['ec']['y']);
        [$cek, $nonce] = self::keys(self::ecdh($uaPrivate, $asPublic), $authSecret, $uaPublic, $asPublic, $salt);

        $plain = openssl_decrypt(substr($payload, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($payload, -16));
        if ($plain === false) {
            throw new \RuntimeException('The push message did not decrypt.');
        }
        $end = strrpos(rtrim($plain, "\0"), "\x02");
        if ($end === false || $end !== strlen(rtrim($plain, "\0")) - 1) {
            throw new \RuntimeException('The push message has no last-record mark.');
        }

        return substr($plain, 0, $end);
    }

    public static function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new \RuntimeException('Could not make a P-256 key: ' . (openssl_error_string() ?: 'openssl failed'));
        }

        return $key;
    }

    /** A raw uncompressed P-256 point as an OpenSSL public key. */
    public static function publicKeyFromPoint(string $point): \OpenSSLAsymmetricKey
    {
        $der = (string) hex2bin(self::P256_SPKI_PREFIX) . $point;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new \InvalidArgumentException('Not a P-256 public key.');
        }

        return $key;
    }

    private static function ecdh(\OpenSSLAsymmetricKey $private, string $peerPoint): string
    {
        $secret = openssl_pkey_derive(self::publicKeyFromPoint($peerPoint), $private);
        if ($secret === false || strlen($secret) !== 32) {
            throw new \RuntimeException('Key agreement failed.');
        }

        return $secret;
    }

    /** @return array{0: string, 1: string} The content key and the nonce. */
    private static function keys(string $ecdh, string $authSecret, string $uaPublic, string $asPublic, string $salt): array
    {
        // RFC 8291 §3.3–3.4: IKM from the ECDH secret and the auth secret.
        $prkKey = hash_hmac('sha256', $ecdh, $authSecret, true);
        $ikm = hash_hmac('sha256', "WebPush: info\0" . $uaPublic . $asPublic . "\x01", $prkKey, true);
        // RFC 8188 §2.2–2.3: the content key and nonce from the salt.
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        return [$cek, $nonce];
    }
}
