<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\Print;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Print\SourceDocumentPrinter;
use Whity\Core\Document\Print\WorkspaceName;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Tests\Support\SchemaFromMigrations;

/**
 * WHIT-999: the letterhead's name. A workspace with no company name and no
 * site name of its own printed "Whity", Whity's default site name, at the top
 * of its invoices; it now prints the workspace's own name.
 */
final class LetterheadNameTest extends TestCase
{
    private PDO $pdo;
    private SettingsService $settings;
    private SourceDocumentPrinter $printer;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->printer = new SourceDocumentPrinter(
            new DocumentFieldRegistry(),
            new DocumentEmbedRegistry(),
            $this->settings,
            workspace: new WorkspaceName($this->pdo),
        );
    }

    public function testNothingSetPrintsTheWorkspaceNameNotWhitysDefault(): void
    {
        self::assertSame('Example Bakery', $this->printer->letterhead(1)['name']);
    }

    public function testTheCompanyNameWins(): void
    {
        $this->settings->setTenant(1, SettingsRegistry::COMPANY_NAME, 'Example Bakery LLC');

        self::assertSame('Example Bakery LLC', $this->printer->letterhead(1)['name']);
    }

    public function testAWhiteLabelSiteNameStillComesBeforeTheWorkspaceName(): void
    {
        $this->settings->setTenant(1, SettingsRegistry::SITE_NAME, 'Bakery Portal');

        self::assertSame('Bakery Portal', $this->printer->letterhead(1)['name']);
    }

    public function testWithoutTheLookupTheSiteNameIsStillTheLastResort(): void
    {
        $printer = new SourceDocumentPrinter(new DocumentFieldRegistry(), new DocumentEmbedRegistry(), $this->settings);

        self::assertSame(SettingsRegistry::defaultFor(SettingsRegistry::SITE_NAME), $printer->letterhead(1)['name']);
    }
}
