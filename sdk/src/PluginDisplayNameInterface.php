<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * The name people see for a plugin, in English and Arabic (SDK 1.117,
 * WHIT-1198).
 *
 * Optional. `getName()` stays the plugin's id: routes, settings, entitlements
 * and the store use it, and it never changes. This is only what a person
 * reads, on Settings › Plugins and in a workspace template's offer: "Whity for
 * Salons" rather than "Salons". Without it, people see the id.
 *
 * The product's name is always "Whity" in Latin letters, in Arabic too:
 * `['en' => 'Whity for Salons', 'ar' => 'Whity للصالونات']`. Each language is
 * required, up to 60 characters; a declaration that fails is ignored with a
 * logged reason and people see the id.
 *
 * @deprecated 1.161 Use {@see \Whity\Sdk\Contribute\PluginDisplayNameInterface}, the same type under the name it keeps in 2.0 (D6).
 *             This name keeps working through 2.0.x.
 */
interface PluginDisplayNameInterface
{
    /** @return array{en: string, ar: string} */
    public function getDisplayName(): array;
}
