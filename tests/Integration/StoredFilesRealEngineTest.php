<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Chat\ChatAttachments;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Storage\StoredFiles;
use Whity\Storage\LocalStorageDriver;
use Whity\Storage\StorageDriverInterface;
use Whity\Storage\StorageException;

/**
 * The one file store (merge map plumbing, WHIT-1159; migration 298): every
 * stored object has one `stored_files` row in its workspace, the owner points
 * at it, the same object recorded twice is one row, and a deleted file's row
 * goes with it.
 *
 * Example Bakery (1) and Example Florist (2).
 */
final class StoredFilesRealEngineTest extends TestCase
{
    private PDO $pdo;
    private string $root;
    private LocalStorageDriver $storage;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Florist', 'florist')");
        $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                          VALUES (10, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->root = sys_get_temp_dir() . '/stored-files-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        $this->storage = new LocalStorageDriver($this->root);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir((string) $f) : unlink((string) $f);
        }
        rmdir($this->root);
    }

    public function testStoreWritesOnceUnderTheWorkspaceWithItsChecksum(): void
    {
        $stored = StoredFiles::store($this->storage, 1, 'documents', '42', '%PDF-1.4 bakery', 'application/pdf', 'pdf');

        self::assertMatchesRegularExpression('#^tenants/1/documents/42/[0-9a-f]{32}\.pdf$#', $stored['storage_key']);
        self::assertSame(hash('sha256', '%PDF-1.4 bakery'), $stored['checksum_sha256']);
        self::assertSame(15, $stored['byte_size']);
        self::assertSame('%PDF-1.4 bakery', $this->storage->get($stored['storage_key']));
    }

    public function testAnArtifactHasItsOwnRowAndTheSameObjectIsOneRow(): void
    {
        $this->pdo->exec("INSERT INTO documents (id, tenant_id, template_name, title, created_by, created_at) VALUES (42, 1, 'Letter', 'Order confirmation', 10, CURRENT_TIMESTAMP)");
        $stored = (new DocumentArtifactStore($this->storage))->put(1, 42, '%PDF-1.4 order', 'application/pdf');
        $rec = ['document_id' => 42, 'storage_key' => $stored->storageKey, 'content_type' => $stored->contentType, 'byte_size' => $stored->byteSize, 'checksum_sha256' => $stored->checksumSha256, 'rendered_by' => 10];

        $first = (new DocumentArtifactRepository($this->pdo))->create(1, $rec);
        $again = StoredFiles::remember($this->pdo, 1, StoredFiles::DOCUMENT_ARTIFACT, $rec, null, 10);

        $row = $this->q("SELECT stored_file_id FROM document_artifacts WHERE id = {$first}")->fetchColumn();
        self::assertSame((int) $row, $again, 'recording the same object again finds its row');
        $file = $this->q('SELECT tenant_id, kind, byte_size, checksum_sha256, created_by_profile_id FROM stored_files')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $file);
        self::assertSame(['1', 'document_artifact', '14', hash('sha256', '%PDF-1.4 order'), '10'], array_map('strval', array_values($file[0])));
    }

    public function testAChatFileIsRecordedAndForgottenWithItsMessage(): void
    {
        $this->pdo->exec("INSERT INTO conversations (id, tenant_id, kind, channel, subject, status, contact_address, last_message_at, created_at, updated_at)
                          VALUES (101, 1, 'team', 'team', 'Ramadan boxes', 'open', '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $chat = new ChatAttachments($this->pdo, $this->storage);
        $png = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . str_repeat("\0", 64);
        $file = $chat->upload(1, 101, 10, $png, 'image/png', 'shelf.png', ['width' => 10, 'height' => 10]);
        self::assertSame(1, (int) $this->q("SELECT COUNT(*) FROM stored_files WHERE kind = 'chat_attachment' AND filename = 'shelf.png'")->fetchColumn());
        self::assertNotNull($this->q('SELECT stored_file_id FROM chat_attachments WHERE id = ' . (int) $file['id'])->fetchColumn());

        $this->pdo->exec('INSERT INTO messages (id, tenant_id, conversation_id, kind, body_text, status, created_at) VALUES (5, 1, 101, \'note\', \'\', \'noted\', CURRENT_TIMESTAMP)');
        $this->pdo->exec('UPDATE chat_attachments SET message_id = 5 WHERE id = ' . (int) $file['id']);
        $chat->deleteForMessage(1, 5);

        self::assertSame(0, (int) $this->q('SELECT COUNT(*) FROM stored_files')->fetchColumn(), 'the file and its row go together');
    }

    public function testAKeyThatIsTakenIsNeverOverwritten(): void
    {
        // A storage where every key already exists, as a broken random source would make it.
        $taken = $this->createStub(StorageDriverInterface::class);
        $taken->method('exists')->willReturn(true);

        $this->expectException(StorageException::class);
        StoredFiles::store($taken, 2, 'forms', '7', 'first', 'text/plain', 'txt');
    }

    public function testTheBackfillLinksExistingRowsAndCanRunAgain(): void
    {
        // A printout and an upload recorded before migration 298, sharing one object.
        $this->pdo->exec("INSERT INTO documents (id, tenant_id, template_name, title, created_by, created_at) VALUES (43, 1, 'Letter', 'Delivery note', 10, CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO document_artifacts (tenant_id, document_id, storage_key, content_type, byte_size, checksum_sha256, rendered_by)
                          VALUES (1, 43, 'tenants/1/documents/43/a.pdf', 'application/pdf', 9, 'abc', 10)");
        $this->pdo->exec("INSERT INTO document_artifacts (tenant_id, document_id, storage_key, content_type, byte_size, checksum_sha256, rendered_by)
                          VALUES (1, 43, 'tenants/1/documents/43/b.pdf', 'application/pdf', 9, 'def', 10)");

        $db = \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $db->forceConnect();
        require_once dirname(__DIR__, 2) . '/database/migrations/298_create_stored_files.php';
        \Database\Migrations\CreateStoredFiles::up($db);
        \Database\Migrations\CreateStoredFiles::up($db);

        self::assertSame(2, (int) $this->q('SELECT COUNT(*) FROM stored_files')->fetchColumn(), 'one row per object, however often it runs');
        self::assertSame(0, (int) $this->q('SELECT COUNT(*) FROM document_artifacts WHERE stored_file_id IS NULL')->fetchColumn());
        self::assertSame('abc', $this->q("SELECT f.checksum_sha256 FROM document_artifacts a JOIN stored_files f ON f.id = a.stored_file_id WHERE a.storage_key LIKE '%a.pdf'")->fetchColumn());
    }

    private function q(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt;
    }
}
