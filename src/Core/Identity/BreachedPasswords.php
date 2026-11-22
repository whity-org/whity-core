<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * Refuses a new password that has been seen in a data breach or is too common
 * to protect anything (READINESS CE-7, ASVS 2.1.7, NIST 800-63B 5.1.1.2).
 *
 * Two checks, cheapest first:
 *  1. Offline: a short built-in list of the commonest passwords that pass the
 *     12-character minimum, and any password made of fewer than four different
 *     characters. Always on, so a self-hosted install with no internet still
 *     refuses `password1234`.
 *  2. Online: the Have I Been Pwned range API with k-anonymity. Only the first
 *     five hex characters of the password's SHA-1 leave the server; the answer
 *     lists every suffix with that prefix (padded, so its size says nothing),
 *     and the match is made here. Nothing about the password is stored.
 *
 * The online check fails open: when the service can't be reached the password
 * is judged by the offline check alone, so an outage never stops anyone from
 * setting a password. It runs only where a password is set or changed, never
 * at sign-in.
 */
final class BreachedPasswords
{
    public const RANGE_URL = 'https://api.pwnedpasswords.com/range/';

    /** Common passwords of 12 or more characters, lower-cased. */
    private const COMMON = [
        '000000000000', '111111111111', '123123123123', '1234567890123', '12345678901234',
        '123456789012', '123456789123', '12345678910', '123456qwerty', '1q2w3e4r5t6y',
        '1qaz2wsx3edc', 'abc123456789', 'abcdefghijkl', 'admin1234567', 'administrator',
        'asdfghjkl123', 'baseball1234', 'changeme1234', 'football1234', 'iloveyou1234',
        'letmein12345', 'passw0rd1234', 'password1234', 'password12345', 'password123456',
        'password@123', 'passwordpassword', 'qwerty123456', 'qwertyuiop12', 'qwertyuiop123',
        'qwertyuiopasdf', 'superman1234', 'welcome12345', 'zaq12wsxcde3', 'zxcvbnm12345',
    ];

    /** @var (\Closure(string): ?string)|null fetches a URL's body, null on failure */
    private readonly ?\Closure $fetch;

    /**
     * @param (\Closure(string): ?string)|null $fetch Null: offline check only.
     */
    public function __construct(?\Closure $fetch = null)
    {
        $this->fetch = $fetch;
    }

    /** The online check through PHP's own HTTP stream, with a short timeout. */
    public static function online(float $timeoutSeconds = 3.0): self
    {
        return new self(static function (string $url) use ($timeoutSeconds): ?string {
            $context = stream_context_create(['http' => [
                'method'        => 'GET',
                'timeout'       => $timeoutSeconds,
                'header'        => "Add-Padding: true\r\nUser-Agent: whity-core\r\n",
                'ignore_errors' => false,
            ]]);
            $body = @file_get_contents($url, false, $context);

            return is_string($body) ? $body : null;
        });
    }

    public function isBreached(#[\SensitiveParameter] string $password): bool
    {
        if (in_array(strtolower($password), self::COMMON, true) || count(array_unique(mb_str_split($password))) < 4) {
            return true;
        }
        if ($this->fetch === null) {
            return false;
        }

        $hash = strtoupper(sha1($password));
        try {
            $body = ($this->fetch)(self::RANGE_URL . substr($hash, 0, 5));
        } catch (\Throwable) {
            $body = null;
        }
        if ($body === null) {
            // Unreachable: judged by the built-in list alone (fails open).
            return false;
        }
        $suffix = substr($hash, 5);
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            [$candidate, $count] = array_pad(explode(':', trim($line), 2), 2, '0');
            if ($candidate === $suffix && (int) $count > 0) {
                return true;
            }
        }

        return false;
    }
}
