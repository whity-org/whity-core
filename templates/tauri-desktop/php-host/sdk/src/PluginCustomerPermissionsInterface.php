<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Which of a plugin's own permissions a workspace's CUSTOMERS may hold (SDK 1.104).
 *
 * A person who joins a workspace through customer sign-up gets a `customer`
 * membership. Core caps what a customer can do: a permission whose action
 * changes who can do what, decides on someone's behalf or removes things
 * (`manage`, `upload`, `review`, `delete`, ...) is dropped for a customer, on
 * any resource, core or plugin. That rule exists because core cannot know
 * whether a plugin's `manage` reaches one record or the whole workspace.
 *
 * A plugin that does know answers it here. Listing a permission says: "this
 * permission only ever reaches the CALLER'S OWN records, and this plugin
 * enforces that on every route that checks it." A dental lab plugin whose
 * clinic routes bind the caller's own clinic, and answer 404 for any other
 * clinic's patients, can list `dlaas_patient:manage`. Then:
 *
 *  - Settings > Sign-up can offer a role made of customer-safe core
 *    permissions plus permissions listed here. The workspace admin still
 *    chooses the role.
 *  - A customer membership keeps these permissions instead of losing them.
 *
 * WHAT THE HOST REFUSES (each refused slug is skipped and logged, the rest stay):
 *  - a slug this plugin does not declare in {@see PluginInterface::getPermissions()};
 *  - a core permission, or any permission under a core resource (`users`,
 *    `roles`, `settings`, ...), whatever its action;
 *  - a permission another plugin claimed first, or one whose resource another
 *    active plugin also declares permissions under (a plugin vouches only for
 *    resources it alone owns);
 *  - anything that is not plain `resource:action`.
 *
 * Listing a permission does not grant it to anyone. Roles still come from
 * {@see PluginRolesInterface} or from an admin.
 *
 * When the plugin is disabled or uninstalled its list is forgotten at once, so
 * a customer stops holding those permissions and a sign-up role that relied on
 * them stops being offered and stops letting people join.
 *
 * Do not rename actions to dodge the customer rule (`dlaas_case:respond` for
 * `dlaas_case:review`). Declare them here instead: the rule may grow, and a
 * permission that is customer-grade should say so.
 *
 * A workspace (data-only) plugin cannot declare this: it has no routes of its
 * own to enforce the scoping.
 */
interface PluginCustomerPermissionsInterface
{
    /**
     * The plugin's own permissions that reach only the caller's own records.
     *
     * @return list<string> Permission slugs, each also returned by getPermissions().
     */
    public function getCustomerPermissions(): array;
}
