<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Core\Document\Render\RenderReadiness;
use Whity\Core\Document\Render\RenderServiceClient;
use Whity\Core\Document\Render\RenderServiceClientInterface;

/**
 * `php bin/whity-cli render:check` (WHIT-1001)
 *
 * Can this deployment print documents? Asks the render service the same
 * questions the print paths ask ({@see RenderServiceClient::readiness()}) and
 * prints the answer with the exact fix. Run it inside the app container, where
 * RENDER_SERVICE_URL resolves the way it does for the app:
 *
 *   docker compose exec frankenphp php bin/whity-cli render:check
 *
 * It does not read `documents.render_enabled`: that is the operator's own
 * switch, shown on the admin screen, and this command needs no database.
 *
 * Exit codes: 0 ready, 1 not ready (the output says why and what to change).
 */
final class RenderCheckCommand implements CliCommand
{
    private readonly RenderServiceClientInterface $client;

    public function __construct(?RenderServiceClientInterface $client = null)
    {
        $env = static fn (string $key, string $default): string
            => (string) ($_ENV[$key] ?? (getenv($key) !== false ? getenv($key) : $default));

        $this->client = $client ?? new RenderServiceClient(
            $env('RENDER_SERVICE_URL', 'http://render:8130'),
            $env('RENDER_SHARED_SECRET', ''),
        );
    }

    /**
     * @param array<int, string> $argv Remaining CLI arguments (unused).
     */
    public function execute(array $argv): int
    {
        $readiness = $this->client->readiness(true);

        echo 'App version:      ' . $readiness->appVersion . "\n";
        echo 'Renderer version: ' . ($readiness->renderVersion ?? 'unknown') . "\n";

        if ($readiness->isReady()) {
            echo "\n\033[0;32m✓ The document renderer is connected, the same version, and accepts the shared secret.\033[0m\n";
            echo "  Printing follows the documents.render_enabled switch (on unless an administrator turned it off).\n";

            return 0;
        }

        echo "\n\033[1;31m✗ " . $readiness->message . ' (' . self::label($readiness->state) . ")\033[0m\n";
        echo '  Fix: ' . $readiness->fix . "\n";

        return 1;
    }

    private static function label(string $state): string
    {
        return match ($state) {
            RenderReadiness::NOT_CONFIGURED => 'RENDER_SERVICE_URL is empty',
            RenderReadiness::SECRET_MISSING => 'RENDER_SHARED_SECRET is missing or too short on the app',
            RenderReadiness::UNREACHABLE => 'nothing answered',
            RenderReadiness::SECRET_MISMATCH => 'shared secret refused',
            RenderReadiness::VERSION_MISMATCH => 'version mismatch',
            default => $state,
        };
    }
}
