<?php

declare(strict_types=1);

namespace Whity\Sdk\Licensing;

/**
 * Whether this installation holds a licence for a paid plugin from the Whity store (SDK 1.85).
 *
 * A host service: resolve it with `\Whity\app(\Whity\Sdk\Licensing\StoreLicence::class)`.
 *
 * The host answers from the installation's own store connection (Whity ID). It fetches the
 * store's signed licence statement, verifies it against the store's pinned key, caches it, and
 * keeps answering through an offline window when the store cannot be reached. A plugin never
 * sees a token, a key or a statement, only the answer.
 *
 * What a plugin does with `grace` and `unlicensed` is its own decision. Whity Pay, for instance,
 * stops new sales but never takes away what a paying workspace already has.
 */
interface StoreLicence
{
    /**
     * @param string $slug The plugin's slug in the store, e.g. `whity-pay`.
     */
    public function status(string $slug): LicenceState;
}
