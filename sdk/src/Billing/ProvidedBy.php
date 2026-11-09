<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * Which plugin answers a host-held service slot (SDK 1.86).
 *
 * The HOST stamps the name when the plugin loads, from the name the loader
 * holds. The plugin does not supply it, so a caller can check who it is
 * talking to, e.g. `providerName() === 'WhityPay'` before trusting a licence
 * status.
 */
interface ProvidedBy
{
    /** The providing plugin's name, or null when no plugin provides it. */
    public function providerName(): ?string;
}
