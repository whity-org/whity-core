<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

use PDO;
use Whity\Storage\StorageDriverInterface;
use Whity\Storage\StorageKey;

/**
 * Files, photos and voice in Chat (design 3.5, UC.10, WHIT-1095).
 *
 * A file is uploaded to a chat before its message is sent (so the person sees
 * progress, and a failed send keeps it), then joins the message when it is
 * sent. Only the person who uploaded it can send it, only in that chat, and
 * only once.
 *
 * Bytes live on the workspace's storage (the platform's tenant-routing driver,
 * like form uploads and documents) under a fresh random name, never
 * overwritten; their type is the sniffed one ({@see ChatAttachmentPolicy}).
 * Reading one back needs membership of its chat.
 */
final class ChatAttachments
{
    public const STORAGE_SEGMENT = 'chat';

    private const COLUMNS = 'a.id, a.conversation_id, a.message_id, a.uploaded_by_profile_id, a.kind, f.storage_key, f.content_type,
        f.byte_size, f.filename, a.width, a.height, a.duration_ms, a.transcript, a.created_at';

    /** The file's details live in `stored_files` (WHIT-1159, migration 299). */
    private const FROM = 'chat_attachments a JOIN stored_files f ON f.id = a.stored_file_id AND f.tenant_id = a.tenant_id';

    public function __construct(
        private readonly PDO $db,
        private readonly StorageDriverInterface $storage,
    ) {
    }

    /**
     * Store a file for a chat, not yet on a message.
     *
     * @param array{duration_ms?: ?int, transcript?: ?string, width?: ?int, height?: ?int} $about
     * @return array<string, mixed> the presented attachment
     *
     * @throws ChatException
     */
    public function upload(int $tenantId, int $chatId, int $profileId, string $bytes, ?string $declaredType, ?string $filename, array $about = []): array
    {
        $checked = ChatAttachmentPolicy::check($bytes, $declaredType, $filename);
        $kind = $checked['kind'];
        $duration = isset($about['duration_ms']) ? max(0, (int) $about['duration_ms']) : null;
        if ($kind === 'voice' && $duration !== null && $duration > ChatAttachmentPolicy::MAX_VOICE_MS + 2000) {
            throw new ChatException('Voice messages can be up to 5 minutes long.');
        }
        $transcript = isset($about['transcript']) ? trim(mb_substr((string) $about['transcript'], 0, 4000)) : null;

        // The one file store (WHIT-1159).
        try {
            $stored = \Whity\Core\Storage\StoredFiles::store($this->storage, $tenantId, self::STORAGE_SEGMENT, (string) $chatId, $bytes, $checked['content_type'], $checked['ext']);
        } catch (\Whity\Storage\StorageException) {
            throw new ChatException('The file could not be stored. Please try again.', 503);
        }
        $storedFileId = \Whity\Core\Storage\StoredFiles::remember(
            $this->db,
            $tenantId,
            \Whity\Core\Storage\StoredFiles::CHAT_ATTACHMENT,
            $stored,
            self::safeFilename($filename, $checked['ext']),
            $profileId,
        );

        $stmt = $this->db->prepare('INSERT INTO chat_attachments (tenant_id, conversation_id, uploaded_by_profile_id, kind,
                width, height, duration_ms, transcript, stored_file_id, created_at)
             VALUES (:tid, :cid, :pid, :kind, :w, :h, :ms, :text, :stored, NOW())
             RETURNING id');
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $chatId, PDO::PARAM_INT);
        $stmt->bindValue(':pid', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':kind', $kind);
        foreach ([':w' => $about['width'] ?? null, ':h' => $about['height'] ?? null, ':ms' => $kind === 'voice' || $kind === 'video' ? $duration : null] as $k => $v) {
            $stmt->bindValue($k, $v === null ? null : max(0, min((int) $v, 2_000_000_000)), $v === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        }
        $stmt->bindValue(':text', $kind === 'voice' && $transcript !== '' ? $transcript : null, $kind === 'voice' && $transcript !== null && $transcript !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':stored', $storedFileId, PDO::PARAM_INT);
        $stmt->execute();
        $id = (int) $stmt->fetchColumn();

        return self::present($this->row($tenantId, $id) ?? []);
    }

    /**
     * Check that these uploads can go on a new message in this chat: the
     * person's own, in this chat, not sent yet.
     *
     * @param list<int> $ids
     *
     * @throws ChatException
     */
    public function assertSendable(int $tenantId, int $chatId, int $profileId, array $ids): void
    {
        if (count($ids) > ChatAttachmentPolicy::MAX_PER_MESSAGE) {
            throw new ChatException('Up to ' . ChatAttachmentPolicy::MAX_PER_MESSAGE . ' files go on one message.');
        }
        foreach ($ids as $id) {
            $row = $this->row($tenantId, $id);
            if ($row === null || (int) $row['conversation_id'] !== $chatId || (int) ($row['uploaded_by_profile_id'] ?? 0) !== $profileId || $row['message_id'] !== null) {
                throw new ChatException('A file on this message is not one you uploaded here. Add it again.');
            }
        }
    }

