<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Plan\WebsitePlans;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;

/**
 * `GET /api/public/plans`: the plans whity.dev shows (WS.5). Public and
 * read-only; the contract is `docs/design/sites/README.md`, the shape is
 * {@see WebsitePlans}. CORS comes from {@see \Whity\Http\WebsiteCors}.
 *
 * Cached by browsers and the CDN for five minutes. When the catalogue cannot
 * be read the answer is a 503 that is never cached, and the page keeps the
 * plans built into it.
 */
final class PublicPlansApiHandler
{
    public function __construct(
        private readonly WebsitePlans $plans,
        private readonly GlobalSettingsRepository $settings,
        private readonly string $appUrl,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        try {
            $payload = $this->plans->build(
                $this->setting(SettingsRegistry::WEBSITE_PLANS),
                $this->setting(SettingsRegistry::BILLING_DEFAULT_CURRENCY),
                $this->appUrl,
            );
        } catch (\Throwable $e) {
            error_log('[PublicPlans] build failed: ' . $e->getMessage());

            return Response::error('Plans are not available right now.', 503)
                ->withHeaders(['Cache-Control' => 'no-store']);
        }

        return Response::json($payload)->withHeaders(['Cache-Control' => 'public, max-age=300']);
    }

    private function setting(string $key): string
    {
        return $this->settings->get($key) ?? SettingsRegistry::defaultFor($key);
    }
}
