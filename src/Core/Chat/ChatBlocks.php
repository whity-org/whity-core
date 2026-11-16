<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

use PDO;

/**
 * Blocks inside chat messages (design 3.5, UC.5, WHIT-1096): a poll, or a
 * Whity record (a booking or a document) with its live status and a way to
 * open it.
 *
 * A block is stored in the message's `chat_meta.block` as it was posted; what
 * it shows is read when the chat is read, so a booking that was cancelled
 * since says Cancelled, and a poll's counts are always the current ones.
 * Opening a record goes through that record's own page, which checks what the
 * reader may see there.
 */
final class ChatBlocks
{
    public const MAX_OPTIONS = 10;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The block to store, from what the poster sent, or why it is refused.
     *
     * @return array<string, mixed>
     *
     * @throws ChatException
     */
    public function normalise(int $tenantId, mixed $block): array
    {
        if (!is_array($block)) {
            throw new ChatException('That block is not one Chat knows.');
        }
        $type = $block['type'] ?? null;
        if ($type === 'poll') {
            $question = trim(mb_substr((string) ($block['question'] ?? ''), 0, 300));
            if ($question === '') {
                throw new ChatException('Write the poll\'s question.');
            }
            $options = [];
            foreach (is_array($block['options'] ?? null) ? $block['options'] : [] as $o) {
                $text = trim(mb_substr((string) (is_scalar($o) ? $o : ''), 0, 120));
                if ($text !== '' && !in_array($text, $options, true)) {
                    $options[] = $text;
                }
            }
            if (count($options) < 2 || count($options) > self::MAX_OPTIONS) {
                throw new ChatException('A poll has between 2 and ' . self::MAX_OPTIONS . ' different answers.');
            }
            $closes = null;
            if (isset($block['closes_at']) && is_string($block['closes_at']) && ($ts = strtotime($block['closes_at'])) !== false) {
                $closes = gmdate('Y-m-d\TH:i:s\Z', $ts);
            }

            return ['type' => 'poll', 'question' => $question, 'options' => $options, 'multiple' => ($block['multiple'] ?? false) === true, 'closes_at' => $closes];
        }
        if ($type === 'record') {
            $kind = $block['record'] ?? null;
            $id = is_numeric($block['id'] ?? null) ? (int) $block['id'] : 0;
            if (!in_array($kind, ['booking', 'document'], true) || $id <= 0 || $this->records($tenantId, [$kind => [$id]]) === []) {
                throw new ChatException('That record is not in this workspace.');
            }

            return ['type' => 'record', 'record' => $kind, 'id' => $id];
        }

        throw new ChatException('That block is not one Chat knows.');
    }

    /**
     * Each message's block as the viewer sees it now.
     *
     * @param array<int, array<string, mixed>> $blocks message id => stored block
     * @return array<int, array<string, mixed>>
     */
    public function present(int $tenantId, array $blocks, int $viewerProfileId): array
    {
        if ($blocks === []) {
            return [];
        }
        $pollIds = [];
        $wanted = ['booking' => [], 'document' => []];
        foreach ($blocks as $messageId => $b) {
            if (($b['type'] ?? null) === 'poll') {
                $pollIds[] = $messageId;
            } elseif (($b['type'] ?? null) === 'record' && isset($wanted[$b['record'] ?? ''])) {
                $wanted[(string) $b['record']][] = (int) ($b['id'] ?? 0);
            }
        }
        $votes = $this->votes($tenantId, $pollIds);
        $records = $this->records($tenantId, $wanted);

        $out = [];
        foreach ($blocks as $messageId => $b) {
            if (($b['type'] ?? null) === 'poll') {
                $options = array_values(array_map('strval', is_array($b['options'] ?? null) ? $b['options'] : []));
                $byOption = array_fill(0, count($options), []);
                $voters = [];
                foreach ($votes[$messageId] ?? [] as $v) {
                    if (isset($byOption[$v['option']])) {
                        $byOption[$v['option']][] = $v;
                        $voters[$v['profile_id']] = true;
                    }
                }
                $closes = isset($b['closes_at']) && is_string($b['closes_at']) ? $b['closes_at'] : null;
                $out[$messageId] = [
                    'type' => 'poll',
                    'question' => (string) ($b['question'] ?? ''),
                    'multiple' => ($b['multiple'] ?? false) === true,
                    'closes_at' => $closes,
                    'closed' => $closes !== null && strtotime($closes) <= time(),
                    'voters' => count($voters),
                    'options' => array_map(static fn (string $text, int $i): array => [
                        'text' => $text,
                        'votes' => count($byOption[$i]),
                        'mine' => in_array($viewerProfileId, array_column($byOption[$i], 'profile_id'), true),
                        'people' => array_column($byOption[$i], 'name'),
                    ], $options, array_keys($options)),
                ];
            } elseif (($b['type'] ?? null) === 'record') {
                $kind = (string) ($b['record'] ?? '');
                $out[$messageId] = $records[$kind][(int) ($b['id'] ?? 0)] ?? ['type' => 'record', 'record' => $kind, 'id' => (int) ($b['id'] ?? 0), 'missing' => true];
            }
        }

        return $out;
    }

