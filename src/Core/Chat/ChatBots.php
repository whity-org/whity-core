<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

use PDO;
use Whity\Core\Messaging\MessagingService;

/**
 * Bots set by the workspace (design 3.5, UC.4, WHIT-1097): rules an admin
 * turns on in Settings › Chat. A bot follows its rule and never decides
 * anything; agents are something else (they think, and propose).
 *
 * - **Away reply**: a customer who writes outside opening hours gets the
 *   workspace's away message, once a day per conversation.
 * - **Welcome**: a customer's first message gets the welcome message.
 * - **Hand-over**: a new conversation goes to the next person on a list, in
 *   turn, when nobody has it.
 *
 * Order status and stock alerts need inventory's and accounting's data; they
 * come as bots of their own.
 */
final class ChatBots
{
    public const KINDS = ['away', 'welcome', 'handover'];

    /** @var array<string, array<string, mixed>> */
    private const DEFAULTS = [
        'away' => ['text' => 'Thanks for your message. We are closed now and will answer when we open.', 'timezone' => 'UTC', 'days' => [1, 2, 3, 4, 5, 6], 'from' => '09:00', 'to' => '18:00'],
        'welcome' => ['text' => 'Hello! Thanks for writing. Someone from the team will answer here soon.'],
        'handover' => ['people' => [], 'next' => 0],
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array{kind: string, enabled: bool, settings: array<string, mixed>}> */
    public function all(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT kind, enabled, settings FROM chat_bots WHERE tenant_id = :tid');
        $stmt->execute([':tid' => $tenantId]);
        $saved = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $decoded = json_decode((string) $r['settings'], true);
            $saved[(string) $r['kind']] = ['enabled' => \Whity\Core\Db\DbBool::of($r['enabled']), 'settings' => is_array($decoded) ? $decoded : []];
        }
        $out = [];
        foreach (self::KINDS as $kind) {
            $out[] = [
                'kind' => $kind,
                'enabled' => $saved[$kind]['enabled'] ?? false,
                'settings' => ($saved[$kind]['settings'] ?? []) + self::DEFAULTS[$kind],
            ];
        }

        return $out;
    }

