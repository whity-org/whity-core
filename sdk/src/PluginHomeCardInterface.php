<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Optional (SDK 1.62, WHIT-872): this plugin's one card on the home page. It names
 * the app, shows one number that matters, a few lines and at most one chart, and
 * links into the app. A person can hide, move and resize it with the dashboard's
 * widgets ({@see PluginDashboardInterface}, register H.5). A dashboard `stat`
 * widget now covers the same ground; prefer it for new plugins.
 *
 * `source` is a GET route this plugin registered under this same `permission`, and
 * {@see Manifest\HomeCardValidator} describes what it answers. That route decides
 * the person's scope, because the host shows what it returns. A card grants nothing
 * by itself: it appears only to people holding `permission`, and a bad one is
 * dropped with a logged reason while the plugin keeps loading.
 */
interface PluginHomeCardInterface
{
    /**
     * @return array{
     *     label: array{en: string, ar: string},
     *     caption?: array{en: string, ar: string},
     *     permission: string,
     *     source?: string,
     *     record?: string,
     *     screen?: string,
     *     icon?: string
     * }|null null when this plugin adds no card
     */
    public function getHomeCard(): ?array;
}
