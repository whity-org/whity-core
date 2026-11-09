<?php

declare(strict_types=1);

/**
 * bcrypt at the lowest cost, for the test run only.
 *
 * The default cost takes about 200 ms per hash, and login tests pay that again
 * to verify it. Unqualified function calls resolve in the calling namespace
 * first, so declaring `password_hash` in the namespaces that call it replaces
 * the call for the tests and leaves the production code as it is. Nothing here
 * is loaded outside tests/bootstrap.php, so no setting can weaken a real hash.
 *
 * Add a namespace when production code starts hashing passwords in a new one.
 * Forgetting only makes those tests slower.
 */

namespace Whity\Api {
    /** @param array<string, mixed> $options */
    function password_hash(string $password, string|int|null $algo, array $options = []): string
    {
        return \password_hash($password, $algo, $options + ['cost' => 4]);
    }
}

namespace Whity\Auth {
    /** @param array<string, mixed> $options */
    function password_hash(string $password, string|int|null $algo, array $options = []): string
    {
        return \password_hash($password, $algo, $options + ['cost' => 4]);
    }
}

namespace Whity\Core\Document\Demo {
    /** @param array<string, mixed> $options */
    function password_hash(string $password, string|int|null $algo, array $options = []): string
    {
        return \password_hash($password, $algo, $options + ['cost' => 4]);
    }
}

namespace Whity\Core\Identity {
    /** @param array<string, mixed> $options */
    function password_hash(string $password, string|int|null $algo, array $options = []): string
    {
        return \password_hash($password, $algo, $options + ['cost' => 4]);
    }
}

namespace Whity\Database {
    /** @param array<string, mixed> $options */
    function password_hash(string $password, string|int|null $algo, array $options = []): string
    {
        return \password_hash($password, $algo, $options + ['cost' => 4]);
    }
}
