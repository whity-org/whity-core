<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

use Whity\Sdk\Frontend\Blocks\BlockValidator;

/**
 * The shape of a plugin's guided setup, in `plugin.json`'s `onboarding` or
 * {@see \Whity\Sdk\PluginOnboardingInterface::getOnboarding()} (SDK 1.163,
 * decision D4).
 *
 * Checked from the document alone: the titles in English and Arabic, a
 * `permission` the plugin declares, a `status` path, step ids, and each step's
 * blocks through {@see BlockValidator}. Whether `status` is one of the
 * plugin's own GET routes is the host's question, asked when it loads. Pure
 * and static.
 */
final class OnboardingValidator
{
    public const STEP_ID_PATTERN = '/^[a-z][a-z0-9-]{0,40}$/';

    private function __construct()
    {
    }

    /**
     * @param list<string>|null $permissions the plugin's own permissions; null skips that check
     * @return list<string> each naming its path; empty when valid
     */
    public static function errors(mixed $onboarding, ?array $permissions = null, string $path = 'onboarding'): array
    {
        if ($onboarding === null) {
            return [];
        }
        if (!is_array($onboarding) || array_is_list($onboarding)) {
            return ["{$path}: must be an object with title, status, permission and steps"];
        }

        $errors = [];
        foreach (array_keys($onboarding) as $field) {
            if (!in_array($field, ['title', 'description', 'status', 'permission', 'steps'], true)) {
                $errors[] = "{$path}.{$field}: unknown field; expected title, description, status, permission, steps";
            }
        }
        $errors = [...$errors, ...self::text($onboarding['title'] ?? null, "{$path}.title", true)];
        $errors = [...$errors, ...self::text($onboarding['description'] ?? null, "{$path}.description", false)];

        $status = $onboarding['status'] ?? null;
        if (!is_string($status) || !str_starts_with($status, '/')) {
            $errors[] = "{$path}.status: required, the path of one of the plugin's own GET routes";
        }
        $permission = $onboarding['permission'] ?? null;
        if (!is_string($permission) || $permission === '') {
            $errors[] = "{$path}.permission: required, one of the plugin's own permissions";
        } elseif ($permissions !== null && !in_array($permission, $permissions, true)) {
            $errors[] = "{$path}.permission: \"{$permission}\" is not one of the plugin's permissions";
        }

        $steps = $onboarding['steps'] ?? null;
        if (!is_array($steps) || $steps === [] || !array_is_list($steps)) {
            $errors[] = "{$path}.steps: required, a non-empty list of steps";

            return $errors;
        }
        $seen = [];
        foreach ($steps as $i => $step) {
            $at = "{$path}.steps[{$i}]";
            if (!is_array($step)) {
                $errors[] = "{$at}: must be an object";
                continue;
            }
            foreach (array_keys($step) as $field) {
                if (!in_array($field, ['id', 'title', 'description', 'blocks'], true)) {
                    $errors[] = "{$at}.{$field}: unknown field; expected id, title, description, blocks";
                }
            }
            $id = $step['id'] ?? null;
            if (!is_string($id) || preg_match(self::STEP_ID_PATTERN, $id) !== 1) {
                $errors[] = "{$at}.id: required, lowercase letters, digits and dashes, starting with a letter";
            } elseif (isset($seen[$id])) {
                $errors[] = "{$at}.id: \"{$id}\" is used by another step";
            } else {
                $seen[$id] = true;
            }
            $errors = [...$errors, ...self::text($step['title'] ?? null, "{$at}.title", true)];
            $errors = [...$errors, ...self::text($step['description'] ?? null, "{$at}.description", false)];
            $blocks = $step['blocks'] ?? null;
            if (!is_array($blocks) || $blocks === [] || !array_is_list($blocks)) {
                $errors[] = "{$at}.blocks: required, a non-empty list of blocks";
                continue;
            }
            foreach (BlockValidator::validate($blocks)['errors'] as $blockError) {
                $errors[] = "{$at}.blocks: {$blockError}";
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private static function text(mixed $value, string $path, bool $required): array
    {
        if ($value === null) {
            return $required ? ["{$path}: required, {\"en\": …, \"ar\": …}"] : [];
        }
        $errors = [];
        foreach (['en', 'ar'] as $language) {
            $text = is_array($value) ? ($value[$language] ?? null) : null;
            if (!is_string($text) || trim($text) === '') {
                $errors[] = "{$path}.{$language}: required text";
            }
        }

        return $errors;
    }
}
