<?php

declare(strict_types=1);

namespace Whity\Core\Billing;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Container\HostWiredService;
use Whity\Sdk\Billing\LicenceCheckout;
use Whity\Sdk\Billing\LicenceCheckoutResult;
use Whity\Sdk\Billing\LicenceSales;
use Whity\Sdk\Billing\LicenceSaleStatus;
use Whity\Sdk\Billing\ProvidedBy;

/**
 * The host's {@see LicenceSales}: one slot, one provider (SDK 1.86, WHIT-1013).
 *
 * Registered in the container before plugins load. The loader offers it the
 * licence seller of each plugin implementing
 * {@see \Whity\Sdk\PluginLicenceSalesInterface}. The FIRST is kept, stamped
 * with that plugin's name, and every later offer is refused. The store resolves
 * this object, never a plugin's, so nothing can stand between the two and no
 * other plugin can answer.
 *
 * A provider that throws is answered as `unavailable` (checkout) or null
 * (status). A provider's failure must not become the store's crash, and its
 * message, which can name a provider endpoint, is logged, never returned.
 */
final class HostLicenceSales implements LicenceSales, ProvidedBy, HostWiredService
{
    private ?string $owner = null;
    private ?LicenceSales $provider = null;

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /** @return bool False when another plugin already sells licences here. */
    public function offer(string $pluginName, LicenceSales $provider): bool
    {
        if ($provider === $this) {
            return false;
        }
        if ($this->provider !== null && $this->owner !== $pluginName) {
            return false;
        }

        $this->owner = $pluginName;
        $this->provider = $provider;

        return true;
    }

    public function providerName(): ?string
    {
        return $this->owner;
    }

    public function checkout(LicenceCheckout $checkout): LicenceCheckoutResult
    {
        if ($this->provider === null) {
            return LicenceCheckoutResult::refused(LicenceCheckoutResult::NOT_CONFIGURED);
        }

        try {
            return $this->provider->checkout($checkout);
        } catch (\Throwable $e) {
            $this->logger->error('The licence seller failed to start a checkout', [
                'plugin' => $this->owner,
                'error' => $e::class,
            ]);

            return LicenceCheckoutResult::refused(LicenceCheckoutResult::UNAVAILABLE);
        }
    }

    public function status(string $reference): ?LicenceSaleStatus
    {
        if ($this->provider === null || $reference === '') {
            return null;
        }

        try {
            $status = $this->provider->status($reference);
        } catch (\Throwable $e) {
            $this->logger->error('The licence seller failed to read a licence', [
                'plugin' => $this->owner,
                'error' => $e::class,
            ]);

            return null;
        }

        // An answer about a different licence is no answer.
        return $status !== null && $status->reference === $reference ? $status : null;
    }
}
