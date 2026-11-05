<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

use PDO;
use Throwable;

/**
 * Load the committed catalogues into the translations table when an install
 * upgrades: English and every language with committed files.
 *
 * `migrate run` is the one step every upgrade path already takes, so it runs this
 * and nobody has a further step to forget. Without it, an upgraded install keeps
 * showing English for every label added since it was first seeded, because only
 * the fresh install's migration 121 seeded them: v0.2.25 reached one deployment
 * with 1,273 Arabic labels missing.
 *
 * {@see TranslationSync} does the writing, so what it leaves alone stays alone: a
 * string edited in /admin/translations and every tenant's override.
 */
final class TranslationRefresh
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $baseDir,
    ) {
    }

    /**
     * @return array{languages: int, inserted: int, updated: int, divergent: int}|null
     *     Null when this database has no translations table to load into.
     */
    public function run(): ?array
    {
        if (!$this->hasTranslationTables()) {
            return null;
        }

        $catalog = new TranslationCatalog($this->baseDir);
        $sync    = new TranslationSync($this->pdo);
        $totals  = ['languages' => 0, 'inserted' => 0, 'updated' => 0, 'divergent' => 0];

        $codes = array_unique([TranslationCatalog::SOURCE_LANGUAGE, ...$catalog->localeCodes()]);
        foreach ($codes as $code) {
            $bundle = $code === TranslationCatalog::SOURCE_LANGUAGE ? $catalog->read() : $catalog->readLocale($code);
            if ($bundle === []) {
                continue;
            }

            $report = $sync->sync($bundle, $code);
            $totals['languages']++;
            $totals['inserted']  += count($report['inserted']);
            $totals['updated']   += count($report['updated']);
            $totals['divergent'] += count($report['divergent']);
        }

        return $totals;
    }

    private function hasTranslationTables(): bool
    {
        try {
            // @tenant-guard-ignore: probes that the table exists, and matches no rows.
            $this->pdo->query('SELECT 1 FROM translations WHERE 1 = 0');
            $this->pdo->query('SELECT 1 FROM languages WHERE 1 = 0');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
