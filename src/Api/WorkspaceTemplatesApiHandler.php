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
use Whity\Core\Workspace\Templates\TemplateSetup;
use Whity\Http\JsonBody;

/**
 * Workspace templates (SDK 1.116, WHIT-1194), all under `settings:write`:
 *   GET    /api/workspace/templates              → the cards, which ones this workspace chose, live examples
 *   POST   /api/workspace/templates/{key}/apply  → { examples?: bool } apply it as the caller
 *   DELETE /api/workspace/templates/examples     → remove the examples in one click
 *
 * and a chosen template's guided setup (WHIT-1195), listed with the plugins'
 * in `GET /api/workspace/onboarding` as `template-{key}`:
 *   GET  /api/workspace/templates/{key}/setup/status         → which steps are done
 *   GET  /api/workspace/templates/{key}/setup/current/{kind} → what a step opens with
 *   GET  /api/workspace/templates/{key}/setup/services       → the template's services to tick
 *   POST /api/workspace/templates/{key}/setup/{kind}         → save a step, as the caller
 *   POST /api/workspace/templates/{key}/setup/offer/close    → "Not now" on the plugin offer
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

    /** A workspace this new, with no template yet, is sent to the picker after sign-in. */
    public const PICKER_DAYS = 14;

    /**
     * @param \Closure(): TemplateApplier $applier
     * @param (\Closure(): TemplateSetup)|null $setup
     */
    public function __construct(
        private readonly TemplateCatalogue $catalogue,
        private readonly TemplateRuns $runs,
        private readonly \Closure $applier,
        private readonly ?CodePluginActivation $activation = null,
        private readonly ?AuditLogger $audit = null,
        private readonly ?\Closure $language = null,
        private readonly ?\Closure $setup = null,
    ) {
    }

    /**
     * The guided setups of the templates this workspace chose, as plugin
     * onboarding entries with text still `{en, ar}` (WHIT-1195).
     *
     * @return list<array<string, mixed>>
     */
    public function onboardings(int $tenantId): array
    {
        $out = [];
        foreach ($this->runs->forTenant($tenantId) as $run) {
            $template = $this->catalogue->find($run['template_key']);
            if ($template === null) {
                continue;
            }
            $entry = TemplateSetup::onboarding($template, $this->rawOffer($template), \Whity\Core\RBAC\CorePermissions::SETTINGS_WRITE);
            if ($entry !== null) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * The chosen templates' steps as dashboard setup points (SDK 1.115 shape):
     * a screen step is left out, since nothing in the workspace says it is
     * done, and at most six are listed.
     *
     * @return list<array{plugin: string, permission: string, status: string, points: list<array<string, mixed>>}>
     */
    public function dashboardSetup(int $tenantId): array
    {
        $out = [];
        foreach ($this->onboardings($tenantId) as $entry) {
            $points = [];
            foreach ($entry['steps'] as $step) {
                if (self::isScreenStep($entry, (string) $step['id'])) {
                    continue;
                }
                $points[] = ['id' => $step['id'], 'label' => $step['title'], 'why' => $step['description']];
            }
            if ($points !== []) {
                $out[] = ['plugin' => (string) $entry['plugin'], 'permission' => (string) $entry['permission'], 'status' => (string) $entry['status'], 'points' => array_slice($points, 0, 6)];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $entry */
    private function isScreenStep(array $entry, string $stepId): bool
    {
        $template = $this->catalogue->find(substr((string) $entry['plugin'], strlen(TemplateSetup::PREFIX)));
        if ($template === null) {
            return false;
        }
        foreach (is_array($template['setup']['steps'] ?? null) ? $template['setup']['steps'] : [] as $step) {
            if (is_array($step) && $step['id'] === $stepId) {
                return $step['kind'] === 'screen';
            }
        }

        return false;
    }

    /** @param array<string, string> $params */
    public function setupStatus(Request $request, array $params = []): Response
    {
        return $this->withSetup($request, $params, function (TemplateSetup $setup, array $template, int $tenantId, int $profileId): Response {
            $offer = $this->rawOffer($template);
            $state = $offer !== null && $this->activation !== null ? $this->activation->stateFor((string) $offer['plugin'], $tenantId) : null;
            $run = $this->runs->find($tenantId, (string) $template['key']);

            $steps = $setup->status($template, $profileId, $state, ($run['offer_closed_at'] ?? null) !== null);

            // The stepper reads `steps`, the dashboard's setup points `points`.
            return Response::json(['data' => ['steps' => $steps, 'points' => $steps]]);
        });
    }

    /** @param array<string, string> $params */
    public function setupCurrent(Request $request, array $params = []): Response
    {
        return $this->withSetup($request, $params, static fn (TemplateSetup $setup, array $template, int $tenantId, int $profileId): Response => Response::json(['data' => $setup->current((string) ($params['kind'] ?? ''), $profileId)]));
    }

    /** @param array<string, string> $params */
    public function setupServices(Request $request, array $params = []): Response
    {
        return $this->withSetup($request, $params, static fn (TemplateSetup $setup, array $template): Response => Response::json(['data' => $setup->serviceRows($template)]));
    }

    /** @param array<string, string> $params */
    public function setupSave(Request $request, array $params = []): Response
    {
        return $this->withSetup($request, $params, static function (TemplateSetup $setup, array $template, int $tenantId, int $profileId) use ($request, $params): Response {
            $kind = (string) ($params['kind'] ?? '');
            $kinds = array_column(is_array($template['setup']['steps'] ?? null) ? $template['setup']['steps'] : [], 'kind');
            if (!in_array($kind, $kinds, true)) {
                return Response::error('This template has no such step.', 404);
            }

            try {
                return Response::json(['data' => $setup->save($kind, $profileId, JsonBody::parsed($request))]);
            } catch (\InvalidArgumentException $e) {
                return Response::error($e->getMessage(), 422, ['reason' => 'unreadable']);
            }
        });
    }

    /** @param array<string, string> $params */
    public function closeOffer(Request $request, array $params = []): Response
    {
        return $this->withSetup($request, $params, function (TemplateSetup $setup, array $template, int $tenantId): Response {
            return Response::json(['data' => ['closed' => $this->runs->close($tenantId, (string) $template['key'], 'offer')]]);
        });
    }

    /**
     * Run a setup route for a template this workspace chose.
     *
     * @param array<string, string> $params
     * @param callable(TemplateSetup, array<string, mixed>, int, int): Response $then
     */
    private function withSetup(Request $request, array $params, callable $then): Response
    {
        return $this->inWorkspace(function (int $tenantId) use ($request, $params, $then): Response {
            $profileId = self::profileId($request);
            if ($profileId === null || $this->setup === null) {
                return Response::error('Sign in to set up this workspace.', 401);
            }
            $key = (string) ($params['key'] ?? '');
            $template = $this->catalogue->find($key);
            if ($template === null || $this->runs->find($tenantId, $key) === null) {
                return Response::error('This workspace did not start from that template.', 404);
            }

            return $then(($this->setup)(), $template, $tenantId, $profileId);
        });
    }

    /**
     * The template's offer with its text still `{en, ar}` and the plugin's
     * trial days, when the plugin is installed and paid.
     *
     * @param array<string, mixed> $template
     * @return array<string, mixed>|null
     */
    private function rawOffer(array $template): ?array
    {
        $offer = $template['offer'] ?? null;
        if (!is_array($offer) || $this->activation === null) {
            return null;
        }
        $paid = $this->activation->paidAccessOf((string) $offer['plugin']);

        return $paid === null ? null : ['trialDays' => self::trialDays($offer, $paid['trialDays'])] + $offer;
    }

    /** @var (\Closure(int, ?int, string, ?int): Response)|null */
    private ?\Closure $trialStarter = null;

    /**
     * How a trial starts: the Plugins page's own path, with every rule it
     * applies ({@see WorkspaceCodePluginsApiHandler::startTrialFor()}).
     *
     * @param \Closure(int, ?int, string, ?int): Response $starter (workspace, person, plugin, days)
     */
    public function useTrialStarter(\Closure $starter): void
    {
        $this->trialStarter = $starter;
    }

    /**
     * POST /api/workspace/templates/{key}/setup/offer/trial (plugins:enable):
     * start the offered plugin's trial for the template's own length.
     *
     * @param array<string, string> $params
     */
    public function startOfferTrial(Request $request, array $params = []): Response
    {
        return $this->withSetup($request, $params, function (TemplateSetup $setup, array $template, int $tenantId, int $profileId): Response {
            $offer = $this->rawOffer($template);
            if ($offer === null || $this->trialStarter === null) {
                return Response::error('This template offers no plugin here.', 404);
            }
            $days = (int) $offer['trialDays'];
            if ($days <= 0) {
                return Response::error('This workspace has already had its trial, or the plugin offers none', 409, ['reason' => 'trial_unavailable']);
            }

            return ($this->trialStarter)($tenantId, $profileId, (string) $offer['plugin'], $days);
        });
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
                'offerPicker' => $chosen === [] && $this->runs->isNew($tenantId, self::PICKER_DAYS),
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
            'trialDays' => self::trialDays($offer, $paid['trialDays']),
            'trialAvailable' => $this->activation->trialAvailable($plugin, $tenantId),
            'state' => $state['state'],
            'reason' => $state['reason'],
            'trialEndsAt' => $state['trialEndsAt'],
        ];
    }

    /**
     * The trial a template's setup starts: the template's own length
     * (owner, 2026-11-17: 30 days) when the plugin offers a trial at all,
     * else the plugin's.
     *
     * @param array<string, mixed> $offer
     */
    public static function trialDays(array $offer, int $pluginDays): int
    {
        if ($pluginDays <= 0) {
            return 0;
        }
        $days = $offer['trialDays'] ?? null;

        return is_int($days) && $days > 0 ? min($days, \Whity\Sdk\Manifest\WorkspaceTemplateValidator::MAX_TRIAL_DAYS) : $pluginDays;
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
