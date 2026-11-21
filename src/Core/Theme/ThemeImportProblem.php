<?php

declare(strict_types=1);

namespace Whity\Core\Theme;

/**
 * Why something pasted could not become a theme, written for the person who
 * pasted it (ThemeImporter). The text is ours, never a library's or the
 * database's, so the API may show it.
 */
final class ThemeImportProblem extends \InvalidArgumentException
{
    public function reason(): string
    {
        return $this->getMessage();
    }
}
