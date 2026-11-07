<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * A signature refused because the subject's current version is not the one
 * the signer was shown (WHIT-922). The message is written for the signer.
 */
final class RouteVersionChangedException extends \RuntimeException
{
}