    /**
     * Vote, or change your vote: the options you pick replace what you picked before.
     *
     * @param array<string, mixed> $block the stored block
     * @param list<int> $choices
     *
     * @throws ChatException
     */
    public function vote(int $tenantId, int $messageId, int $profileId, array $block, array $choices): void
    {
        if (($block['type'] ?? null) !== 'poll') {
            throw new ChatException('That message is not a poll.');
        }
        $closes = $block['closes_at'] ?? null;
        if (is_string($closes) && strtotime($closes) <= time()) {
            throw new ChatException('This poll has closed.');
        }
        $count = count(is_array($block['options'] ?? null) ? $block['options'] : []);
        $choices = array_values(array_unique(array_filter($choices, static fn (int $i): bool => $i >= 0 && $i < $count)));
        if (($block['multiple'] ?? false) !== true && count($choices) > 1) {
            throw new ChatException('This poll takes one answer.');
        }
        $this->db->prepare('DELETE FROM chat_poll_votes WHERE tenant_id = :tid AND message_id = :mid AND profile_id = :pid')
            ->execute([':tid' => $tenantId, ':mid' => $messageId, ':pid' => $profileId]);
        foreach ($choices as $i) {
            $this->db->prepare('INSERT INTO chat_poll_votes (tenant_id, message_id, profile_id, option_index, created_at) VALUES (:tid, :mid, :pid, :i, NOW())')
                ->execute([':tid' => $tenantId, ':mid' => $messageId, ':pid' => $profileId, ':i' => $i]);
        }
    }

