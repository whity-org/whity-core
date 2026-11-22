<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Settings\SettingsRegistry;

/**
 * The route an invoice travels before it is issued, when the workspace chose
 * one (`accounting.invoice_route_template_id`, WHIT-1318 A2a): conditions such
 * as "total above JOD 1,000.000" live in the template's own rules (FL.1).
 * With none chosen there is nothing to approve.
 */
final class InvoiceRoutes implements InvoiceApprovals
{
    /** @param \Closure(int): array<string, mixed> $settings the workspace's effective settings */
    public function __construct(
        private readonly DocumentRouter $router,
        private readonly RouteTemplateRepository $templates,
        private readonly \Closure $settings,
        private readonly string $settingKey = SettingsRegistry::ACCOUNTING_INVOICE_ROUTE_TEMPLATE_ID,
        private readonly string $subjectType = InvoiceRouteSubject::TYPE,
    ) {
    }

    public function issue(int $tenantId, ?int $actor, array $invoice): ?int
    {
        $templateId = (int) (($this->settings)($tenantId)[$this->settingKey] ?? 0);
        $template = $templateId > 0 ? $this->templates->findById($templateId, $tenantId) : null;
        if ($template === null) {
            return null;
        }
        $steps = RouteTemplateInstantiation::toRouteSteps(
            $this->templates->stepsFor((int) $template['id'], $tenantId),
            $this->templates->edgesFor((int) $template['id'], $tenantId),
        );
        $name = is_string($invoice['contact_name'] ?? null) ? $invoice['contact_name'] : '';
        $title = $this->subjectType === InvoiceRouteSubject::BILL
            ? 'Bill from ' . ($name !== '' ? $name : 'a supplier')
            : 'Invoice for ' . ($name !== '' ? $name : 'a customer');
        $issued = $this->router->issueOnSubject(
            $tenantId,
            $actor,
            $this->subjectType,
            (int) $invoice['id'],
            null,
            trim(preg_replace('/\s+/', ' ', $title) ?? $title),
            $steps,
            (int) $template['id'],
            (string) $template['name'],
        );

        return (int) $issued['route']['id'];
    }
}
