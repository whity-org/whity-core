<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Security;

use PHPUnit\Framework\TestCase;
use Whity\Core\Security\SecretLocations;

/**
 * No secret is stored somewhere `whity-cli secrets reencrypt` doesn't know
 * about (merge map plumbing, WHIT-1047). A new `*_encrypted` setting key or
 * column named in src/ must be added to {@see SecretLocations}.
 */
final class SecretLocationsTest extends TestCase
{
    /** Named in src/ but not a place of their own: a field of a pattern above, or the TOTP path. */
    private const NOT_A_PLACE = [
        'credentials_encrypted', // the field inside notifications.%.credentials_encrypted
    ];

    public function testEveryEncryptedSettingOrColumnInSrcIsListed(): void
    {
        $listed = [];
        foreach (SecretLocations::all() as $ref) {
            $listed[] = $ref->key;
            $listed[] = $ref->column;
        }

        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 4) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!str_ends_with((string) $file, '.php')) {
                continue;
            }
            preg_match_all("/'([a-z0-9_.]*_encrypted)'/", (string) file_get_contents((string) $file), $m);
            foreach ($m[1] as $name) {
                $found[$name] = true;
            }
        }

        $missing = array_values(array_diff(array_keys($found), $listed, self::NOT_A_PLACE));
        sort($missing);
        self::assertSame([], $missing, 'Add these to SecretLocations so a key rotation reaches them.');
    }
}
