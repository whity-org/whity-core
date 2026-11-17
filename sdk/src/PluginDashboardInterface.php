<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * What this plugin adds to the workspace dashboard (SDK 1.115, WHIT-1177).
 *
 * Optional. Two parts, each optional:
 *
 * `setup`: points this plugin adds to the dashboard's "Finish setting up" card,
 * beside core's own (logo, team, an agent, the tax number, a plan). Each point
 * has `{en, ar}` words and opens one of this plugin's own places: a step of its
 * guided setup ({@see PluginOnboardingInterface}, `step`) or one of its screens
 * (`screen`). Whether a point is done comes from `status`, one of this plugin's
 * own GET routes gated on `permission`, answering
 * `{"data": {"points": {"<point id>": true|false}}}`. Work it out from what the
 * workspace holds, as onboarding's status does, so a point done by hand counts.
 * A point the status leaves out counts as not done; when the route fails, this
 * plugin's points are left off the card and the rest of it still shows.
 *
 * `widgets`: cards on the dashboard, of four kinds: `stat` (one or a few
 * numbers), `list` (a short list or table), `chart` (one chart) and `actions`
 * (buttons to this plugin's own routes or screens). A widget is built from
 * blocks ({@see Frontend\Blocks\BlockContract}), from the set its kind allows
 * ({@see Manifest\DashboardValidator::BLOCKS_BY_KIND}). Its data comes from one
 * route, `source`, a GET route of this plugin gated on the widget's own
 * `permission`, and every data-bound block in it reads that route. The route
 * answers in the person's scope, because the host shows what it returns.
 * `sizes` are the widths it fits, first the one it starts at.
 *
 * Core lists a widget only to people holding its permission in the workspace,
 * and only while the plugin is on there. People may hide, show, move and resize
 * widgets on their own dashboard; the order and sizes are theirs alone. A widget
 * whose route fails or answers nothing shows a calm empty line and never stops
 * the rest of the page.
 *
 * Every visible string is `{en, ar}`. A bad point or widget is dropped with a
 * logged reason and the plugin keeps loading; {@see Manifest\DashboardValidator}
 * checks the shape so a plugin can test it.
 */
interface PluginDashboardInterface
{
    /**
     * @return array{
     *     setup?: array{
     *         permission: string,
     *         status: string,
     *         points: list<array{
     *             id: string,
     *             label: array{en: string, ar: string},
     *             why?: array{en: string, ar: string},
     *             action?: array{en: string, ar: string},
     *             step?: string,
     *             screen?: string,
     *             icon?: string
     *         }>
     *     },
     *     widgets?: list<array{
     *         id: string,
     *         kind: 'stat'|'list'|'chart'|'actions',
     *         title: array{en: string, ar: string},
     *         description?: array{en: string, ar: string},
     *         permission: string,
     *         source?: string,
     *         sizes?: list<'small'|'medium'|'large'>,
     *         blocks: list<array<string, mixed>>,
     *         screen?: string,
     *         icon?: string
     *     }>
     * }
     */
    public function getDashboard(): array;
}
