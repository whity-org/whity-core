<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\MeHomeApiHandler;
use Whity\Core\Request;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Tenant\TenantContext;

/**
 * Your recent documents and Finish setting up (WHIT-872, section 20): the caller's
 * own rows in their own workspace, and steps from this workspace's facts alone.
 */
final class MeHomeApiRealEngineTest extends TestCase
{
    private const RANA = 101;
    private const OMAR = 202;
    private const BAKERY = 1;
    private const PHARMACY = 2;

    private PDO $pdo;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Pharmacy', 'pharmacy')");
        foreach ([self::RANA => 'Rana Haddad', self::OMAR => 'Omar Khalil'] as $id => $name) {
            $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                                 VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
        }
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testRecentDocumentsAreTheCallersOwnInThisWorkspaceThisWeek(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->document(self::BAKERY, self::RANA, 'mine', $now);
        $this->document(self::BAKERY, self::RANA, 'last month', gmdate('Y-m-d H:i:s', time() - 30 * 86400));
        $this->document(self::BAKERY, self::OMAR, 'a colleague\'s', $now);
        $this->document(self::PHARMACY, self::RANA, 'my other workspace', $now);

        self::assertSame(['mine'], array_column($this->recent(self::BAKERY, self::RANA), 'title'));
    }

    public function testADocumentSaysWhoItIsWithAndADraftSaysDraft(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $routed = $this->document(self::BAKERY, self::RANA, 'routed', $now);
        $this->waitingWith($routed, self::OMAR);
        $this->document(self::BAKERY, self::RANA, 'issued', $now);
        $this->draft(self::BAKERY, self::RANA, 'unpublished', null);
        $this->draft(self::BAKERY, self::RANA, 'published', 1);
        $this->draft(self::PHARMACY, self::RANA, 'other workspace', null);

        $rows = array_column($this->recent(self::BAKERY, self::RANA), null, 'title');

        self::assertCount(3, $rows);
        self::assertSame('with', $rows['routed']['status']);
        self::assertSame('Omar Khalil', $rows['routed']['waitingWith']);
        self::assertSame('issued', $rows['issued']['status']);
        self::assertSame('draft', $rows['unpublished']['status']);
        self::assertSame('draft', $rows['unpublished']['kind']);
    }

    public function testRecentDocumentsNeedASession(): void
    {
        TenantContext::setTenantId(self::BAKERY);

        self::assertSame(401, (new MeHomeApiHandler($this->pdo))->recentDocuments(new Request('GET', '/api/me/recent-documents'))->getStatusCode());
    }

    public function testStepsReadThisWorkspacesFactsOnly(): void
    {
        $this->setting(self::PHARMACY, SettingsRegistry::COMPANY_TAX_ID, '"123"');
        $this->member(self::PHARMACY, self::RANA);
        $this->member(self::PHARMACY, self::OMAR);

        $body = $this->steps(self::BAKERY);

        self::assertSame(['branding', 'team', 'agent', 'tax'], array_column($body['steps'], 'key'));
        self::assertSame(0, $body['done']);
        self::assertSame(4, $body['total']);
    }

    public function testAStepIsDoneOnceItsFactIsTrue(): void
    {
        $this->setting(self::BAKERY, SettingsRegistry::COMPANY_TAX_ID, '"123"');
        $this->setting(self::BAKERY, SettingsRegistry::BRANDING_MAIN_COLOR, '""');
        $this->member(self::BAKERY, self::RANA);
        $this->member(self::BAKERY, self::OMAR);

        $done = array_column($this->steps(self::BAKERY)['steps'], 'done', 'key');

        self::assertTrue($done['tax']);
        self::assertTrue($done['team']);
        self::assertFalse($done['branding'], 'An emptied setting is not done.');
        self::assertFalse($done['agent']);
    }

    public function testAPlanStepAppliesOnlyWhenThereArePlansToChoose(): void
    {
        $this->pdo->exec("INSERT INTO plans (id, plan_key, name, is_active) VALUES (5, 'starter', '[plan name]', true)");
        self::assertSame(['key' => 'plan', 'done' => false], $this->steps(self::BAKERY)['steps'][4]);

        $this->pdo->exec("INSERT INTO tenant_plan (tenant_id, plan_id) VALUES (1, 5)");
        self::assertSame(['key' => 'plan', 'done' => true], $this->steps(self::BAKERY)['steps'][4]);
        self::assertFalse($this->steps(self::PHARMACY)['steps'][4]['done'], 'Another workspace\'s plan does not count.');
    }

    public function testTheSystemTenantHasNoSteps(): void
    {
        self::assertSame(['steps' => [], 'done' => 0, 'total' => 0], $this->steps(0));
    }

    /** @return list<array<string, mixed>> */
    private function recent(int $tenantId, int $profileId): array
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $request = new Request('GET', '/api/me/recent-documents');
        $request->user = (object) ['profile_id' => $profileId];
        $response = (new MeHomeApiHandler($this->pdo))->recentDocuments($request);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        return json_decode($response->getBody(), true)['data'];
    }

    /** @return array{steps: list<array{key: string, done: bool}>, done: int, total: int} */
    private function steps(int $tenantId): array
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $response = (new MeHomeApiHandler($this->pdo))->setupSteps(new Request('GET', '/api/me/setup-steps'));
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        return json_decode($response->getBody(), true)['data'];
    }

    private function document(int $tenantId, int $profileId, string $title, string $createdAt): int
    {
        $this->pdo->prepare("INSERT INTO documents (tenant_id, template_name, title, created_by, created_at) VALUES (?, 't', ?, ?, ?)")
            ->execute([$tenantId, $title, $profileId, $createdAt]);

        return (int) $this->pdo->lastInsertId();
    }

    private function waitingWith(int $documentId, int $profileId): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->prepare('INSERT INTO document_route_recipients (tenant_id, document_id, route_id, step_id, profile_id, created_by_event_id) VALUES (1, ?, 1, 1, ?, 1)')
            ->execute([$documentId, $profileId]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    private function draft(int $tenantId, int $profileId, string $title, ?int $published): void
    {
        $this->pdo->prepare("INSERT INTO document_sources (tenant_id, kind, title, blocks, published_version, created_by, updated_at) VALUES (?, 'note', ?, '[]', ?, ?, ?)")
            ->execute([$tenantId, $title, $published, $profileId, gmdate('Y-m-d H:i:s')]);
    }

    private function setting(int $tenantId, string $key, string $value): void
    {
        $this->pdo->prepare('INSERT INTO tenant_settings (tenant_id, setting_key, value) VALUES (?, ?, ?)')->execute([$tenantId, $key, $value]);
    }

    private function member(int $tenantId, int $profileId): void
    {
        $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES (?, ?, 1, 'active', CURRENT_TIMESTAMP)")
            ->execute([$profileId, $tenantId]);
    }
}
