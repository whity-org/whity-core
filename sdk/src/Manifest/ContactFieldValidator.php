<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

use Whity\Sdk\PluginNamespace;

/**
 * The shape of the contact fields a plugin declares with
 * {@see \Whity\Sdk\PluginContactFieldsInterface::getContactFields()}
 * (SDK 1.119, WHIT-1209).
 *
 * ```php
 * [
 *     'key' => 'supplier_code',            // becomes keyhub.supplier_code
 *     'label' => ['en' => 'Supplier code', 'ar' => 'رمز المورّد'],
 *     'type' => 'text',
 *     'inList' => true,
 * ]
 * ```
 *
 * The host runs the same checks; a plugin can run them in its own tests.
 */
final class ContactFieldValidator
{
    /** The most fields one plugin may add to contacts. */
    public const MAX_FIELDS = 30;

    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'multiselect', 'checkbox', 'profile_ref'];

    public const MAX_LABEL = 100;

    public const MAX_OPTIONS = 100;

    /** `custom_field_definitions.field_key` is 64 characters. */
    public const MAX_KEY = 64;

    private function __construct()
    {
    }

    /**
     * The field's full key under this plugin (`keyhub.supplier_code`), or
     * null when the key can't be one of this plugin's.
     */
    public static function qualifiedKey(string $pluginName, mixed $key): ?string
    {
        $slug = PluginNamespace::slug($pluginName);
        if ($slug === null || !is_string($key)) {
            return null;
        }
        $full = str_contains($key, '.') ? $key : $slug . '.' . $key;
        if (!str_starts_with($full, $slug . '.') || strlen($full) > self::MAX_KEY
            || preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $full) !== 1) {
            return null;
        }

        return $full;
    }

    /**
     * What is wrong with one declared field; empty when it is fine.
     *
     * @return list<string>
     */
    public static function errors(string $pluginName, mixed $field): array
    {
        if (!is_array($field)) {
            return ['a field must be an array'];
        }
        $errors = [];
        if (self::qualifiedKey($pluginName, $field['key'] ?? null) === null) {
            $errors[] = 'key must be lower-case letters, digits and _, starting with a letter, optionally under this plugin\'s own name and a dot, '
                . self::MAX_KEY . ' characters in all';
        }
        $label = $field['label'] ?? null;
        foreach (['en', 'ar'] as $lang) {
            $text = is_array($label) ? ($label[$lang] ?? null) : null;
            if (!is_string($text) || trim($text) === '' || mb_strlen(trim($text)) > self::MAX_LABEL) {
                $errors[] = 'label.' . $lang . ' is required, up to ' . self::MAX_LABEL . ' characters';
            }
        }
        $type = $field['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            $errors[] = 'type must be one of ' . implode(', ', self::TYPES);
        }
        if (in_array($type, ['select', 'multiselect'], true)) {
            $options = $field['options'] ?? null;
            $ok = is_array($options) && $options !== [] && count($options) <= self::MAX_OPTIONS;
            foreach (is_array($options) ? $options : [] as $option) {
                $ok = $ok && is_string($option) && trim($option) !== '';
            }
            if (!$ok) {
                $errors[] = 'options must be a list of 1 to ' . self::MAX_OPTIONS . ' choices';
            }
        }
        foreach (['required', 'inList'] as $flag) {
            if (array_key_exists($flag, $field) && !is_bool($field[$flag])) {
                $errors[] = $flag . ' must be true or false';
            }
        }
        if (array_key_exists('editPermission', $field)
            && (!is_string($field['editPermission']) || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.-]+$/', $field['editPermission']) !== 1)) {
            $errors[] = 'editPermission must be a permission such as keyhub:purchasing';
        }

        return $errors;
    }
}
