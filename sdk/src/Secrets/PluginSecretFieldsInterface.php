<?php

declare(strict_types=1);

namespace Whity\Sdk\Secrets;

/**
 * Secrets a person types into the plugin's screens (SDK 1.88, WHIT-1016).
 *
 * Declare the key and who may change it, and put a `secretField` block on a
 * screen. The host draws the field and stores what is typed in this plugin's
 * {@see SecretStore}, for the workspace of the person typing. The browser only
 * ever learns whether a value is set; the value itself is never sent back.
 *
 *     public function getSecretFields(): array
 *     {
 *         return ['api_key' => ['permission' => 'acme:settings:manage']];
 *     }
 *
 * The plugin reads the value with {@see SecretStore::get()}.
 *
 * OPTIONAL. Plugins that do not implement it are untouched.
 */
interface PluginSecretFieldsInterface
{
    /**
     * Keys are `[a-z0-9_.-]{1,128}`. `permission` is required: holding it is
     * what lets a person see whether the secret is set and change it.
     *
     * @return array<string, array{permission: string}> secret key => declaration
     */
    public function getSecretFields(): array;
}
