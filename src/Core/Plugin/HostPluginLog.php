<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Psr\Log\LoggerInterface;
use Whity\Core\Observability\ErrorScrubber;
use Whity\Core\Observability\ErrorTracker;
use Whity\Sdk\Log\ErrorReporter;
use Whity\Sdk\Log\PluginLog;

/**
 * The host's {@see PluginLog} and {@see ErrorReporter} for one plugin (SDK 1.152,
 * #1686).
 *
 * Everything a plugin hands over is scrubbed by {@see ErrorScrubber}, the same
 * rules core's error tracker applies, before it reaches the log or the tracker.
 * The plugin's name and the workspace are added after scrubbing and override any
 * value the plugin put under those keys, so a record always says truthfully who
 * wrote it. Nothing here throws: a failing logger or tracker is swallowed.
 */
final class HostPluginLog implements PluginLog, ErrorReporter
{
    /**
     * @param \Closure(): ?int $workspace
     */
    public function __construct(
        private readonly string $plugin,
        private readonly LoggerInterface $logger,
        private readonly ?ErrorTracker $tracker,
        private readonly \Closure $workspace,
        private readonly ErrorScrubber $scrubber = new ErrorScrubber(),
    ) {
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function report(\Throwable $error, array $context = []): void
    {
        $tags = $this->tags($context);
        try {
            if ($this->tracker !== null) {
                // The tracker scrubs the exception's message and trace itself.
                $this->tracker->captureException($error, $tags);

                return;
            }
        } catch (\Throwable) {
            // Fall through to the log.
        }
        $this->write('error', $error::class . ': ' . $error->getMessage(), $context + ['reported' => true]);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        try {
            $this->logger->log($level, '[plugin:' . $this->plugin . '] ' . $this->scrubber->text($message), $this->tags($context));
        } catch (\Throwable) {
            // Logging never breaks the plugin.
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function tags(array $context): array
    {
        $scrubbed = $this->scrubber->context($context);
        $scrubbed['plugin'] = $this->plugin;
        $workspace = null;
        try {
            $workspace = ($this->workspace)();
        } catch (\Throwable) {
        }
        $scrubbed['tenant_id'] = $workspace;

        return $scrubbed;
    }
}
