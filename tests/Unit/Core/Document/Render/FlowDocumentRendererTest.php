<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\Render;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeRenderServiceClient;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\Render\DocumentRenderRejectedException;
use Whity\Core\Document\Render\FlowDocumentRenderer;
use Whity\Core\Document\Render\RenderReadiness;
use Whity\Core\Document\Render\RenderServiceClient;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Sdk\Render\FlowDocument;

/**
 * The tenant-policy layer in front of the flowing render mode (#1072).
 *
 * The division this file exists to hold: the RENDER SERVICE owns what a valid
 * document is, and this owns how much of one a given tenant may render. So
 * every test here is about a ceiling, a setting, or the boundary between the
 * two — and none is about block types, heading levels or figure sources, which
 * are deliberately not re-implemented in PHP.
 *
 * The ceilings are exercised through a fake client, so a breach is proven by
 * the service NEVER BEING CALLED. That is the assertion that matters: a ceiling
 * which rejects after the round trip has already spent the render it was meant
 * to prevent.
 */
final class FlowDocumentRendererTest extends TestCase
{
    private const TENANT = 7;

    /** A second tenant, so "this ceiling is not that tenant's" is provable. */
    private const OTHER_TENANT = 999;

    private SettingsService $settings;
    private FakeRenderServiceClient $client;
    private FlowDocumentRenderer $renderer;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        // The tenants have to EXIST. `tenant_settings.tenant_id` carries a
        // foreign key that PostgreSQL enforces and SQLite (which does not turn
        // on `PRAGMA foreign_keys` by default) does not — so a test that writes
        // a per-tenant override against an id nobody created passes on the
        // SQLite shards and fails on the real-engine ones. Both ids this file
        // uses are seeded, including the second one, whose whole job is to
        // prove a ceiling does NOT leak across tenants.
        foreach ([self::TENANT, self::OTHER_TENANT] as $id) {
            $pdo->exec(
                "INSERT INTO tenants (id, name, slug) VALUES ({$id}, 'tenant-{$id}', 'tenant-{$id}')"
                . ' ON CONFLICT DO NOTHING'
            );
        }