    /** @param list<int> $ids */
    public function attach(int $tenantId, int $messageId, array $ids): void
    {
        foreach ($ids as $id) {
            $this->db->prepare('UPDATE chat_attachments SET message_id = :mid WHERE tenant_id = :tid AND id = :id AND message_id IS NULL')
                ->execute([':mid' => $messageId, ':tid' => $tenantId, ':id' => $id]);
        }
    }

    /**
     * Attachments on the given messages, in the order they were added.
     *
     * @param list<int> $messageIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function forMessages(int $tenantId, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE a.tenant_id = ? AND a.message_id IN ({$marks}) ORDER BY a.id");
        $stmt->execute([$tenantId, ...$messageIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['message_id']][] = self::present($row);
        }

        return $out;
    }

    /**
     * A chat's sent photos, videos and files, newest first, above the reader's floor (UC.8).
     *
     * @return list<array<string, mixed>>
     */
    public function media(int $tenantId, int $chatId, int $floorId, ?string $kind, int $limit = 60): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' JOIN messages m ON m.id = a.message_id AND m.tenant_id = a.tenant_id
            WHERE a.tenant_id = :tid AND a.conversation_id = :cid AND a.message_id > :floor AND m.deleted_at IS NULL';
        $params = [':tid' => $tenantId, ':cid' => $chatId, ':floor' => $floorId];
        if ($kind === 'photos') {
            $sql .= " AND a.kind IN ('image', 'video')";
        } elseif ($kind === 'files') {
            $sql .= " AND a.kind = 'file'";
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY a.id DESC LIMIT :limit');
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(self::present(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * The row behind an attachment, for reading its bytes; the caller checks membership.
     *
     * @return array<string, mixed>|null
     */
    public function row(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE a.tenant_id = :tid AND a.id = :id');
        $stmt->execute([':tid' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * A copy of a sent file for a message in another chat (Forward, UC.9): its
     * own bytes under a fresh name, so deleting either message leaves the other.
     *
     * @return int the copy's id, not sent yet
     */
    public function copyForChat(int $tenantId, int $attachmentId, int $chatId, int $profileId): int
    {
        $row = $this->row($tenantId, $attachmentId);
        if ($row === null) {
            throw ChatException::notFound();
        }
        $copy = $this->upload(
            $tenantId,
            $chatId,
            $profileId,
            $this->bytes((string) $row['storage_key']),
            (string) $row['content_type'],
            isset($row['filename']) ? (string) $row['filename'] : null,
            [
                'duration_ms' => isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
                'width' => isset($row['width']) ? (int) $row['width'] : null,
                'height' => isset($row['height']) ? (int) $row['height'] : null,
            ],
        );
        if (isset($row['transcript']) && $row['transcript'] !== '') {
            $this->setTranscript($tenantId, (int) $copy['id'], (string) $row['transcript']);
        }

        return (int) $copy['id'];
    }

    public function setTranscript(int $tenantId, int $id, string $text): void
    {
        $text = trim(mb_substr($text, 0, 4000));
        $this->db->prepare('UPDATE chat_attachments SET transcript = :text WHERE tenant_id = :tid AND id = :id')
            ->execute([':text' => $text === '' ? null : $text, ':tid' => $tenantId, ':id' => $id]);
    }

    public function bytes(string $storageKey): string
    {
        return $this->storage->get($storageKey);
    }

    /**
     * When a message is deleted for everyone, its files go too.
     */
    public function deleteForMessage(int $tenantId, int $messageId): void
    {
        $stmt = $this->db->prepare('SELECT f.storage_key FROM ' . self::FROM . ' WHERE a.tenant_id = :tid AND a.message_id = :mid');
        $stmt->execute([':tid' => $tenantId, ':mid' => $messageId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            try {
                $this->storage->delete((string) $key);
            } catch (\Throwable) {
                // The row goes either way; a stray object costs storage, not privacy (nothing points at it).
            }
            \Whity\Core\Storage\StoredFiles::forget($this->db, $tenantId, (string) $key);
        }
        $this->db->prepare('DELETE FROM chat_attachments WHERE tenant_id = :tid AND message_id = :mid')
            ->execute([':tid' => $tenantId, ':mid' => $messageId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);

        return [
            'id' => $id,
            'kind' => (string) ($row['kind'] ?? 'file'),
            'content_type' => (string) ($row['content_type'] ?? ''),
            'byte_size' => (int) ($row['byte_size'] ?? 0),
            'filename' => isset($row['filename']) ? (string) $row['filename'] : null,
            'width' => isset($row['width']) ? (int) $row['width'] : null,
            'height' => isset($row['height']) ? (int) $row['height'] : null,
            'duration_ms' => isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
            'transcript' => isset($row['transcript']) ? (string) $row['transcript'] : null,
            'message_id' => isset($row['message_id']) ? (int) $row['message_id'] : null,
            'url' => '/api/v1/chat/attachments/' . $id,
        ];
    }

    private static function safeFilename(?string $raw, string $ext): string
    {
        $name = basename(str_replace('\\', '/', (string) $raw));
        $name = trim(preg_replace('/[\x00-\x1f\x7f"<>]/', '', $name) ?? '');
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file.' . $ext;
        }

        return mb_substr($name, 0, 255);
    }
}
