<?php

declare(strict_types=1);

namespace Whity\Core\Sandbox;

/** Something with an effect outside the database was tried inside {@see SideEffectGuard::blocking()} (WHIT-991). */
final class SideEffectBlockedException extends \RuntimeException
{
}
