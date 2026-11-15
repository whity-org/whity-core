<?php

declare(strict_types=1);

namespace Tests\Integration\Chat;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Chat\ChatAttachmentPolicy;
use Whity\Core\Chat\ChatAttachments;
use Whity\Core\Chat\ChatException;
use Whity\Core\Chat\ChatRepository;
use Whity\Core\Chat\ChatService;
use Whity\Core\Chat\ChatViewer;
use Whity\Storage\LocalStorageDriver;

/**
 * Files, photos and voice in Chat on the real schema (design 3.5, UC.10,
 * WHIT-1095; migration 283): the type comes from the bytes, a file joins only
 * its uploader's next message in that chat and only once, it is read back only
 * by people in the chat, a voice message keeps its words, and deleting the
 * message for everyone takes its files with it.
 */
final class ChatAttachmentsRealEngineTest extends TestCase
{
    private const A = 1;
    private const AMRO = 10;
    private const AWSS = 11;
    private const EMAD = 12;
    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR";

    private PDO $pdo;
    private ChatService $chat;
    private string $root;
    private int $group = 0;
    private int $other = 0;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();
        $this->root = sys_get_temp_dir() . '/chat-files-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        $this->chat = new ChatService(new ChatRepository($this->pdo), null, null, new ChatAttachments($this->pdo, new LocalStorageDriver($this->root)));
        $this->group = (int) $this->chat->createGroup(self::A, ChatViewer::person(self::AMRO), 'Abdali team', [self::AWSS], null);
        $this->other = (int) $this->chat->createGroup(self::A, ChatViewer::person(self::EMAD), 'Payroll', [], null);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir((string) $f) : unlink((string) $f);
        }
        rmdir($this->root);
    }

    public function testTheTypeComesFromTheBytesNotTheName(): void
    {
        self::assertSame('image/png', ChatAttachmentPolicy::check(self::PNG, 'application/pdf', 'box.pdf')['content_type']);
        self::assertSame('file', ChatAttachmentPolicy::check("%PDF-1.4\n", null, 'price.pdf')['kind']);
        self::assertSame('voice', ChatAttachmentPolicy::check("OggS\0\2", 'audio/ogg', 'v.ogg')['kind']);
        self::assertSame('audio/webm', ChatAttachmentPolicy::check("\x1a\x45\xdf\xa3rest", 'audio/webm;codecs=opus', 'v.webm')['content_type']);
        self::assertSame('video/webm', ChatAttachmentPolicy::check("\x1a\x45\xdf\xa3rest", 'video/webm', 'v.webm')['content_type']);

        foreach ([['<html><script>x</script>', 'page.txt'], ['<svg onload=x>', 'a.svg'], ["MZ\x90\0", 'tool.exe'], ['', 'empty.png']] as [$bytes, $name]) {
            try {
                ChatAttachmentPolicy::check($bytes, null, $name);
                self::fail("{$name} should be refused");
            } catch (ChatException $e) {
                self::assertSame(422, $e->status());
            }
        }
    }

    public function testAFileJoinsItsUploadersNextMessageInThatChatOnce(): void
    {
        $amro = ChatViewer::person(self::AMRO);
        $photo = $this->chat->upload(self::A, $amro, $this->group, self::PNG, 'image/png', 'box.png', ['width' => 1200, 'height' => 900]);
        self::assertNull($photo['message_id']);

        // Awss cannot send Amro's upload, and Amro cannot send it in another chat.
        $this->refused(fn () => $this->chat->post(self::A, ChatViewer::person(self::AWSS), $this->group, '', [], 'message', null, null, false, [$photo['id']]));

        $sent = $this->chat->post(self::A, $amro, $this->group, '', [], 'message', null, null, false, [$photo['id']]);
        self::assertSame('', $sent['body']);
        self::assertSame([[$photo['id'], 'image', 1200, 900]], array_map(static fn (array $a): array => [$a['id'], $a['kind'], $a['width'], $a['height']], $sent['attachments']));

        // Once sent, it can't go on a second message.
        $this->refused(fn () => $this->chat->post(self::A, $amro, $this->group, 'again', [], 'message', null, null, false, [$photo['id']]));
    }

    public function testOnlyPeopleInTheChatReadAFile(): void
    {
        $amro = ChatViewer::person(self::AMRO);
        $pdf = $this->chat->upload(self::A, $amro, $this->group, "%PDF-1.4\nprices", 'application/pdf', 'Price list 2027.pdf', []);

        // Before it is sent, only its uploader reads it.
        self::assertSame("%PDF-1.4\nprices", $this->chat->file(self::A, $amro, $pdf['id'])['bytes']);
        $this->refused(fn () => $this->chat->file(self::A, ChatViewer::person(self::AWSS), $pdf['id']), 404);

        $this->chat->post(self::A, $amro, $this->group, 'The new prices', [], 'message', null, null, false, [$pdf['id']]);
        $read = $this->chat->file(self::A, ChatViewer::person(self::AWSS), $pdf['id']);
        self::assertSame('application/pdf', $read['content_type']);
        self::assertSame('Price list 2027.pdf', $read['filename']);
        self::assertTrue($read['inline']);
        $this->refused(fn () => $this->chat->file(self::A, ChatViewer::person(self::EMAD), $pdf['id']), 404);

        self::assertSame([$pdf['id']], array_column($this->chat->media(self::A, ChatViewer::person(self::AWSS), $this->group, 'files'), 'id'));
        self::assertSame([], $this->chat->media(self::A, ChatViewer::person(self::AWSS), $this->group, 'photos'));
    }

    public function testAVoiceMessageKeepsItsLengthAndWords(): void
    {
        $awss = ChatViewer::person(self::AWSS);
        $voice = $this->chat->upload(self::A, $awss, $this->group, "OggS\0\2voice", 'audio/ogg', 'voice.ogg', ['duration_ms' => 18000]);
        $this->chat->transcribe(self::A, $awss, $voice['id'], 'We have six left for tomorrow.');
        // Only its sender sets the words, and only before it goes.
        $this->refused(fn () => $this->chat->transcribe(self::A, ChatViewer::person(self::AMRO), $voice['id'], 'changed'), 404);

        $sent = $this->chat->post(self::A, $awss, $this->group, '', [], 'message', null, null, false, [$voice['id']]);
        self::assertSame(18000, $sent['attachments'][0]['duration_ms']);
        self::assertSame('We have six left for tomorrow.', $sent['attachments'][0]['transcript']);
        $this->refused(fn () => $this->chat->transcribe(self::A, $awss, $voice['id'], 'later'), 404);

        $this->refused(fn () => $this->chat->upload(self::A, $awss, $this->group, "OggS\0\2long", 'audio/ogg', 'long.ogg', ['duration_ms' => 6 * 60 * 1000]));
    }

    public function testDeletingTheMessageForEveryoneTakesItsFiles(): void
    {
        $amro = ChatViewer::person(self::AMRO);
        $photo = $this->chat->upload(self::A, $amro, $this->group, self::PNG, 'image/png', 'box.png', []);
        $sent = $this->chat->post(self::A, $amro, $this->group, 'This box', [], 'message', null, null, false, [$photo['id']]);

        $deleted = $this->chat->delete(self::A, $amro, $sent['id']);
        self::assertSame([], $deleted['attachments']);
        $this->refused(fn () => $this->chat->file(self::A, $amro, $photo['id']), 404);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM chat_attachments')->fetchColumn());
    }

    public function testAnAgentSendsNoFiles(): void
    {
        $agent = ChatViewer::agent(1, self::AMRO, 'Mira');
        $this->refused(fn () => $this->chat->upload(self::A, $agent, $this->group, self::PNG, 'image/png', 'x.png', []), 403);
    }

    private function refused(callable $fn, int $status = 422): void
    {
        try {
            $fn();
            self::fail('expected a refusal');
        } catch (ChatException $e) {
            self::assertSame($status, $e->status(), $e->plain());
        }
    }

    private function seed(): void
    {
        $pdo = $this->pdo;
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec('INSERT INTO tenants (id, name, slug) VALUES (' . self::A . ", 'Example Bakery', 'bakery')");
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'user' AND tenant_id IS NULL");
        $stmt->execute();
        $role = (int) $stmt->fetchColumn();
        foreach ([self::AMRO => 'Amro Saleh', self::AWSS => 'Awss Haddad', self::EMAD => 'Emad Nasser'] as $id => $name) {
            $pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                        VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$id}, " . self::A . ", {$role}, 'active', 'member', CURRENT_TIMESTAMP)");
        }
    }
}
