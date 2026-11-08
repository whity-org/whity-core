<?php

declare(strict_types=1);

namespace Whity\Sdk\Notification;

/**
 * Optional capability: the notification types a plugin may send (WHIT-1030).
 * A plugin that declares none cannot send any.
 *
 * Declare BARE keys. The host stores each under the plugin's own namespace, so
 * `order.shipped` from `acme` is the type `acme:order.shipped`. Two things
 * follow: two plugins never share a type, and a plugin cannot pose as a core
 * notice. A type such as `security.alert` or `billing.overdue`, which people
 * cannot opt out of, is out of reach: the namespaced key never begins that way,
 * so a plugin's notice is always one a person can turn off.
 *
 *     public function getNotificationTypes(): array
 *     {
 *         return [
 *             'order.shipped' => ['label' => ['en' => 'Order shipped', 'ar' => 'تم شحن الطلب']],
 *         ];
 *     }
 */
interface PluginNotificationsInterface
{
    /**
     * Bare key => ['label' => ['en' => string, 'ar' => string]]. A key is
     * lowercase, starts with a letter and continues with letters, digits,
     * underscores or dots. A malformed declaration is refused whole.
     *
     * @return array<string, array{label: array{en: string, ar: string}}>
     */
    public function getNotificationTypes(): array;
}
