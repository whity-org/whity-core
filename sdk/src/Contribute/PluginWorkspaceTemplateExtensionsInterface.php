<?php

declare(strict_types=1);

namespace Whity\Sdk\Contribute;

/**
 * Optional (SDK 1.170, WHIT-1413; design section 40 §2.2): pieces your
 * plugin adds to another plugin's workspace template.
 *
 * ```php
 * public function getWorkspaceTemplateExtensions(): array
 * {
 *     return [[
 *         'key' => 'patient-file',
 *         'version' => 1,
 *         'target' => 'clinic',
 *         'setsUp' => [['en' => 'A patient file for each visit', 'ar' => 'ملف لكل مريض']],
 *         'forms' => [[...]],
 *     ]];
 * }
 * ```
 *
 * An extension only adds: `setsUp` lines, `services`, `roles`, `forms`,
 * `documents`, `examples` and guided `setup.steps`, in the shape of a
 * template's ({@see \Whity\Sdk\Manifest\WorkspaceTemplateValidator::extensionErrors()}).
 * It applies with its `target` template, and with every template built on it,
 * wherever your plugin is on. An item whose key the template already has is
 * left out; an extension that would make the template invalid is left out
 * whole, and the template is offered without it.
 *
 * Each extension is its own part of the template: bump its `version` when it
 * changes, and a workspace that applied it is offered the update. A plugin
 * may also declare its extensions in `plugin.json` as
 * `"workspaceTemplateExtensions": ["templates/clinic-patient-file.json"]`.
 * A host older than 1.170 ignores this interface.
 */
interface PluginWorkspaceTemplateExtensionsInterface
{
    /**
     * @return list<array<string, mixed>> the extensions
     */
    public function getWorkspaceTemplateExtensions(): array;
}
