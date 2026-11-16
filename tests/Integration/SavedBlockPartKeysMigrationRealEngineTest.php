<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Migrations\SavedBlockPartKeys;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\SavedBlock\PerDocumentParts;
use Whity\Core\Document\SavedBlock\SavedBlockExpander;
use Whity\Core\Document\SavedBlock\SavedBlockRepository;
use Whity\Database\Database;

/**
 * Migration 270 (E.23a): saved blocks' per-document parts move from
 * positions (`<block id>#<n>`) to stable keys, on rows shaped as the M7 editor
 * stored them before 3.4. Runs on SQLite by default and on PostgreSQL when
 * PHPUNIT_PG_DSN is set.
 *
 * What matters: every part gets a key on its mark or field, the same key in
 * every version of the block; drafts' overrides move to the keys and keep
 * filling the same parts; rows already on keys are left alone; running it
 * again changes nothing; another workspace's rows are its own; and a value
 * still stored under the old name prints in the right place.
 */
final class SavedBlockPartKeysMigrationRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER = 2;

    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Florist', 'example-florist')");
        $this->db = Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
    }

    public function testLegacyPartsGetStableKeysAndDraftsFollowThem(): void
    {
        // Version 1 had only the signer; version 2 (current) added the date and a witness in a toggle.
        $v1 = [self::line('a', [self::t('Signed by '), self::pd('Amro Saleh')])];
        $v2 = [
            self::line('a', [self::t('Signed by '), self::pd('Amro Saleh'), self::t(' on '), ['type' => 'field', 'path' => 'doc.date', 'label' => 'Date', 'perDocument' => true]]),
            ['id' => 't', 'type' => 'toggle', 'open' => true, 'summary' => [], 'children' => [self::line('b', [self::pd('Witness'), self::pd(', witness')])]],
        ];
        $sign = $this->savedBlock(self::TENANT, $v2, ['a#0', 'a#1', 'b#0'], [1 => $v1, 2 => $v2]);
        $other = $this->savedBlock(self::OTHER, $v1, ['a#0'], [1 => $v1]);

        $filled = [
            'a#0' => [self::t('Awss Haddad')],
            'b#0' => [self::t('Ahmad Khalil')],
            'z#9' => [self::t('a part no version has')],
        ];
        $draft = $this->source(self::TENANT, [
            self::line('p', [self::t('Please sign:')]),
            ['id' => 'c', 'type' => 'columns', 'columns' => [[['id' => 'r1', 'type' => 'savedBlock', 'savedBlockId' => (string) $sign, 'version' => 2, 'overrides' => $filled]], []]],
        ]);
        $foreignDraft = $this->source(self::OTHER, [['id' => 'r9', 'type' => 'savedBlock', 'savedBlockId' => (string) $other, 'version' => 1, 'overrides' => ['a#0' => [self::t('Emad Nasser')]]]]);

        SavedBlockPartKeys::up($this->db);

        $row = $this->row('saved_blocks', $sign);
        $blocks = json_decode($row['blocks'], true);
        $parts = json_decode($row['per_document_parts'], true);
        self::assertCount(3, $parts);
        [$signer, $date, $witness] = array_column($parts, 'key');
        foreach ([$signer, $date, $witness] as $key) {
            self::assertMatchesRegularExpression('/^pd_[A-Za-z0-9_-]{8}$/', $key);
        }
        self::assertSame(['en' => 'Amro Saleh'], $parts[0]['label']);
        self::assertSame('field', $parts[1]['kind']);
        self::assertSame(['en' => 'Witness, witness'], $parts[2]['label'], 'adjacent marked text is one part');
        self::assertSame([$signer, $date, $witness], json_decode($row['per_document_keys'], true));
        self::assertEquals([['type' => 'perDocument', 'key' => $signer]], $blocks[0]['content'][1]['marks']);
        self::assertSame($date, $blocks[0]['content'][3]['perDocument']);
        self::assertSame($witness, $blocks[1]['children'][0]['content'][1]['marks'][0]['key']);

        // Version 1's signer is the same part, so the same key.
        $old = json_decode($this->versionBlocks($sign, 1), true);
        self::assertEquals([['type' => 'perDocument', 'key' => $signer]], $old[0]['content'][1]['marks']);

        // The draft's values now sit under the keys; a name no version has is kept as it was.
        $overrides = json_decode($this->row('document_sources', $draft)['blocks'], true)[1]['columns'][0][0]['overrides'];
        self::assertEquals([$signer => [self::t('Awss Haddad')], $witness => [self::t('Ahmad Khalil')], 'z#9' => [self::t('a part no version has')]], $overrides);

        // Another workspace: its own keys, its own draft.
        $foreignKey = json_decode($this->row('saved_blocks', $other)['per_document_parts'], true)[0]['key'];
        self::assertNotSame($signer, $foreignKey);
        self::assertSame([$foreignKey], array_keys(json_decode($this->row('document_sources', $foreignDraft)['blocks'], true)[0]['overrides']));

        // It prints what the document filled, where it filled it.
        $printed = (new SavedBlockExpander(new SavedBlockRepository($this->pdo)))->expand(self::TENANT, json_decode($this->row('document_sources', $draft)['blocks'], true));
        self::assertSame('Awss Haddad', $printed[1]['columns'][0][0]['content'][1]['text']);
        self::assertSame('Ahmad Khalil', $printed[1]['columns'][0][1]['children'][0]['content'][0]['text']);

        // A second run changes nothing.
        $before = [$this->row('saved_blocks', $sign), $this->versionBlocks($sign, 1), $this->row('document_sources', $draft)];
        SavedBlockPartKeys::up($this->db);
        self::assertSame($before, [$this->row('saved_blocks', $sign), $this->versionBlocks($sign, 1), $this->row('document_sources', $draft)]);
    }

    public function testRowsAlreadyOnKeysAreLeftAlone(): void
    {
        $blocks = [self::line('a', [self::t('Signed by '), ['type' => 'text', 'text' => 'Amro Saleh', 'marks' => [['type' => 'perDocument', 'key' => 'pd_signer01']]]])];
        $sign = $this->savedBlock(self::TENANT, $blocks, ['pd_signer01'], [1 => $blocks]);
        $this->pdo->prepare('UPDATE saved_blocks SET per_document_parts = :p WHERE id = :id')
            ->execute([':p' => json_encode([['key' => 'pd_signer01', 'label' => ['en' => 'Signer', 'ar' => 'الموقّع'], 'kind' => 'text', 'required' => true]]), ':id' => $sign]);
        $draft = $this->source(self::TENANT, [['id' => 'r1', 'type' => 'savedBlock', 'savedBlockId' => (string) $sign, 'version' => 1, 'overrides' => ['pd_signer01' => [self::t('Awss Haddad')]]]]);
        $draftBefore = $this->row('document_sources', $draft);

        SavedBlockPartKeys::up($this->db);

        $parts = json_decode($this->row('saved_blocks', $sign)['per_document_parts'], true);
        self::assertEquals([['key' => 'pd_signer01', 'label' => ['en' => 'Signer', 'ar' => 'الموقّع'], 'kind' => 'text', 'required' => true]], $parts, 'the label the creator gave is kept');
        self::assertEquals($blocks, json_decode($this->row('saved_blocks', $sign)['blocks'], true));
        self::assertSame($draftBefore, $this->row('document_sources', $draft));
    }

    /** A value stored under the old name (a draft the migration didn't reach) still fills its part. */
    public function testAnOverrideUnderTheOldNameStillFillsItsPart(): void
    {
        $blocks = [self::line('a', [self::t('Signed by '), ['type' => 'text', 'text' => 'Amro Saleh', 'marks' => [['type' => 'perDocument', 'key' => 'pd_signer01']]], self::t(' on '), ['type' => 'text', 'text' => '12 November', 'marks' => [['type' => 'perDocument', 'key' => 'pd_date0001']]]])];
        $sign = $this->savedBlock(self::TENANT, $blocks, ['pd_signer01', 'pd_date0001'], [1 => $blocks]);
        $printed = (new SavedBlockExpander(new SavedBlockRepository($this->pdo)))->expand(self::TENANT, [
            ['id' => 'r1', 'type' => 'savedBlock', 'savedBlockId' => (string) $sign, 'version' => 1, 'overrides' => ['a#1' => [self::t('13 November')], 'pd_signer01' => [self::t('Awss Haddad')]]],
        ]);
        self::assertSame('Signed by Awss Haddad on 13 November', implode('', array_column($printed[0]['content'], 'text')));
    }

    public function testKeysAreMintedOncePerLegacyName(): void
    {
        $minted = 0;
        $map = [];
        $mint = static function () use (&$minted): string {
            return 'pd_test000' . $minted++;
        };
        $once = PerDocumentParts::assignKeys([self::line('a', [self::pd('x'), self::t(' '), self::pd('y')])], $map, $mint);
        $again = PerDocumentParts::assignKeys([self::line('a', [self::pd('x')])], $map, $mint);
        self::assertSame(['a#0' => 'pd_test0000', 'a#1' => 'pd_test0001'], $map);
        self::assertSame('pd_test0000', $again[0]['content'][0]['marks'][0]['key'], 'another version reuses the key');
        self::assertSame('pd_test0001', $once[0]['content'][2]['marks'][0]['key']);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * @param list<mixed>              $blocks
     * @param list<string>             $keys
     * @param non-empty-array<int, list<mixed>> $versions
     */
    private function savedBlock(int $tenant, array $blocks, array $keys, array $versions): int
    {
        $this->pdo->prepare(
            "INSERT INTO saved_blocks (tenant_id, name, mode, sharing, blocks, per_document_keys, version, created_at, updated_at)
             VALUES (:t, 'Signature line', 'synced', 'workspace', :b, :k, :v, NOW(), NOW())"
        )->execute([':t' => $tenant, ':b' => json_encode($blocks), ':k' => json_encode($keys), ':v' => max(array_keys($versions))]);
        $id = (int) $this->pdo->lastInsertId();
        foreach ($versions as $version => $vb) {
            $this->pdo->prepare('INSERT INTO saved_block_versions (tenant_id, saved_block_id, version, blocks, created_at) VALUES (:t, :id, :v, :b, NOW())')
                ->execute([':t' => $tenant, ':id' => $id, ':v' => $version, ':b' => json_encode($vb)]);
        }

        return $id;
    }

    /** @param list<mixed> $blocks */
    private function source(int $tenant, array $blocks): int
    {
        $this->pdo->prepare("INSERT INTO document_sources (tenant_id, kind, title, blocks, created_at, updated_at) VALUES (:t, 'template', 'Payment reminder', :b, NOW(), NOW())")
            ->execute([':t' => $tenant, ':b' => json_encode($blocks)]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, string> */
    private function row(string $table, int $id): array
    {
        $cols = $table === 'saved_blocks' ? 'blocks, per_document_parts, per_document_keys' : 'blocks';
        $stmt = $this->pdo->prepare("SELECT {$cols} FROM {$table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        /** @var array<string, string> $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row;
    }

    private function versionBlocks(int $id, int $version): string
    {
        $stmt = $this->pdo->prepare('SELECT blocks FROM saved_block_versions WHERE saved_block_id = :id AND version = :v');
        $stmt->execute([':id' => $id, ':v' => $version]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @param list<mixed> $content
     * @return array<string, mixed>
     */
    private static function line(string $id, array $content): array
    {
        return ['id' => $id, 'type' => 'paragraph', 'content' => $content];
    }

    /** @return array{type: string, text: string} */
    private static function t(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /** @return array<string, mixed> */
    private static function pd(string $text): array
    {
        return ['type' => 'text', 'text' => $text, 'marks' => [['type' => 'perDocument']]];
    }
}
