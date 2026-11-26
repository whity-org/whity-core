<?php

declare(strict_types=1);

namespace Whity\Core\Workspace\Templates;

use Whity\Core\PluginLoader;
use Whity\Sdk\Templates\TemplateComposer;

/**
 * The workspace templates on offer (SDK 1.116, WHIT-1194): those of the
 * plugins on in this workspace, then core's own "Blank". A plugin cannot
 * replace Blank.
 *
 * Since WHIT-1433 they also include the templates of installed plugins that
 * are off in this workspace but may be turned on there, marked `pluginOn:
 * false`: a template plugin is on only where it is used (owner, 26 Nov
 * 2026), and choosing its template turns it on.
 *
 * Since SDK 1.170 (WHIT-1413) they are composed first
 * ({@see TemplateComposer}): a template that `extends` another is merged
 * with it, and the extensions of the plugins on here are added. Each composed
 * template lists its `parts` with their versions.
 */
final class TemplateCatalogue
{
    public const BLANK = 'blank';

    /** @var list<string> */
    private array $notes = [];

    /**
     * @param (\Closure(): list<array<string, mixed>>)|null $plugins for tests
     * @param (\Closure(): list<array<string, mixed>>)|null $extensions for tests
     */
    public function __construct(
        private readonly ?PluginLoader $loader,
        private readonly ?\Closure $plugins = null,
        private readonly ?\Closure $extensions = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        // WHIT-1433: also the templates of plugins off here that the workspace
        // may turn on; choosing one turns its plugin on (`pluginOn`).
        $fromPlugins = $this->plugins !== null ? ($this->plugins)() : ($this->loader?->getOfferableWorkspaceTemplates() ?? []);
        $extensions = $this->extensions !== null ? ($this->extensions)() : ($this->loader?->getWorkspaceTemplateExtensions() ?? []);
        $templates = array_values(array_filter($fromPlugins, static fn (array $t): bool => ($t['key'] ?? null) !== self::BLANK));
        $on = [];
        foreach ($templates as $i => $template) {
            $on[(string) $template['key']] = ($template['pluginOn'] ?? true) !== false;
            unset($templates[$i]['pluginOn']);
        }
        $composed = TemplateComposer::compose($templates, $extensions);
        $this->notes = $composed['notes'];
        $out = [];
        foreach ($composed['templates'] as $template) {
            $out[] = $template + ['pluginOn' => $on[(string) $template['key']] ?? true];
        }
        $out[] = self::blank();

        return $out;
    }

    /**
     * What composing left out the last time {@see self::all()} ran, and why.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        foreach ($this->all() as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Start with nothing set up, as every workspace did before templates.
     *
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return [
            'key' => self::BLANK,
            'version' => 1,
            'name' => ['en' => 'Start blank', 'ar' => 'ابدأ فارغاً'],
            'summary' => [
                'en' => 'An empty workspace. Add what you need when you need it.',
                'ar' => 'مساحة عمل فارغة. أضف ما تحتاجه عندما تحتاجه.',
            ],
            'icon' => 'square',
            'setsUp' => [
                ['en' => 'Nothing set up yet', 'ar' => 'لا شيء معدّ بعد'],
                ['en' => 'Every free part of Whity, ready to turn on', 'ar' => 'كل أجزاء Whity المجانية جاهزة للتشغيل'],
            ],
        ];
    }
}
