<?php

namespace Whity\Core;

/**
 * Enforces the site-wide password strength policy.
 *
 * A single source of truth for the minimum password length so every flow
 * (admin create, admin edit, self-service profile, invite-accept, reset)
 * rejects the same too-short input rather than each hard-coding its own
 * threshold.  The message is intentionally generic and safe to surface to
 * end-users; never include the raw input in any error returned from here.
 */
final class PasswordPolicy
{
    /**
     * 12, as ASVS 2.1.1 asks (READINESS CE-5). Checked when a password is set
     * or changed; a stored hash is never re-checked, so nobody is locked out.
     */
    public const MIN_LENGTH = 12;

    /**
     * Maximum length, in bytes. bcrypt (PASSWORD_BCRYPT, used by every hashing
     * call in the app) silently truncates its input at 72 bytes, so any two
     * passwords sharing the first 72 bytes hash to the same value — a caller
     * could believe a long passphrase's tail adds security when it is ignored.
     * Rejecting longer input here makes that truncation impossible to hit
     * unknowingly (and bounds the plaintext we handle) rather than accepting a
     * password whose tail is silently discarded.
     */
    public const MAX_LENGTH = 72;

    /**
     * Assert that $password satisfies the policy (min and max length).
     *
     * @param string $password The plaintext password to check (never logged).
     * @throws \InvalidArgumentException when the password is too short or too long.
     */
    /** Set at boot (READINESS CE-7); null in tests and tools that set none. */
    private static ?Identity\BreachedPasswords $breached = null;

    public const BREACHED_MESSAGE = 'This password has been seen in a data breach or is too common. Choose another.';

    public static function setBreachedPasswords(?Identity\BreachedPasswords $breached): void
    {
        self::$breached = $breached;
    }

    /**
     * Refuse a NEW password that is known to be breached or common. Call it
     * where a password is set or changed, never at sign-in.
     *
     * @throws \InvalidArgumentException with {@see self::BREACHED_MESSAGE}
     */
    public static function refuseBreached(#[\SensitiveParameter] string $password): void
    {
        if (self::$breached?->isBreached($password) === true) {
            throw new \InvalidArgumentException(self::BREACHED_MESSAGE);
        }
    }

    public static function validate(#[\SensitiveParameter] string $password): void
    {
        if (strlen($password) < self::MIN_LENGTH) {
            throw new \InvalidArgumentException(
                'Password must be at least ' . self::MIN_LENGTH . ' characters'
            );
        }
        if (strlen($password) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(
                'Password must be at most ' . self::MAX_LENGTH . ' characters'
            );
        }
        self::refuseBreached($password);
    }
}
