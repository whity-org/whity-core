<?php

declare(strict_types=1);

namespace Whity\Sdk\Contribute;

/**
 * Optional (SDK 1.173, WHIT-1424; design section 41 §9, CA.17–CA.19): screens
 * your plugin shows to a workspace's CUSTOMERS in their customer area at
 * `/@handle`: a tab, a card on their Home, or a page reached from a link.
 *
 * ```php
 * public function getCustomerScreens(): array
 * {
 *     return [[
 *         'id' => 'my-courses',
 *         'label' => ['en' => 'Courses', 'ar' => 'الدورات'],
 *         'placement' => 'tab',            // tab | home | page
 *         'screen' => 'blocks',            // blocks | embed
 *         'requiredPermission' => 'courses_enrolment:read',
 *         'icon' => 'document',
 *         'order' => 10,
 *         'blocks' => [['type' => 'dataList', 'source' => ['apiPath' => '/api/courses/mine'], ...]],
 *         'count' => ['apiPath' => '/api/courses/mine/count'],   // optional, {"count": n}
 *     ]];
 * }
 * ```
 *
 * Each descriptor:
 *  - `id`: kebab-case, unique across plugins (staff screens included).
 *  - `label`: a string or `{en, ar}`.
 *  - `placement`: `tab` (default), `home` (a card on Home, `blocks` only) or
 *    `page` (no tab; open it from a link, a Home card or another screen).
 *  - `screen`: `blocks` or `embed` (`embed: {path, fullScreen?}`). The staff
 *    kinds `crud`, `action` and `custom` are refused.
 *  - `requiredPermission`: one of YOUR permissions that you listed in
 *    {@see PluginCustomerPermissionsInterface::getCustomerPermissions()}. A
 *    customer sees the screen only while they hold it.
 *  - `blocks` / `embed`: as for a staff screen, with one more rule: every
 *    route a screen reads or writes (data sources, form submits, actions, the
 *    embed page, `count`) must be one of your routes declared
 *    `'customer' => true` in {@see \Whity\Sdk\PluginInterface::getRoutes()}.
 *  - `icon`, `order`, `count`: optional.
 *
 * HOW CORE KEEPS IT TO THE CUSTOMER'S OWN RECORDS (CA.18):
 *  1. Deny by default. The customer area calls your routes with a short-lived
 *     customer token bound to the workspace in the address. That token opens
 *     ONLY routes declared `'customer' => true`; any other route answers 404.
 *  2. Core tells you who the customer is: on a customer route,
 *     {@see \Whity\Sdk\Http\CustomerCall::from()} gives their profile, the
 *     workspace and their contact ids in it. Scope every query by them and
 *     never trust an id the request names.
 *  3. Check it in your own tests with
 *     {@see \Whity\Sdk\Testing\CustomerIsolationTestCase}.
 *
 * A screen that breaks a rule is dropped with a logged reason; the rest of the
 * plugin keeps loading. A host older than 1.173 ignores this interface and the
 * `customer` route key.
 */
interface PluginCustomerScreensInterface
{
    /**
     * The screens shown to the workspace's customers.
     *
     * @return list<array<string, mixed>>
     */
    public function getCustomerScreens(): array;
}
