<?php

declare(strict_types=1);

namespace Whity\PluginHost;

use Whity\Core\Hooks\HookManager;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\PluginInterface;

/**
 * Registers a plugin's getHooks() declarations onto a HookManager, wrapping
 * every callback in the same per-plugin error boundary production's
 * PluginLoader::wrapHookCallback() uses: a generic Throwable is logged and
 * swallowed (the payload passes through unchanged, so one broken listener
 * never corrupts the dispatch chain), while HookVetoException is logged at
 * info level and re-thrown — the one exception the SDK sanctions for a
 * listener to abort the operation that dispatched it.
 *
 * A plugin whose getHooks() itself throws loses only its hooks (logged,
 * registration continues for the next plugin) — routes/permissions/migrations
 * are unaffected, since hook declaration is evaluated independently of them.
 */
final class HookRegistrar
{
    public static function registerAll(HookManager $hookManager, string $pluginName, PluginInterface $plugin): void
    {
        try {
            $hooks = $plugin->getHooks();
        } catch (\Throwable $e) {
            error_log("[php-host] plugin '{$pluginName}' getHooks() threw " . get_class($e) . ': ' . $e->getMessage());

            return;
        }

        foreach ($hooks as $eventName => $hookData) {
            if (!is_string($eventName)) {
                continue;
            }

            foreach (self::normalize($hookData) as [$callback, $priority, $observe]) {
                $hookManager->listen(
                    $eventName,
                    $observe ? self::wrapObserveOnly($pluginName, $callback) : self::wrap($pluginName, $callback),
                    $priority
                );
            }
        }
    }

    /**
     * Normalize a getHooks() entry into a flat list of [callable, priority, observe] entries.
     *
     * @return list<array{0: callable, 1: int, 2: bool}>
     */
    private static function normalize(mixed $hookData): array
    {
        if (is_callable($hookData)) {
            return [[$hookData, 10, false]];
        }

        if (!is_array($hookData)) {
            return [];
        }

        // A single structured subscription: ['callback' => ..., 'priority' => ..., 'observe' => ...].
        if (isset($hookData['callback']) && is_callable($hookData['callback'])) {
            return [self::structured($hookData)];
        }

        // A list of callables or structured subscriptions.
        $entries = [];
        foreach ($hookData as $item) {
            if (is_callable($item)) {
                $entries[] = [$item, 10, false];
            } elseif (is_array($item) && isset($item['callback']) && is_callable($item['callback'])) {
                $entries[] = self::structured($item);
            }
        }

        return $entries;
    }

    /**
     * @param array<mixed> $subscription Has a callable 'callback'.
     * @return array{0: callable, 1: int, 2: bool}
     */
    private static function structured(array $subscription): array
    {
        /** @var callable $callback */
        $callback = $subscription['callback'];
        $priority = is_int($subscription['priority'] ?? null) ? $subscription['priority'] : 10;

        return [$callback, $priority, ($subscription['observe'] ?? false) === true];
    }

    private static function wrap(string $pluginName, callable $callback): callable
    {
        return function (array $data, array $context = []) use ($pluginName, $callback): array {
            try {
                $result = $callback($data, $context);

                return is_array($result) ? $result : $data;
            } catch (HookVetoException $e) {
                error_log("[php-host] plugin '{$pluginName}' vetoed \"{$e->eventName()}\": {$e->reason()}");
                throw $e;
            } catch (\Throwable $e) {
                error_log("[php-host] plugin '{$pluginName}' hook callback threw " . get_class($e) . ': ' . $e->getMessage());

                return $data;
            }
        };
    }

    /**
     * An observe-only listener (SDK 1.NEXT): its return value is ignored, it holds
     * its own copy of the payload, and a veto it throws does not cross this
     * boundary. Matches production's PluginLoader::wrapObserveOnlyCallback().
     */
    private static function wrapObserveOnly(string $pluginName, callable $callback): callable
    {
        return function (array $data, array $context = []) use ($pluginName, $callback): void {
            try {
                $callback($data, $context);
            } catch (HookVetoException $e) {
                error_log("[php-host] plugin '{$pluginName}' threw a veto from an observe-only listener on \"{$e->eventName()}\"; ignored.");
            } catch (\Throwable $e) {
                error_log("[php-host] plugin '{$pluginName}' hook callback threw " . get_class($e) . ': ' . $e->getMessage());
            }
        };
    }
}
