<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

/**
 * The shape of the notice types a plugin declares, in `plugin.json`'s
 * `notifications` or {@see \Whity\Sdk\Notification\PluginNotificationsInterface::getNotificationTypes()}
 * (SDK 1.163, decision D4).
 *
 * ```json
 * "notifications": {
 *     "order_shipped": {"label": {"en": "Order shipped", "ar": "Order shipped"}, "emailDefault": true}
 * }
 * ```
 *
 * The host refuses the whole declaration when any type fails these checks, so
 * they are the same: a bare key, a label in English and Arabic, and
 * `emailDefault` true or false when given. Pure and static.
 */
final class NotificationTypesValidator
{
    /** Bare key: lowercase, digits, underscores and dots. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_.]*$/';

    /** The canonical type, `<plugin slug>:<key>`, is at most this long. */
    public const MAX_TYPE_LENGTH = 100;

    private function __construct()
    {
    }

    /** @return list<string> each naming its path; empty when valid */
    public static function errors(mixed $types, ?string $slug = null, string $path = 'notifications'): array
    {
        if ($types === null) {
            return [];
        }
        if (!is_array($types) || ($types !== [] && array_is_list($types))) {
            return ["{$path}: must be an object of type key => declaration"];
        }

        $errors = [];
        foreach ($types as $key => $declaration) {
            $key = (string) $key;
            $at = "{$path}.{$key}";
            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                $errors[] = "{$at}: key must be lowercase letters, digits, underscores and dots, starting with a letter";
                continue;
            }
            if ($slug !== null && strlen($slug . ':' . $key) > self::MAX_TYPE_LENGTH) {
                $errors[] = "{$at}: key is too long (\"{$slug}:{$key}\" is over " . self::MAX_TYPE_LENGTH . ' characters)';
            }
            if (!is_array($declaration)) {
                $errors[] = "{$at}: must be an object with a label";
                continue;
            }
            foreach (array_keys($declaration) as $field) {
                if (!in_array($field, ['label', 'emailDefault'], true)) {
                    $errors[] = "{$at}.{$field}: unknown field; expected label, emailDefault";
                }
            }
            $label = $declaration['label'] ?? null;
            foreach (['en', 'ar'] as $language) {
                $text = is_array($label) ? ($label[$language] ?? null) : null;
                if (!is_string($text) || trim($text) === '') {
                    $errors[] = "{$at}.label.{$language}: required, the name people see in their notification settings";
                }
            }
            if (array_key_exists('emailDefault', $declaration) && !is_bool($declaration['emailDefault'])) {
                $errors[] = "{$at}.emailDefault: must be true or false";
            }
        }

        return $errors;
    }
}
