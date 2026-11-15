<?php

declare(strict_types=1);

namespace Whity\Core\Notification\Push;

use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;

/**
 * The instance's VAPID key pair (RFC 8292), WHIT-1073.
 *
 * Every push message is signed with this pair, and a browser that subscribed
 * under its public key accepts pushes signed by it only. It identifies the
 * INSTANCE to the browsers' push services: no account with Google, Mozilla or
 * Apple is involved, so a self-hosted Whity needs nothing but this.
 *
 * Made on first use: the first request that needs the public key creates the
 * pair, and every later one reads it back. Two processes racing to create it
 * both try to store theirs with {@see GlobalSettingsRepository::setIfAbsent()};
 * one wins and both use the winner's, so no browser is ever subscribed under a
 * key that was thrown away.
 *
 * WHERE IT LIVES. One `app_settings` row, `push.vapid.keys`, holding JSON with
 * the public key (base64url, 65 bytes uncompressed P-256) and the private key
 * as PEM encrypted with the shared key ring ({@see EncryptedSecretStore}). It is
 * NOT a {@see \Whity\Core\Settings\SettingsRegistry} key, so it never leaves the
 * server through the settings API (the arrangement PaymentSecrets and the SMTP
 * password use).
 *
 * CHANGING IT. Deleting the row makes a new pair on next use, and every
 * existing subscription stops working (push services refuse a signature from
 * another key). The sender then drops each one as the push service rejects it,
 * and devices subscribe again when the person next opens the app.
 */
final class VapidKeys
{
    public const SETTING_KEY = 'push.vapid.keys';

    public function __construct(
        private readonly GlobalSettingsRepository $globals,
        private readonly EncryptedSecretStore $secrets,
    ) {
    }

    /** The public key, base64url without padding: what a browser subscribes with. */
    public function publicKey(): string
    {
        return $this->pair()['public'];
    }

    /**
     * The pair, created on first use.
     *
     * @return array{public: string, privatePem: string}
     */
    public function pair(): array
    {
        $stored = $this->read();
        if ($stored !== null) {
            return $stored;
        }

        $fresh = self::generate();
        $this->globals->setIfAbsent(self::SETTING_KEY, (string) json_encode([
            'public'  => $fresh['public'],
            'private' => $this->secrets->encrypt($fresh['privatePem']),
        ]));

        // Read back rather than trust $fresh: another process may have won.
        return $this->read() ?? throw new \RuntimeException('The push key pair could not be stored.');
    }

    /**
     * A new P-256 pair.
     *
     * @return array{public: string, privatePem: string}
     */
    public static function generate(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('Could not make a P-256 key pair: ' . (openssl_error_string() ?: 'openssl failed'));
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('Could not read the P-256 public key.');
        }

        return [
            'public'     => Base64Url::encode(self::uncompressedPoint($details['ec']['x'], $details['ec']['y'])),
            'privatePem' => (string) $pem,
        ];
    }

    /** 0x04 ‖ X ‖ Y, each coordinate left-padded to 32 bytes. */
    public static function uncompressedPoint(string $x, string $y): string
    {
        return "\x04" . str_pad($x, 32, "\0", STR_PAD_LEFT) . str_pad($y, 32, "\0", STR_PAD_LEFT);
    }

    /** @return array{public: string, privatePem: string}|null */
    private function read(): ?array
    {
        $raw = $this->globals->get(self::SETTING_KEY);
        if ($raw === null || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_string($data['public'] ?? null) || !is_string($data['private'] ?? null)) {
            throw new \RuntimeException('The stored push key pair is not readable.');
        }

        return ['public' => $data['public'], 'privatePem' => $this->secrets->decrypt($data['private'])];
    }
}
