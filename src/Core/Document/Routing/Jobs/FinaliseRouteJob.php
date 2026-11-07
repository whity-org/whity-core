<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing\Jobs;

use Whity\Core\Document\Routing\RouteFinaliseService;
use Whity\Core\Document\Routing\RouteFinaliser;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\JobInterface;

/**
 * Tries a route's final document again (WHIT-921, #1725), after the render
 * service was down when the route reached its finalise step.
 *
 * Internal: {@see RouteFinaliser} enqueues it, never a public caller. While the
 * step is still waiting it fails, so the queue tries again with a longer pause
 * each time; once the document is made (or can never be) it succeeds. Safe to
 * run twice: a finished step is left as it is, and an attempt in progress
 * holds the step so a second one does not render it again.
 */
final class FinaliseRouteJob implements JobInterface
{
    public const NAME = 'core.routes.finalise';

    /** @var \Closure(): RouteFinaliseService */
    private \Closure $finaliser;

    /** @param \Closure(): RouteFinaliseService $finaliser built on first use */
    public function __construct(\Closure $finaliser)
    {
        $this->finaliser = $finaliser;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $tenantId = TenantContext::getTenantId();
        $routeId = $payload['route_id'] ?? null;
        if ($tenantId === null || !is_int($routeId)) {
            return ['outcome' => 'ignored'];
        }

        $outcome = ($this->finaliser)()->finalise($tenantId, $routeId);
        if ($outcome === RouteFinaliser::WAITING || $outcome === RouteFinaliser::BUSY) {
            throw new \RuntimeException('The final document is still waiting; trying again later.');
        }

        return ['outcome' => $outcome];
    }
}
