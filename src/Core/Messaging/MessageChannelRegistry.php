<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

use Whity\Core\Container\HostWiredService;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Support\SourceSlug;
use Whity\Sdk\Messaging\MessageChannel;

/**
 * The channels Messages can talk through (SDK 1.97, ADR 0016 §3).
 *
 * Core's own channels (`email`, `webchat`) register under bare keys. A
 * plugin's register under `<plugin>:<key>`, attributed by the plugin's own
 * name as the loader passes it, never by what the plugin returns, so a plugin
 * cannot pose as core's email channel or as another plugin's channel.
 *
 * {@see HostWiredService}: an improvised empty registry would make every
 * outbound message fail as "no such channel" with nothing pointing at the
 * cause.
 */
final class MessageChannelRegistry implements HostWiredService
{
    public const CORE_SOURCE = 'core';
    public const NAMESPACE_SEPARATOR = ResourceTypeRegistry::NAMESPACE_SEPARATOR;
    public const MAX_KEY_LENGTH = 64;

    /** @var array<string, MessageChannel> */
    private array $channels = [];

    /** @var array<string, string> canonical key => who registered it */
    private array $originByKey = [];

    /** @throws \InvalidArgumentException On a malformed or duplicate key. */
    public function registerCore(MessageChannel $channel): void
    {
        $key = $channel->key();
        if (!self::isValidSlug($key)) {
            throw new \InvalidArgumentException("Invalid message channel key '{$key}'.");
        }
        if (isset($this->channels[$key])) {
            throw new \InvalidArgumentException("Message channel '{$key}' is already registered.");
        }
        $this->channels[$key] = $channel;
        $this->originByKey[$key] = self::CORE_SOURCE;
    }

    /**
     * Register a plugin's channels. The whole batch is checked first; a plugin
     * registering again (a reload) replaces only its own channels.
     *
     * @param array<mixed> $channels
     *
     * @throws \InvalidArgumentException On a malformed key, a non-channel, a
     *                                   duplicate in the batch, or a plugin calling itself core.
     */
    public function register(string $pluginName, array $channels): void
    {
        $prefix = SourceSlug::from($pluginName);
        if ($pluginName === self::CORE_SOURCE || $prefix === null) {
            throw new \InvalidArgumentException("Plugin name '{$pluginName}' cannot own message channels.");
        }

        $batch = [];
        foreach ($channels as $channel) {
            if (!$channel instanceof MessageChannel) {
                throw new \InvalidArgumentException('getMessageChannels() must return MessageChannel instances.');
            }
            $key = $channel->key();
            if (!self::isValidSlug($key)) {
                throw new \InvalidArgumentException("Invalid message channel key '{$key}'.");
            }
            $canonical = $prefix . self::NAMESPACE_SEPARATOR . $key;
            if (isset($batch[$canonical])) {
                throw new \InvalidArgumentException("Message channel '{$key}' is declared twice.");
            }
            $batch[$canonical] = $channel;
        }

        foreach ($this->originByKey as $key => $origin) {
            if ($origin === $pluginName) {
                unset($this->channels[$key], $this->originByKey[$key]);
            }
        }
        foreach ($batch as $key => $channel) {
            $this->channels[$key] = $channel;
            $this->originByKey[$key] = $pluginName;
        }
    }

    public function get(string $key): ?MessageChannel
    {
        return $this->channels[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->channels[$key]);
    }

    /** Core's or the plugin name that registered it; null when unknown. */
    public function origin(string $key): ?string
    {
        return $this->originByKey[$key] ?? null;
    }

    /** @return list<string> sorted */
    public function keys(): array
    {
        $keys = array_keys($this->channels);
        sort($keys);

        return $keys;
    }

    /**
     * What staff and the settings screen read: every channel, whether this
     * workspace has it on, and what it can do. A channel whose own code throws
     * is listed as off rather than taking the list down.
     *
     * @return list<array{key: string, label: array<string, string>, origin: string, enabled: bool, capabilities: array<string, bool|int|null>}>
     */
    public function catalogue(int $tenantId): array
    {
        $out = [];
        foreach ($this->keys() as $key) {
            $channel = $this->channels[$key];
            try {
                $label = $channel->label();
                $enabled = $channel->isEnabled($tenantId);
                $capabilities = $channel->capabilities()->toArray();
            } catch (\Throwable $e) {
                error_log("[MessageChannelRegistry] channel {$key} failed to describe itself: " . $e->getMessage());
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => array_filter($label, 'is_string'),
                'origin' => $this->originByKey[$key] ?? self::CORE_SOURCE,
                'enabled' => $enabled,
                'capabilities' => $capabilities,
            ];
        }

        return $out;
    }

    /** A bare key: `[a-z][a-z0-9_]*`, at most {@see MAX_KEY_LENGTH} characters. */
    public static function isValidSlug(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1 && strlen($key) <= self::MAX_KEY_LENGTH;
    }
}
