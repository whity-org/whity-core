<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use Whity\Sdk\Notification\PluginNotificationsInterface;
use Whity\Sdk\PluginNamespace;

/**
 * Reads a plugin's declared notification types (WHIT-1030). A declaration is
 * untrusted data and is refused whole when any part is malformed, like the
 * sibling plugin declarations.
 */
final class PluginNotificationTypes
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_.]*$/';
    private const MAX_KEY_LENGTH = 100;

    private function __construct()
    {
    }

    /**
     * Namespaced type => its label.
     *
     * @return array<string, array{en: string, ar: string}>
     * @throws \InvalidArgumentException on a malformed declaration
     */
    public static function of(PluginNotificationsInterface $plugin, string $slug): array
    {
        return array_map(static fn (array $type): array => $type['label'], self::declared($plugin, $slug));
    }

    /**
     * Namespaced type => its label and whether email starts on for a person
     * who never chose for it (#2209). `emailDefault` is optional and false
     * when left out, so a plugin that never opted in sends no email.
     *
     * @return array<string, array{label: array{en: string, ar: string}, emailDefault: bool}>
     * @throws \InvalidArgumentException on a malformed declaration
     */
    public static function declared(PluginNotificationsInterface $plugin, string $slug): array
    {
        $types = [];
        foreach ($plugin->getNotificationTypes() as $key => $declaration) {
            $key = (string) $key;
            $type = $slug . PluginNamespace::SEPARATOR . $key;
            if (preg_match(self::KEY_PATTERN, $key) !== 1 || strlen($type) > self::MAX_KEY_LENGTH) {
                throw new \InvalidArgumentException("Bad notification type key: {$key}");
            }
            // Untrusted: the array shape on the interface is PHPDoc only.
            /** @var mixed $raw */
            $raw = $declaration;
            $label = is_array($raw) && is_array($raw['label'] ?? null) ? $raw['label'] : [];
            $english = $label['en'] ?? null;
            $arabic = $label['ar'] ?? null;
            if (!self::isText($english) || !self::isText($arabic)) {
                throw new \InvalidArgumentException("Notification type {$key} needs a label in English and Arabic.");
            }
            $emailDefault = is_array($raw) ? ($raw['emailDefault'] ?? false) : false;
            if (!is_bool($emailDefault)) {
                throw new \InvalidArgumentException("Notification type {$key}: emailDefault must be true or false.");
            }
            $types[$type] = ['label' => ['en' => $english, 'ar' => $arabic], 'emailDefault' => $emailDefault];
        }

        return $types;
    }

    /** @phpstan-assert-if-true non-empty-string $value */
    private static function isText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