    /**
     * Records to put in a message, newest first: upcoming and recent bookings,
     * the latest documents, matching the words.
     *
     * @return list<array<string, mixed>>
     */
    public function find(int $tenantId, string $kind, string $q, int $limit = 20): array
    {
        $q = mb_strtolower(trim(mb_substr($q, 0, 80)));
        $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        if ($kind === 'booking') {
            $stmt = $this->db->prepare("SELECT b.id FROM calendar_bookings b LEFT JOIN calendar_services s ON s.id = b.service_id AND s.tenant_id = b.tenant_id
                WHERE b.tenant_id = :tid AND b.starts_at >= :since
                  AND (:q = '' OR LOWER(COALESCE(b.customer_name, '')) LIKE :like ESCAPE '!' OR LOWER(COALESCE(s.name, '')) LIKE :like2 ESCAPE '!')
                ORDER BY b.starts_at ASC LIMIT :limit");
            $stmt->bindValue(':since', gmdate('Y-m-d H:i:s', time() - 7 * 86400));
        } elseif ($kind === 'document') {
            $stmt = $this->db->prepare("SELECT d.id FROM documents d
                WHERE d.tenant_id = :tid
                  AND (:q = '' OR LOWER(COALESCE(d.title, '')) LIKE :like ESCAPE '!' OR LOWER(COALESCE(d.number, '')) LIKE :like2 ESCAPE '!')
                ORDER BY d.created_at DESC, d.id DESC LIMIT :limit");
        } else {
            return [];
        }
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':q', $q);
        $stmt->bindValue(':like', $like);
        $stmt->bindValue(':like2', $like);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $ids = array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        $found = $this->records($tenantId, [$kind => $ids]);

        return array_values(array_filter(array_map(static fn (int $id): ?array => $found[$kind][$id] ?? null, $ids)));
    }

    /**
     * @param list<int> $messageIds
     * @return array<int, list<array{option: int, profile_id: int, name: string}>>
     */
    private function votes(int $tenantId, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->db->prepare("SELECT v.message_id, v.option_index, v.profile_id, p.display_name
              FROM chat_poll_votes v JOIN profiles p ON p.id = v.profile_id
             WHERE v.tenant_id = ? AND v.message_id IN ({$marks}) ORDER BY v.created_at, v.profile_id");
        $stmt->execute([$tenantId, ...$messageIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['message_id']][] = ['option' => (int) $r['option_index'], 'profile_id' => (int) $r['profile_id'], 'name' => (string) $r['display_name']];
        }

        return $out;
    }

    /**
     * @param array<string, array<int>> $wanted kind => ids
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function records(int $tenantId, array $wanted): array
    {
        $out = [];
        $bookings = array_values(array_unique(array_filter($wanted['booking'] ?? [])));
        if ($bookings !== []) {
            $marks = implode(',', array_fill(0, count($bookings), '?'));
            $stmt = $this->db->prepare("SELECT b.id, b.status, b.starts_at, b.ends_at, b.customer_name, s.name AS service_name, p.display_name AS staff_name
                  FROM calendar_bookings b
                  LEFT JOIN calendar_services s ON s.id = b.service_id AND s.tenant_id = b.tenant_id
                  LEFT JOIN profiles p ON p.id = b.profile_id
                 WHERE b.tenant_id = ? AND b.id IN ({$marks})");
            $stmt->execute([$tenantId, ...$bookings]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $start = self::iso($r['starts_at']);
                $out['booking'][(int) $r['id']] = [
                    'type' => 'record',
                    'record' => 'booking',
                    'id' => (int) $r['id'],
                    'title' => trim((string) ($r['service_name'] ?? '') . ($r['customer_name'] ? ' · ' . (string) $r['customer_name'] : '')),
                    'status' => (string) $r['status'],
                    'starts_at' => $start,
                    'ends_at' => self::iso($r['ends_at']),
                    'with' => $r['staff_name'] !== null ? (string) $r['staff_name'] : null,
                    'href' => '/calendar' . ($start !== null ? '?date=' . substr($start, 0, 10) : ''),
                ];
            }
        }
        $documents = array_values(array_unique(array_filter($wanted['document'] ?? [])));
        if ($documents !== []) {
            $marks = implode(',', array_fill(0, count($documents), '?'));
            $stmt = $this->db->prepare("SELECT d.id, d.title, d.number, d.template_name, d.withdrawn_at, d.created_at
                  FROM documents d WHERE d.tenant_id = ? AND d.id IN ({$marks})");
            $stmt->execute([$tenantId, ...$documents]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['document'][(int) $r['id']] = [
                    'type' => 'record',
                    'record' => 'document',
                    'id' => (int) $r['id'],
                    'title' => (string) ($r['title'] ?? ''),
                    'number' => $r['number'] !== null ? (string) $r['number'] : null,
                    'kind' => $r['template_name'] !== null ? (string) $r['template_name'] : null,
                    'status' => $r['withdrawn_at'] !== null ? 'withdrawn' : 'issued',
                    'created_at' => self::iso($r['created_at']),
                    'href' => '/admin/document-library/' . (int) $r['id'],
                ];
            }
        }

        return $out;
    }

    private static function iso(mixed $at): ?string
    {
        if (!is_string($at) || $at === '') {
            return null;
        }
        $ts = strtotime($at . (preg_match('/(Z|[+-]\d{2}(:?\d{2})?)$/', $at) === 1 ? '' : ' UTC'));

        return $ts === false ? $at : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
