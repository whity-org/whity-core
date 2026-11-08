<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

/**
 * Whether the render service can print for this app, and if not, why not and
 * what fixes it (WHIT-1001).
 *
 * Two sentences, on purpose, because they have two readers:
 *
 * - `message` may reach anyone whose print failed. It names the KIND of fault
 *   and nothing about the deployment: no URL, no secret, no host. It is what a
 *   503 from a document route says instead of a bare "unavailable".
 * - `fix` is for the operator, and is only shown on the admin status line and
 *   by `whity-cli render:check`. It says exactly what to change.
 *
 * Version numbers appear in both. They are not a secret: `GET /api/health`
 * already publishes the app's, and the mismatch is the thing to fix.
 */
final class RenderReadiness
{
    /** Ready to print. */
    public const READY = 'ready';
    /** RENDER_SERVICE_URL is empty. */
    public const NOT_CONFIGURED = 'not_configured';
    /** RENDER_SHARED_SECRET is unset or under 32 characters on the app. */
    public const SECRET_MISSING = 'secret_missing';
    /** Nothing answered at RENDER_SERVICE_URL. */
    public const UNREACHABLE = 'unreachable';
    /** The render service answered but refused the app's secret. */
    public const SECRET_MISMATCH = 'secret_mismatch';
    /** The render service was built for a different core release. */
    public const VERSION_MISMATCH = 'version_mismatch';

    /** The smallest secret either side accepts. */
    public const MIN_SECRET_LENGTH = 32;

    private function __construct(
        public readonly string $state,
        public readonly string $appVersion,
        public readonly ?string $renderVersion,
        public readonly string $message,
        public readonly string $fix,
    ) {
    }

    public function isReady(): bool
    {
        return $this->state === self::READY;
    }

    public static function ready(string $appVersion, string $renderVersion): self
    {
        return new self(
            self::READY,
            $appVersion,
            $renderVersion,
            'The document renderer is connected',
            '',
        );
    }

    public static function notConfigured(string $appVersion): self
    {
        return new self(
            self::NOT_CONFIGURED,
            $appVersion,
            null,
            'Document rendering is not set up on this instance',
            'Set RENDER_SERVICE_URL on the app (normally http://render:8130) and start the render service. '
                . 'See docs/wiki/Document-Render-Service.md, "Set up rendering".',
        );
    }

    public static function secretMissing(string $appVersion): self
    {
        return new self(
            self::SECRET_MISSING,
            $appVersion,
            null,
            'Document rendering is not set up on this instance',
            'Set RENDER_SHARED_SECRET to the same random value, at least ' . self::MIN_SECRET_LENGTH
                . ' characters, on the app and on the render service (for example the output of '
                . '`openssl rand -hex 32`), then restart both. `whity install` writes one for you.',
        );
    }

    public static function unreachable(string $appVersion, string $baseUrl): self
    {
        return new self(
            self::UNREACHABLE,
            $appVersion,
            null,
            'The document renderer is not reachable',
            'Start the render service: add `render` to COMPOSE_PROFILES in .env (or run '
                . '`docker compose --profile render up -d`), and check that RENDER_SERVICE_URL ('
                . $baseUrl . ') reaches it from the app container.',
        );
    }

    public static function secretMismatch(string $appVersion, ?string $renderVersion): self
    {
        return new self(
            self::SECRET_MISMATCH,
            $appVersion,
            $renderVersion,
            'The document renderer refused this app\'s key',
            'RENDER_SHARED_SECRET differs between the app and the render service, or the render '
                . 'service has none of at least ' . self::MIN_SECRET_LENGTH . ' characters. Set the same '
                . 'value on both and restart both.',
        );
    }

    public static function versionMismatch(string $appVersion, ?string $renderVersion): self
    {
        $reported = $renderVersion ?? 'an unknown version';

        return new self(
            self::VERSION_MISMATCH,
            $appVersion,
            $renderVersion,
            'The document renderer is version ' . $reported . ' but this app is ' . $appVersion
                . ', so printing is paused until they match',
            'Run the render image at the app\'s tag: ghcr.io/whity-org/whity-core/render:v' . $appVersion
                . '. With the published compose files, one WHITY_VERSION sets the app, web and render '
                . 'tags together; set it to v' . $appVersion . ' and run `docker compose up -d`.',
        );
    }

    /**
     * @return array{state: string, ready: bool, app_version: string, render_version: ?string, message: string, fix: string}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'ready' => $this->isReady(),
            'app_version' => $this->appVersion,
            'render_version' => $this->renderVersion,
            'message' => $this->message,
            'fix' => $this->fix,
        ];
    }
}
