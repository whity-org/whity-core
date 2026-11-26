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
use Whity\Sdk\Templates\TemplateComposer;
use Whity\Core\Workspace\Templates\TemplateSetup;
use Whity\Http\JsonBody;

/**
 * Workspace templates (SDK 1.116, WHIT-1194), all under `settings:write`:
 *   GET    /api/workspace/templates              → the cards, which ones this workspace chose, live examples
 *   POST   /api/workspace/templates/{key}/apply  → { examples?: bool, theme?: bool } apply it as the caller
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
    public const AUDIT_ITEM_ADOPTED = 'workspace_template.item_adopted';

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

    /** At most this many lines of what the plugin adds, on its locked card. */
    public const LOCKED_CARD_LINES = 3;

    /**
     * WHIT-1202: the paid plugins this workspace's templates offer and it can
     * try now, for locked cards on the dashboard. Text still `{en, ar}`.
     *
     * An offer is listed only when its plugin is installed and paid, offered to
     * workspaces, and this workspace has no access to it (no plan, no cash, no
     * running trial) and has never had its trial. Never for the system
     * workspace. One card per plugin, however many templates name it.
     *
     * @return list<array{key: string, template: string, plugin: string, pluginName: array{en: string, ar: string}, lines: list<array<string, mixed>>, trialDays: int}>
     *
     * @phpstan-impure it reads what the workspace chose and has
     */
    public function lockedOffers(int $tenantId): array
    {
        if ($tenantId === 0 || $this->activation === null) {
            return [];
        }
        $out = [];
        foreach ($this->runs->forTenant($tenantId) as $run) {
            $template = $this->catalogue->find($run['template_key']);
            $offer = $template !== null ? $this->rawOffer($template) : null;
            if ($template === null || $offer === null) {
                continue;
            }
            $plugin = (string) $offer['plugin'];
            if (isset($out[$plugin])
                || (int) $offer['trialDays'] <= 0
                || $this->activation->accessOf($plugin) === \Whity\Core\Plugin\Workspace\CodePluginActivation::OPERATOR
                || !$this->activation->trialAvailable($plugin, $tenantId)
                || $this->activation->mayTurnOn($plugin, $tenantId)
                || $this->activation->stateFor($plugin, $tenantId)['state'] === \Whity\Core\Plugin\Workspace\CodePluginActivation::STATE_ON
            ) {
                continue;
            }
            $paid = is_array($offer['paid'] ?? null) ? array_values(array_filter($offer['paid'], 'is_array')) : [];
            $out[$plugin] = [
                'key' => 'offer:' . $plugin,
                'template' => (string) $template['key'],
                'plugin' => $plugin,
                'pluginName' => $offer['pluginName'],
                'lines' => array_slice($paid, 0, self::LOCKED_CARD_LINES),
                'trialDays' => (int) $offer['trialDays'],
            ];
        }

        return array_values($out);
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
            } catch (\Whity\Core\Workspace\Templates\StepNotSaved $e) {
                // Our own reason, written for the person filling the step in.
                return Response::error($e->words, 422, ['reason' => 'unreadable']);
            } catch (\InvalidArgumentException $e) {
                error_log('[WorkspaceTemplatesApiHandler] setup step: ' . $e->getMessage());

                return Response::error('That step could not be saved. Check what you entered and try again.', 422, ['reason' => 'unreadable']);
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

        return $paid === null ? null : [
            'trialDays' => self::trialDays($offer, $paid['trialDays']),
            'pluginName' => $this->displayName((string) $offer['plugin']),
        ] + $offer;
    }

    /** @var (\Closure(): array<string, array{en: string, ar: string}>)|null */
    private ?\Closure $displayNames = null;

    /**
     * The names people see for plugins (SDK 1.117).
     *
     * @param \Closure(): array<string, array{en: string, ar: string}> $displayNames
     */
    public function useDisplayNames(\Closure $displayNames): void
    {
        $this->displayNames = $displayNames;
    }

    /** @return array{en: string, ar: string} the plugin's display name, its id when it has none */
    private function displayName(string $plugin): array
    {
        $names = $this->displayNames !== null ? ($this->displayNames)() : [];

        return $names[$plugin] ?? ['en' => $plugin, 'ar' => $plugin];
    }

    /** @var (\Closure(int, string): ?array<string, mixed>)|null */
    private ?\Closure $themes = null;

    /**
     * How a theme is found in a workspace: Whity's own, the plugins' and the
     * imported ones ({@see \Whity\Core\Theme\ThemeLibrary::find()}). Without it
     * no template offers a theme.
     *
     * @param \Closure(int, string): ?array<string, mixed> $find (workspace, theme id)
     */
    public function useThemes(\Closure $find): void
    {
        $this->themes = $find;
    }

    /**
     * The template's theme (SDK 1.131, TH.23) when the workspace has it, with
     * its name in the caller's language; null when the template names none or
     * that theme is not installed here.
     *
     * @param array<string, mixed> $template
     * @return array{id: string, name: ?string}|null
     */
    private function theme(array $template, int $tenantId, string $language): ?array
    {
        $id = $template['theme'] ?? null;
        if (!is_string($id) || $this->themes === null) {
            return null;
        }
        $theme = ($this->themes)($tenantId, $id);

        return is_array($theme) ? ['id' => $id, 'name' => self::text($theme['name'] ?? null, $language)] : null;
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
                    'theme' => $this->theme($template, $tenantId, $language),
                    'chosen' => in_array($template['key'], $chosen, true),
                    'update' => $this->update($template, $tenantId, $language),
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
            $body = JsonBody::parsed($request);
            // Examples come with a first apply; an update adds them only when asked (SDK 1.170).
            $examples = ($body['examples'] ?? ($this->runs->find($tenantId, (string) $template['key']) === null)) !== false;
            // The template's theme is offered on by default; only one installed here is set.
            $theme = ($body['theme'] ?? true) !== false ? ($this->theme($template, $tenantId, $this->language())['id'] ?? null) : null;

            $result = ($this->applier)()->apply($tenantId, $profileId, $template, $examples, $theme);
            $this->audit?->record(self::AUDIT_APPLIED, [
                'tenant_id' => $tenantId,
                'actor_user_id' => $profileId,
                'target_type' => 'workspace_template',
                'metadata' => ['template' => $template['key'], 'version' => $template['version'], 'plugin' => $template['plugin'] ?? null, 'theme' => $theme, 'made' => $result['made'], 'skipped' => count($result['skipped'])],
            ]);

            return Response::json(['data' => [
                'template' => $template['key'],
                'made' => $result['made'],
                'skipped' => $result['skipped'],
                'items' => $this->labelled($result['items'], $template),
            ]]);
        });
    }

    /**
     * What applying would do, with the same body as apply, changing nothing
     * and auditing nothing (SDK 1.169, WHIT-1409): each item with `add`,
     * `keep` (already there), `done` (an earlier run made it), `left` (not
     * ticked) or `skip` and why.
     *
     * @param array<string, string> $params
     */
    public function preview(Request $request, array $params = []): Response
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
            $body = JsonBody::parsed($request);
            $run = $this->runs->find($tenantId, (string) $template['key']);
            $examples = ($body['examples'] ?? ($run === null)) !== false;
            $theme = ($body['theme'] ?? true) !== false ? ($this->theme($template, $tenantId, $this->language())['id'] ?? null) : null;
            $result = ($this->applier)()->preview($tenantId, $profileId, $template, $examples, $theme);

            return Response::json(['data' => [
                'template' => $template['key'],
                'version' => $template['version'],
                'appliedVersion' => $run['version'] ?? null,
                'made' => $result['made'],
                'skipped' => $result['skipped'],
                'items' => $this->labelled($result['items'], $template),
            ]]);
        });
    }

    /**
     * "Use the template's version" of one item an earlier run made (SDK
     * 1.170; decisions WT.2, WT.3): changes only what the template sets on
     * it, as the caller, and deletes nothing.
     *
     * @param array<string, string> $params
     */
    public function adoptItem(Request $request, array $params = []): Response
    {
        return $this->inWorkspace(function (int $tenantId) use ($request, $params): Response {
            $profileId = self::profileId($request);
            if ($profileId === null) {
                return Response::error('Sign in to change a template\'s items.', 401);
            }
            $template = $this->catalogue->find((string) ($params['key'] ?? ''));
            if ($template === null) {
                return Response::error('There is no such template.', 404);
            }
            $part = (string) ($params['part'] ?? '');
            $item = (string) ($params['item'] ?? '');
            $result = ($this->applier)()->adopt($tenantId, $profileId, $template, $part, $item);
            if ($result['action'] === 'add') {
                $this->audit?->record(self::AUDIT_ITEM_ADOPTED, [
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $profileId,
                    'target_type' => 'workspace_template',
                    'metadata' => ['template' => $template['key'], 'version' => $template['version'], 'part' => $part, 'item' => $item],
                ]);
            }

            return Response::json(['data' => ['template' => $template['key'], 'part' => $part, 'key' => $item] + $result]);
        });
    }

    /**
     * What is new in a template this workspace applied: each part whose
     * version on offer is newer than the one applied here (or that it never
     * applied, such as a new extension), and the `changes` lines of those
     * versions. Null when nothing is new or the workspace never applied it.
     *
     * @param array<string, mixed> $template
     * @return array{from: int, to: int, parts: list<array{key: string, from: ?int, to: int}>, changes: list<array{version: int, summary: ?string}>}|null
     */
    private function update(array $template, int $tenantId, string $language): ?array
    {
        $run = $this->runs->find($tenantId, (string) $template['key']);
        if ($run === null) {
            return null;
        }
        $recorded = $this->runs->parts($tenantId, $run['id']);
        $parts = [];
        $changes = [];
        foreach (TemplateComposer::parts($template) as $part) {
            $from = $recorded[$part['key']] ?? null;
            if ($from !== null && $from >= $part['version']) {
                continue;
            }
            $parts[] = ['key' => $part['key'], 'from' => $from, 'to' => $part['version']];
            foreach ($part['changes'] as $change) {
                $at = (int) ($change['version'] ?? 0);
                if ($at > ($from ?? 0)) {
                    $changes[] = ['version' => $at, 'summary' => self::text($change['summary'] ?? null, $language)];
                }
            }
        }
        if ($parts === []) {
            return null;
        }

        return [
            'from' => $recorded['template:' . $template['key']] ?? $run['version'],
            'to' => (int) $template['version'],
            'parts' => $parts,
            'changes' => $changes,
        ];
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
            'pluginName' => self::text($this->displayName($plugin), $language),
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

    /**
     * Each item with its name in the reader's language, as the template
     * writes it (WHIT-1429): a service, role, form or pipeline's name, a
     * document's title, an example customer's name, or an example booking's
     * service and customer. Settings, the theme and the calendar have no name
     * of their own here; the screen words them.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $template
     * @return list<array<string, mixed>>
     */
    private function labelled(array $items, array $template): array
    {
        $language = $this->language();
        /** @var array<string, array<string, string|null>> $names */
        $names = [];
        foreach (['services' => 'name', 'roles' => 'name', 'forms' => 'name', 'pipelines' => 'name', 'documents' => 'title'] as $part => $field) {
            foreach (is_array($template[$part] ?? null) ? $template[$part] : [] as $entry) {
                if (is_array($entry) && is_string($entry['key'] ?? null)) {
                    $names[$part][$entry['key']] = self::text($entry[$field] ?? null, $language);
                }
            }
        }
        $examples = is_array($template['examples'] ?? null) ? $template['examples'] : [];
        $contacts = [];
        foreach (is_array($examples['contacts'] ?? null) ? $examples['contacts'] : [] as $contact) {
            if (is_array($contact) && is_string($contact['key'] ?? null) && is_string($contact['name'] ?? null)) {
                $contacts[$contact['key']] = $contact['name'];
            }
        }
        $names['examples'] = $contacts;
        foreach (is_array($examples['bookings'] ?? null) ? $examples['bookings'] : [] as $booking) {
            if (!is_array($booking) || !is_string($booking['key'] ?? null) || isset($names['examples'][$booking['key']])) {
                continue;
            }
            $service = ($names['services'] ?? [])[(string) ($booking['service'] ?? '')] ?? null;
            $who = $contacts[(string) ($booking['contact'] ?? '')] ?? null;
            $names['examples'][$booking['key']] = $service !== null && $who !== null ? "{$service}, {$who}" : $service;
        }

        $out = [];
        foreach ($items as $item) {
            $label = $names[(string) ($item['part'] ?? '')][(string) ($item['key'] ?? '')] ?? null;
            $out[] = $label !== null ? $item + ['label' => $label] : $item;
        }

        return $out;
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