    /**
     * Turn a bot on or off and set its rule.
     *
     * @param array<string, mixed> $settings
     * @return array{kind: string, enabled: bool, settings: array<string, mixed>}
     *
     * @throws ChatException
     */
    public function save(int $tenantId, string $kind, bool $enabled, array $settings, int $byProfileId): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new ChatException('That bot is not one Chat has.');
        }
        $clean = $this->clean($tenantId, $kind, $settings);
        $stmt = $this->db->prepare('INSERT INTO chat_bots (tenant_id, kind, enabled, settings, updated_by_profile_id, updated_at)
             VALUES (:tid, :kind, :on, :settings, :by, NOW())
             ON CONFLICT (tenant_id, kind) DO UPDATE SET enabled = excluded.enabled, settings = excluded.settings,
                updated_by_profile_id = excluded.updated_by_profile_id, updated_at = excluded.updated_at');
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':kind', $kind);
        $stmt->bindValue(':on', $enabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':settings', (string) json_encode($clean, JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':by', $byProfileId, PDO::PARAM_INT);
        $stmt->execute();

        foreach ($this->all($tenantId) as $bot) {
            if ($bot['kind'] === $kind) {
                return $bot;
            }
        }

        return ['kind' => $kind, 'enabled' => $enabled, 'settings' => $clean];
    }

    /**
     * After a customer's message arrives: the rules that apply, in order.
     * Never throws; a bot problem must never cost anyone their message.
     */
    public function onInbound(MessagingService $messaging, int $tenantId, int $conversationId, bool $isNew): void
    {
        try {
            $bots = [];
            foreach ($this->all($tenantId) as $bot) {
                $bots[$bot['kind']] = $bot;
            }
            if ($isNew && $bots['handover']['enabled']) {
                $this->handOver($tenantId, $conversationId, $bots['handover']['settings']);
            }
            if ($isNew && $bots['welcome']['enabled'] && trim((string) $bots['welcome']['settings']['text']) !== '') {
                $messaging->reply($tenantId, $conversationId, null, (string) $bots['welcome']['settings']['text'], false, 'bot.welcome');
            }
            $away = $bots['away'];
            if ($away['enabled'] && !self::isOpen($away['settings'], time()) && !$this->sentLately($tenantId, $conversationId, 'bot.away')
                && trim((string) $away['settings']['text']) !== '') {
                $messaging->reply($tenantId, $conversationId, null, (string) $away['settings']['text'], false, 'bot.away');
            }
        } catch (\Throwable $e) {
            error_log('[ChatBots] a rule did not run: ' . $e->getMessage());
        }
    }

    /**
     * Whether the workspace is open at `$now` by the away rule's hours.
     *
     * @param array<string, mixed> $settings
     */
    public static function isOpen(array $settings, int $now): bool
    {
        try {
            $zone = new \DateTimeZone((string) ($settings['timezone'] ?? 'UTC'));
        } catch (\Throwable) {
            $zone = new \DateTimeZone('UTC');
        }
        $at = (new \DateTimeImmutable('@' . $now))->setTimezone($zone);
        $days = array_map('intval', is_array($settings['days'] ?? null) ? $settings['days'] : []);
        if (!in_array((int) $at->format('N'), $days, true)) {
            return false;
        }
        $time = $at->format('H:i');

        return $time >= (string) ($settings['from'] ?? '00:00') && $time < (string) ($settings['to'] ?? '24:00');
    }

    /** @param array<string, mixed> $settings */
    private function handOver(int $tenantId, int $conversationId, array $settings): void
    {
        $people = array_values(array_map('intval', is_array($settings['people'] ?? null) ? $settings['people'] : []));
        if ($people === []) {
            return;
        }
        $next = (int) ($settings['next'] ?? 0) % count($people);
        $stmt = $this->db->prepare('UPDATE conversations SET assigned_profile_id = :pid, updated_at = NOW()
            WHERE tenant_id = :tid AND id = :id AND assigned_profile_id IS NULL');
        $stmt->execute([':pid' => $people[$next], ':tid' => $tenantId, ':id' => $conversationId]);
        $settings['next'] = ($next + 1) % count($people);
        $this->db->prepare("UPDATE chat_bots SET settings = :s WHERE tenant_id = :tid AND kind = 'handover'")
            ->execute([':s' => (string) json_encode($settings, JSON_UNESCAPED_UNICODE), ':tid' => $tenantId]);
    }

    private function sentLately(int $tenantId, int $conversationId, string $source): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM messages WHERE tenant_id = :tid AND conversation_id = :cid AND source = :src AND created_at >= :since LIMIT 1');
        $stmt->execute([':tid' => $tenantId, ':cid' => $conversationId, ':src' => $source, ':since' => gmdate('Y-m-d H:i:s', time() - 86400)]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function clean(int $tenantId, string $kind, array $settings): array
    {
        $text = static fn (mixed $v): string => trim(mb_substr((string) (is_scalar($v) ? $v : ''), 0, 1000));
        if ($kind === 'away') {
            $zone = (string) ($settings['timezone'] ?? 'UTC');
            if (!in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
                $zone = 'UTC';
            }
            $hhmm = static fn (mixed $v, string $d): string => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1 ? $v : $d;
            $days = array_values(array_unique(array_filter(array_map('intval', is_array($settings['days'] ?? null) ? $settings['days'] : []), static fn (int $d): bool => $d >= 1 && $d <= 7)));
            sort($days);

            return ['text' => $text($settings['text'] ?? ''), 'timezone' => $zone, 'days' => $days, 'from' => $hhmm($settings['from'] ?? null, '09:00'), 'to' => $hhmm($settings['to'] ?? null, '18:00')];
        }
        if ($kind === 'welcome') {
            return ['text' => $text($settings['text'] ?? '')];
        }
        // Hand-over: only people who work in this workspace.
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($settings['people'] ?? null) ? $settings['people'] : []), static fn (int $id): bool => $id > 0)));
        $staff = [];
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("SELECT profile_id FROM memberships WHERE tenant_id = ? AND status = 'active' AND kind <> 'customer' AND profile_id IN ({$marks})");
            $stmt->execute([$tenantId, ...$ids]);
            $staff = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        return ['people' => array_values(array_filter($ids, static fn (int $id): bool => in_array($id, $staff, true))), 'next' => 0];
    }
}
