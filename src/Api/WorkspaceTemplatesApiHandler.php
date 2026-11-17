<?php

declare(strict_types=1);

namespace Whity\Api;

use Throwable;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Plugin\Workspace\CodePluginActivation;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Workspace\Templates\TemplateApplier;
use Whity\Core\Workspace\Templates\TemplateCatalogue;
use Whity\Core\Workspace\Templates\TemplateRuns;
use Whity\Http\JsonBody;

/**
 * Workspace templates (SDK 1.116, WHIT-1194), all under `settings:write`:
 *   GET    /api/workspace/templates              → the cards, which ones this workspace chose, live examples
 *   POST   /api/workspace/templates/{key}/apply  → { examples?: bool } apply it as the caller
 *   DELETE /api/workspace/templates/examples     → remove the examples in one click
 *
 * Templates are free. A template's `offer` is shown only when the plugin it
 * names is installed and paid; the trial itself starts through
 * `POST /api/workspace/code-plugins/{plugin}/trial`, which needs
 * `plugins:enable`.
 */
final class WorkspaceTemplatesApiHandler
{
    public const AUDIT_APPLIED = 'workspace_template.applied';
    public const AUDIT_EXAMPLES_REMOVED = 'workspace_template.examples_removed';

    /** @param \Closure(): TemplateApplier $applier */
    public function __construct(
        private readonly TemplateCatalogue $catalogue,
        private readonly TemplateRuns $runs,
        private readonly \Closure $applier,
        private readonly ?CodePluginActivation $activation = null,
        private readonly ?AuditLogger $audit = null,
        private readonly ?\Closure $language = null,
    ) {
    }

    public function list(Request $request): Response
    {
        return $this->inWorkspace(function (int $tenantId): Response {
            $language = $this->language();
            $chosen = array_column($this->runs->forTenant($tenantId), 'template_key');
            $cards = [];
            foreach ($this->catalogue->all() as $template) {
                $cards[] = [
                    'key' => $template['key'],
                    'plugin' => $template['plugin'] ?? null,
                    'version' => $template['version'],
                    'name' => self::text($template['name'], $language),
                    'summary' => self::text($template['summary'], $language),
                    'icon' => $template['icon'] ?? null,
                    'setsUp' => array_map(static fn (array $line): ?string => self::text($line, $language), $template['setsUp']),
                    'hasExamples' => !empty($template['examples']['contacts']) || !empty($template['examples']['bookings']),
                    'hasSetup' => !empty($template['setup']['steps']),
                    'offer' => $this->offer($template, $tenantId, $language),
                    'chosen' => in_array($template['key'], $chosen, true),
                ];
            }

            return Response::json(['data' => [
                'templates' => $cards,
                'chosen' => $chosen,
                'examples' => count($this->runs->liveExamples($tenantId)),
            ]]);
        });
    }

    /** @param array<string, string> $params */
    public function apply(Request $request, array $params = []): Response
    {
        return $this->inWorkspace(function (int $tenantId) use ($request, $params): Response {
            $profileId = self::profileId($request);
            if ($profileId === null) {
                return Response::error('Sign in to choose a template.', 401);
            }
            $template = $this->catalogue->find((string) ($params['key'] ?? ''));
            if ($template === null) {
                return Response::error('There is no such template.', 404);
            }
            $examples = (JsonBody::parsed($request)['examples'] ?? true) !== false;

            $result = ($this->applier)()->apply($tenantId, $profileId, $template, $examples);
            $this->audit?->record(self::AUDIT_APPLIED, [
                'tenant_id' => $tenantId,
                'actor_user_id' => $profileId,
                'target_type' => 'workspace_template',
                'metadata' => ['template' => $template['key'], 'version' => $template['version'], 'plugin' => $template['plugin'] ?? null, 'made' => $result['made'], 'skipped' => count($result['skipped'])],
            ]);

            return Response::json(['data' => [
                'template' => $template['key'],
                'made' => $result['made'],
                'skipped' => $result['skipped'],
            ]]);
        });
    }

    public function removeExamples(Request $request): Response
    {
        return $this->inWorkspace(function (int $tenantId) use ($request): Response {
            $result = ($this->applier)()->removeExamples($tenantId);
            $this->audit?->record(self::AUDIT_EXAMPLES_REMOVED, [
                'tenant_id' => $tenantId,
                'actor_user_id' => self::profileId($request),
                'target_type' => 'workspace_template',
                'metadata' => $result,
            ]);

            return Response::json(['data' => $result]);
        });
    }

    /**
     * What the offered plugin adds, and whether its trial can start here.
     * Nothing when the plugin isn't installed or isn't paid.
     *
     * @param array<string, mixed> $template
     * @return array<string, mixed>|null
     */
    private function offer(array $template, int $tenantId, string $language): ?array
    {
        $offer = $template['offer'] ?? null;
        if (!is_array($offer) || $this->activation === null) {
            return null;
        }
        $plugin = (string) $offer['plugin'];
        $paid = $this->activation->paidAccessOf($plugin);
        if ($paid === null) {
            return null;
        }
        $state = $this->activation->stateFor($plugin, $tenantId);

        return [
            'plugin' => $plugin,
            'free' => array_map(static fn (array $line): ?string => self::text($line, $language), $offer['free']),
            'paid' => array_map(static fn (array $line): ?string => self::text($line, $language), $offer['paid']),
            'trialDays' => $paid['trialDays'],
            'trialAvailable' => $this->activation->trialAvailable($plugin, $tenantId),
            'state' => $state['state'],
            'reason' => $state['reason'],
            'trialEndsAt' => $state['trialEndsAt'],
        ];
    }

    private function language(): string
    {
        return $this->language !== null && ($this->language)() === 'ar' ? 'ar' : 'en';
    }

    /** `{en, ar}` in the reader's language, English when that one is missing. */
    private static function text(mixed $value, string $language): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }
        $text = $value[$language] ?? $value['en'] ?? null;

        return is_string($text) ? $text : null;
    }

    private function inWorkspace(callable $then): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                return Response::error('Open a workspace to choose a template.', 403);
            }

            return $then($tenantId);
        } catch (Throwable $e) {
            error_log('[WorkspaceTemplatesApiHandler] ' . $e->getMessage());

            return Response::error('The request could not be processed', 500);
        }
    }

    private static function profileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
    }
}
