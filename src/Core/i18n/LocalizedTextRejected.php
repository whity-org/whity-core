<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

/**
 * A caller sent a name map that cannot be stored. The message is written for
 * the caller and goes back as a 422.
 */
final class LocalizedTextRejected extends \InvalidArgumentException
{
}