        $this->settings = new SettingsService(
            new GlobalSettingsRepository($pdo),
            new TenantSettingsRepository($pdo)
        );
        $this->client = new FakeRenderServiceClient();
        $this->renderer = new FlowDocumentRenderer($this->settings, $this->client);
    }

    public function testRenderingIsOnWhenAReadyRendererIsConfiguredAndTheSwitchCanTurnItOff(): void
    {
        // WHIT-1001: running a ready render service is the opt-in. The setting
        // defaults on, and stays the operator's way to switch printing off.
        self::assertTrue($this->renderer->isEnabled(self::TENANT));
        self::assertNull($this->renderer->unavailableReason(self::TENANT));

        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'false');

        self::assertFalse($this->renderer->isEnabled(self::TENANT));
        self::assertSame(FlowDocumentRenderer::SWITCHED_OFF, $this->renderer->unavailableReason(self::TENANT));
    }

    public function testAnInstallWithNoRendererStaysClosedAndSaysHowToSetItUp(): void
    {
        // The default turned on in WHIT-1001 must change nothing for an
        // install with no renderer: no URL or no secret means no print, and no
        // network call is even attempted.
        foreach ([['', ''], ['http://render:8130', ''], ['http://render:8130', 'too-short']] as [$url, $secret]) {
            RenderServiceClient::resetReadinessCache();
            $calls = 0;
            $client = new RenderServiceClient($url, $secret, 30, 1024, function () use (&$calls): ?array {
                $calls++;

                return null;
            });
            $renderer = new FlowDocumentRenderer($this->settings, $client);

            self::assertFalse($renderer->isEnabled(self::TENANT));
            self::assertSame('Document rendering is not set up on this instance', $renderer->unavailableReason(self::TENANT));
            self::assertStringContainsString('RENDER_S', $renderer->readiness()->fix);
            self::assertSame(0, $calls);
        }
        RenderServiceClient::resetReadinessCache();
    }

    public function testAVersionMismatchedRendererIsNotARendererAndSaysSo(): void
    {
        // A render image at another release prints that release's layout
        // without a word; refusing, with both versions named, is the fix.
        $this->client->readiness = RenderReadiness::versionMismatch('0.2.27', '0.2.26');

        self::assertFalse($this->renderer->isEnabled(self::TENANT));
        self::assertSame(
            'The document renderer is version 0.2.26 but this app is 0.2.27, so printing is paused until they match',
            $this->renderer->unavailableReason(self::TENANT)
        );
    }

    public function testAnUnreachableRendererNamesTheFaultWithoutTheAddress(): void
    {
        $this->client->readiness = RenderReadiness::unreachable('0.2.27', 'http://render:8130');

        $reason = (string) $this->renderer->unavailableReason(self::TENANT);
        self::assertSame('The document renderer is not reachable', $reason);
        self::assertStringNotContainsString('render:8130', $reason);
        // The operator's fix carries it, for the admin screen and render:check.
        self::assertStringContainsString('http://render:8130', $this->renderer->readiness()->fix);
    }

    public function testAnUnconfiguredClientIsNotEnabledEvenWhenTheSettingIsOn(): void
    {
        // Two different failures with one visible outcome: the setting is an
        // operator's DECISION, an unconfigured client is a deployment MISTAKE
        // (typically a shared secret under the 32-character minimum). A caller
        // only needs to know it cannot render; conflating them here would mean
        // an instance that believes rendering is on and fails every attempt.
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        self::assertTrue($this->renderer->isEnabled(self::TENANT));

        $this->client->configured = false;

        self::assertFalse($this->renderer->isEnabled(self::TENANT));
    }

    public function testPassesTheContentTreeThroughUntouched(): void
    {
        // Core adds no fields, rewrites no text and reorders nothing. A tree
        // that arrived is the tree that renders — anything else would make the
        // service's field-naming 422s point at indexes the caller never wrote.
        $payload = FlowDocument::create()->heading(1, 'A')->paragraph('B')->toPayload();

        $this->renderer->render(self::TENANT, $payload);

        self::assertCount(1, $this->client->flowCalls);
        self::assertSame($payload, $this->client->flowCalls[0]);
    }

    public function testReportsThePageCountsTheRendererCameBackWith(): void
    {
        // The whole reason the flowing mode has a result type: a caller cannot
        // work these out for itself, by definition.
        $this->client->flowPageCount = 42;
        $this->client->flowFrontMatterPages = 3;

        $rendered = $this->renderer->render(self::TENANT, $this->payload());

        self::assertSame(42, $rendered->pageCount);
        self::assertSame(3, $rendered->frontMatterPages);
    }

    public function testRefusesTooManyBlocksWithoutCallingTheService(): void
    {
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_FLOW_MAX_BLOCKS, '3');

        $document = FlowDocument::create();
        for ($i = 0; $i < 4; $i++) {
            $document->paragraph('block ' . $i);
        }

        try {
            $this->renderer->render(self::TENANT, $document->toPayload());
            self::fail('Expected the block ceiling to refuse this document');
        } catch (DocumentRenderRejectedException $e) {
            // The number is NAMED, because it is tenant-configurable and
            // therefore not knowable from outside.
            self::assertStringContainsString('(4, max 3)', $e->clientMessage);
        }

        self::assertSame([], $this->client->calls, 'A refused render must not reach the service');
    }

    public function testTheCeilingIsPerTenant(): void
    {
        // The entire point of these keys. The service enforces one hard number
        // for everybody and has no idea tenants exist; a tenant issuing
        // hundred-page submissions and one printing two-page receipts should
        // not be held to the same limit.
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_FLOW_MAX_BLOCKS, '1');

        $payload = FlowDocument::create()->paragraph('a')->paragraph('b')->toPayload();

        // Another tenant keeps the registry default and renders it happily.
        $this->renderer->render(self::OTHER_TENANT, $payload);
        self::assertCount(1, $this->client->flowCalls);

        $this->expectException(DocumentRenderRejectedException::class);
        $this->renderer->render(self::TENANT, $payload);
    }

    public function testMeasuresTheLargestTableRatherThanTheirTotal(): void
    {
        // A document of fifty ten-row tables is ordinary; a single
        // fifty-thousand-row table is not. A summed ceiling would refuse the
        // first in order to catch the second.
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_FLOW_MAX_TABLE_ROWS, '5');

        $rows = static fn (int $n): array => array_map(static fn (int $i): array => [(string) $i], range(1, $n));

        // Four tables of four rows: sixteen rows in total, none over the limit.
        $withinLimit = FlowDocument::create();
        for ($i = 0; $i < 4; $i++) {
            $withinLimit->table(['n'], $rows(4));
        }
        $this->renderer->render(self::TENANT, $withinLimit->toPayload());
        self::assertCount(1, $this->client->flowCalls);

        $overLimit = FlowDocument::create()->table(['n'], $rows(6))->toPayload();

        $this->expectException(DocumentRenderRejectedException::class);
        $this->expectExceptionMessage('(6, max 5)');
        $this->renderer->render(self::TENANT, $overLimit);
    }

    public function testMeasuresTheEncodedPayloadBecauseFiguresAreTheWeight(): void
    {
        // Estimating from the tree would be guessing at the number the limit is
        // actually about: one embedded scan can be most of a document's bytes,
        // and it is a single block.
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_FLOW_MAX_BYTES, '1024');

        $payload = FlowDocument::create()
            ->figure('data:image/png;base64,' . str_repeat('A', 4096))
            ->toPayload();

        self::assertSame(1, count($payload['content']), 'One block — a block ceiling would not catch this');

        $this->expectException(DocumentRenderRejectedException::class);
        $this->expectExceptionMessage('exceeds the maximum render size');
        $this->renderer->render(self::TENANT, $payload);
    }

    public function testRefusesAPayloadWithNoContentRatherThanCountingItAsZero(): void
    {
        // The one shape check made here: every ceiling is a statement about
        // `content`, and a missing one would otherwise pass them all as "0
        // blocks, within the limit" — a pass, for a payload that cannot render.
        $this->expectException(DocumentRenderRejectedException::class);
        $this->expectExceptionMessage('at least one block of content');

        $this->renderer->render(self::TENANT, ['page' => ['preset' => 'a4']]);
    }

    public function testRelaysTheServicesOwnRefusalRatherThanReplacingIt(): void
    {
        // The service names the offending field; core cannot reconstruct that
        // sentence and does not try. This is the load-bearing half of the
        // decision not to re-implement its validator.
        $this->client->rejectWith = '"content[3].level" must be a whole number 1-6';

        try {
            $this->renderer->render(self::TENANT, $this->payload());
            self::fail('Expected the service rejection to propagate');
        } catch (DocumentRenderRejectedException $e) {
            self::assertSame('"content[3].level" must be a whole number 1-6', $e->clientMessage);
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return FlowDocument::create()->paragraph('content')->toPayload();
    }
}
