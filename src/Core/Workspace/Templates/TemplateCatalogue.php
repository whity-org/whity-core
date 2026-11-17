<?php

declare(strict_types=1);

namespace Whity\Core\Workspace\Templates;

use Whity\Core\PluginLoader;

/**
 * The workspace templates on offer (SDK 1.116, WHIT-1194): core's own
 * "Blank", then those of the plugins on in this workspace. A plugin cannot
 * replace Blank.
 */
final class TemplateCatalogue
{
    public const BLANK = 'blank';

    /** @param (\Closure(): list<array<string, mixed>>)|null $plugins for tests */
    public function __construct(
        private readonly ?PluginLoader $loader,
        private readonly ?\Closure $plugins = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $fromPlugins = $this->plugins !== null ? ($this->plugins)() : ($this->loader?->getWorkspaceTemplates() ?? []);
        $out = [];
        foreach ($fromPlugins as $template) {
            if (($template['key'] ?? null) !== self::BLANK) {
                $out[] = $template;
            }
        }
        $out[] = self::blank();

        return $out;
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
            'icon' => 'square-dashed',
            'setsUp' => [
                ['en' => 'Nothing set up yet', 'ar' => 'لا شيء معدّ بعد'],
                ['en' => 'Every free part of Whity, ready to turn on', 'ar' => 'كل أجزاء Whity المجانية جاهزة للتشغيل'],
            ],
        ];
    }
}
