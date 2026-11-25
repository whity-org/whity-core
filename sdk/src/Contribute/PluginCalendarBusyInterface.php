<?php

declare(strict_types=1);

namespace Whity\Sdk\Contribute;

/*
 * {@see \Whity\Sdk\PluginCalendarBusyInterface} under the name it keeps in 2.0,
 * where every contribution point lives in `Contribute\` (SDK 1.161, decision
 * D6).
 *
 * `class_alias` makes the two names one type: a plugin may implement either,
 * and `instanceof` answers the same for both. The contract is documented on
 * {@see \Whity\Sdk\PluginCalendarBusyInterface}; the old name keeps working
 * through 2.0.x (docs/wiki/Plugin-SDK-2.0-Plan.md).
 */
\class_alias(\Whity\Sdk\PluginCalendarBusyInterface::class, PluginCalendarBusyInterface::class);

// Never runs: it tells IDEs and static analysis what the line above defines.
if (false) { // @phpstan-ignore if.alwaysFalse
    /** @see \Whity\Sdk\PluginCalendarBusyInterface */
    interface PluginCalendarBusyInterface extends \Whity\Sdk\PluginCalendarBusyInterface
    {
    }
}
