<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * A guided setup a workspace walks through after turning the plugin on
 * (SDK 1.109, WHIT-1148).
 *
 * Optional. Core draws it as a stepper at `/admin/setup/{plugin}`, offers it
 * from Settings › Plugins, and opens it right after the plugin is turned on or
 * its trial starts. Each step is a block screen, usually one form that posts
 * to one of the plugin's own routes, which can set the workspace up through
 * {@see Setup\WorkspaceSetup}.
 *
 * `status` names one of the plugin's own GET routes. It answers
 * `{"data": {"steps": {"<step id>": true|false}}}`, so the stepper shows which
 * steps are done and opens the first that isn't. Work it out from what the
 * workspace holds, not from a flag, so a step done by hand elsewhere counts
 * too. People may skip steps; nothing forces the order.
 *
 * Every visible string is `{en, ar}`. The blocks follow the same contract as
 * {@see PluginFrontendInterface} screens and are validated the same way: a
 * step that fails validation is dropped, and an onboarding with no valid step
 * is not offered.
 */
interface PluginOnboardingInterface
{
    /**
     * @return array{
     *     title: array{en: string, ar: string},
     *     description?: array{en: string, ar: string},
     *     status: string,
     *     permission: string,
     *     steps: list<array{
     *         id: string,
     *         title: array{en: string, ar: string},
     *         description?: array{en: string, ar: string},
     *         blocks: list<array<string, mixed>>
     *     }>
     * }
     */
    public function getOnboarding(): array;
}
