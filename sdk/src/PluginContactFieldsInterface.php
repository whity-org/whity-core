<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * A plugin's own fields on core's contacts (SDK 1.119, WHIT-1209, #2131).
 *
 * Optional. Each field is a custom field on `contact`, owned by the plugin and
 * named under it: the key `supplier_code` from the plugin `keyhub` becomes
 * `keyhub.supplier_code` (a key already written `keyhub.supplier_code` is
 * kept; one under another plugin's name is refused). Use the full key
 * everywhere: in `fields` on {@see Contacts\Contacts}, in `field[...]`
 * filters, and in documents as `{{contact.keyhub.supplier_code}}`. The
 * prefix is {@see PluginNamespace::slug()} of the plugin's name.
 *
 * WHEN THEY ARE MADE. Custom fields belong to a workspace, so a plugin's
 * fields are made in a workspace when it turns the plugin on, and, where the
 * plugin was already on, the first time the workspace's contact fields are
 * used. Making them never overwrites what the workspace changed (a label, the
 * list column, the order). They don't count toward the workspace's limit of
 * fields. They exist before `PluginWorkspaceLifecycleInterface::onWorkspaceEnabled()`
 * runs, so it can fill them.
 *
 * WHEN THE PLUGIN IS TURNED OFF or removed, its fields are archived: hidden
 * from screens, filters and imports, with every value kept. Turning it on
 * again brings them back with their values. Core never deletes them. People
 * can't archive or restore a plugin's fields themselves.
 *
 * They show on the contact screens, as list columns (`inList`) and in import
 * column matching like any custom field. `type` is one of `text`, `textarea`,
 * `number`, `date`, `select`, `multiselect`, `checkbox` or `profile_ref`;
 * `select` and `multiselect` need `options`. `editPermission`, when set, is
 * the permission needed to change the value, besides `contacts:write`. Each
 * label needs English and Arabic, up to 100 characters each.
 *
 * A declaration that fails is dropped with a logged reason, and the plugin's
 * other fields are kept. A field's key and type never change once it's made.
 *
 * @deprecated 1.161 Use {@see \Whity\Sdk\Contribute\PluginContactFieldsInterface}, the same type under the name it keeps in 2.0 (D6).
 *             This name keeps working through 2.0.x.
 */
interface PluginContactFieldsInterface
{
    /**
     * @return list<array{key: string, label: array{en: string, ar: string}, type: string, required?: bool, inList?: bool, options?: list<string>, editPermission?: string}>
     */
    public function getContactFields(): array;
}
