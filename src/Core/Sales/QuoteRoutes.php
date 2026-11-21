<?php

declare(strict_types=1);

namespace Whity\Core\Sales;

use Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Settings\SettingsRegistry;

/**
 * The route a quote travels before it goes out, when the workspace chose one
 * in Flows (`sales.quote_route_template_id`, WHIT-1321, QT.4): conditions such
 * as "total above $500" live in the template's own rules (FL.1). With none
 * chosen there is nothing to approve.
 */
final class QuoteRoutes implements QuoteApprovals
{
    /** @param \Closure(int): array<string, mixed> $settings the workspace's effective settings */
    public function __construct(
        private readonly DocumentRouter $router,
        private readonly RouteTemplateRepository $templates,
        private readonly \Closure $settings,
    ) {
    }

    public function issue(int $tenantId, ?int $actor, array $quote): ?int
    {
        $templateId = (int) (($this->settings)($tenantId)[SettingsRegistry::SALES_QUOTE_ROUTE_TEMPLATE_ID] ?? 0);
        $template = $templateId > 0 ? $this->templates->findById($templateId, $tenantId) : null;
        if ($template === null) {
            return null;
        }
        $steps = RouteTemplateInstantiation::toRouteSteps(
            $this->templates->stepsFor((int) $template['id'], $tenantId),
            $this->templates->edgesFor((int) $template['id'], $tenantId),
        );
        $title = 'Quote ' . ($quote['number'] ?? '') . ($quote['contact_name'] !== '' ? ' for ' . $quote['contact_name'] : '');
        $issued = $this->router->issueOnSubject(
            $tenantId,
            $actor,
            QuoteRouteSubject::TYPE,
            (int) $quote['id'],
            null,
            trim(preg_replace('/\s+/', ' ', $title) ?? $title),
            $steps,
            (int) $template['id'],
            (string) $template['name'],
        );

        return (int) $issued['route']['id'];
    }
}
