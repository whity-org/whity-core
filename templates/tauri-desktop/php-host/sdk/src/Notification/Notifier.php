<?php

declare(strict_types=1);

namespace Whity\Sdk\Notification;

/**
 * Sends a plugin's notices to people in the current workspace (WHIT-1030). Take
 * it from {@see PluginNotifier}.
 *
 * The workspace is the one the request or job is running in. There is no
 * argument to name another, and without one (a command with no workspace) every
 * method throws {@see \RuntimeException}. Only active members of the workspace
 * are reached: a person, role or unit that is not part of it reaches nobody.
 * Each person's notification preferences apply.
 *
 * `$type` is a BARE key the plugin declared in
 * {@see PluginNotificationsInterface}; anything else throws
 * {@see \InvalidArgumentException}, as does a Notice missing a language.
 *
 * Each method returns how many people were notified.
 */
interface Notifier
{
    public function toPerson(string $type, int $profileId, Notice $notice): int;

    /** Everyone holding this role in the workspace. */
    public function toRole(string $type, int $roleId, Notice $notice): int;

    /** Everyone in this unit and the units below it. */
    public function toUnit(string $type, int $unitId, Notice $notice): int;
}
