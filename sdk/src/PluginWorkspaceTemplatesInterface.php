<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Workspace templates: a ready start for one kind of business, offered right
 * after a workspace is created (SDK 1.116, WHIT-1194).
 *
 * Optional. A template is data, not code: a versioned manifest that says what
 * to set up (settings, booking rules, services, roles, forms, examples), what
 * its guided setup asks, and which paid plugin it offers with a trial. Core
 * applies it as the person who chose it, through the same routes and
 * permissions as {@see Setup\WorkspaceSetup}: nothing a template does is more
 * than that person could do by hand, and applying it again adds only what is
 * missing, never overwriting what the workspace changed.
 *
 * Templates are free. A template may name one plugin in `offer`: its guided
 * setup then ends with what the workspace can do free in Whity, what that
 * plugin adds, and a one-click trial of it (the plugin's own
 * {@see PluginAccessInterface} trial; no card).
 *
 * Shipping templates in a plugin means improving one is a plugin release, not
 * a core release. Bump `version` whenever the manifest changes.
 *
 * Every visible string is `{en, ar}`. Example rows use made-up names only
 * (Example Bakery, the Whity team), are marked as examples, and are removed in
 * one click. The shape is checked by {@see Manifest\WorkspaceTemplateValidator};
 * a template that fails is dropped with a logged reason and the plugin keeps
 * loading.
 */
interface PluginWorkspaceTemplatesInterface
{
    /**
     * @return list<array<string, mixed>> the templates' manifests; see
     *     {@see Manifest\WorkspaceTemplateValidator} for the shape
     */
    public function getWorkspaceTemplates(): array;
}
