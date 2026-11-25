<?php

declare(strict_types=1);

namespace Whity\Sdk\Contribute;

/**
 * Optional (SDK 1.167, WHIT-1401): the hardware your plugin uses when it runs
 * inside Whity Desktop, on the person's own computer.
 *
 * ```php
 * public function getDesktopCapabilities(): array
 * {
 *     return ['printer.list', 'printer.print'];
 * }
 * ```
 *
 * A capability is `<driver>.<operation>`, lower case: `printer.list`,
 * `printer.print`; later `scanner.scan`, `serial.open`, `card-reader.read`.
 * The desktop app gives the plugin a handle bound to exactly this list and
 * refuses a call outside it. What the plugin may then do with each capability
 * is the person's setting on that device, Off, Ask first or Allowed; printing
 * starts Allowed, everything else Off. Under Ask first the call becomes a
 * proposal that runs when the person approves it, and the plugin is told it
 * is waiting (whity-desktop, ADR 0001, decision 3a).
 *
 * On the server this declaration does nothing: there is no hardware. A plugin
 * that does not implement this runs on the desktop with no hardware at all.
 * A name that is not `<driver>.<operation>` is dropped with a logged warning.
 */
interface PluginDesktopCapabilitiesInterface
{
    /**
     * @return list<string> Capability names, `<driver>.<operation>`.
     */
    public function getDesktopCapabilities(): array;
}
