<?php

declare(strict_types=1);

namespace Whity\Sdk;

use Whity\Sdk\Billing\LicenceSales;

/**
 * A billing plugin that sells store plugin licences (SDK 1.86).
 *
 * The host asks once, when the plugin loads, and puts the answer in a single
 * slot stamped with this plugin's name. A second plugin offering one is
 * refused with a warning, so the store always talks to exactly one seller and
 * knows which. See {@see LicenceSales}.
 */
interface PluginLicenceSalesInterface
{
    public function getLicenceSales(): LicenceSales;
}
