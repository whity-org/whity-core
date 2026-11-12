<?php

declare(strict_types=1);

namespace Whity\Core\Notification\Jobs;

use PDO;
use Whity\Core\Agent\AgentStatsRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Sdk\JobInterface;

/**
 * EM.1 `daily-summary`: at 18:00 in each workspace's time zone, everyone with an
 * agent hears what their agents did today and how many things wait for them.
 *
 * The scheduler queues this once an hour; each run picks the workspaces where it
 * is now 18:00 and sends at most one summary per person per day (a shared-store
 * key), so a retried or doubled run sends nothing twice. Nobody who had a quiet
 * day gets an email. Preferences › Notifications turns it off
 * (`agent.daily_summary` on `email`). Like every agent email, it only opens the
 * app.
 */
final class AgentDailySummaryJob implements JobInterface
{
    public const NAME = 'core.agents.daily_summary';
    public const TYPE = 'agent.daily_summary';
    private const HOUR = 18;
    private const DAY = 86400;

    public function __construct(
        private readonly PDO $db,
        private readonly SettingsService $settings,
        private readonly AgentStatsRepository $stats,
        private readonly NotificationPreferenceRepository $preferences,
        private readonly ProfileEmailRepository $emails,
        private readonly SharedStoreInterface $store,
        private readonly BrandedEmailSender $sender,
        private readonly string $appUrl,
        /** @var (\Closure(): \DateTimeImmutable)|null */
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function handle(array $payload): array
    {
        $now = $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $sent = 0;

        foreach ($this->tenantsWithAgents() as $tenantId) {
            $zone = $this->zone($tenantId);
            $local = $now->setTimezone($zone);
            if ((int) $local->format('G') !== self::HOUR) {
                continue;
            }
            $sent += $this->summarise($tenantId, AgentStatsRepository::todayStart($zone, $now), $local->format('Y-m-d'));
        }

        return ['sent' => $sent];
    }

    private function summarise(int $tenantId, string $todayFrom, string $day): int
    {
        $sent = 0;
        foreach ($this->peopleWithAgents($tenantId) as $profileId => $name) {
            if (!$this->wants($tenantId, $profileId)) {
                continue;
            }
            $perAgent = $this->stats->forWorkspace($tenantId, $todayFrom, $profileId);
            $done = 0;
            $waiting = 0;
            $lines = [];
            $lang = $this->sender->language($tenantId);
            $ar = $lang === 'ar';
            $names = $this->agentNames($tenantId, array_keys($perAgent), $ar);
            foreach ($perAgent as $agentId => $row) {
                $done += $row['actionsToday'];
                $waiting += $row['waiting'];
                if ($row['actionsToday'] > 0) {
                    $agent = $names[$agentId] ?? ($ar ? 'وكيل' : 'An agent');
                    $lines[] = $ar
                        ? 'أنجز ' . $agent . ' ' . $row['actionsToday'] . ' أشياء'
                        : $agent . ' did ' . $row['actionsToday'] . ' ' . ($row['actionsToday'] === 1 ? 'thing' : 'things');
                }
            }
            if ($done === 0 && $waiting === 0) {
                continue; // A quiet day sends nothing.
            }
            if ($this->store->increment('agent-daily:' . $tenantId . ':' . $profileId . ':' . $day, self::DAY) > 1) {
                continue;
            }
            $email = trim((string) ($this->emails->findPrimaryForProfile($profileId)['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $workspace = $this->sender->branding($tenantId)->siteName;

            $content = new EmailContent(
                heading: $ar ? 'مساء الخير، ' . $name : 'Good evening, ' . $name,
                paragraphs: [$ar
                    ? 'أنجز وكلاؤك ' . $done . ' أشياء اليوم. و' . $waiting . ' تحتاجك.'
                    : 'Your agents did ' . $done . ' ' . ($done === 1 ? 'thing' : 'things') . ' today. ' . $waiting . ' ' . ($waiting === 1 ? 'needs' : 'need') . ' you.'],
                ctaLabel: $ar ? 'افتح ' . $workspace : 'Open ' . $workspace,
                ctaUrl: $this->appUrl,
                preheader: $ar ? 'ما فعله وكلاؤك اليوم، وما يحتاجك.' : 'What your agents did today, and what needs you.',
                rows: [[$ar ? 'بانتظارك' : 'Waiting for you', (string) $waiting]],
                checklist: $lines,
                notice: $ar
                    ? 'ملخص الساعة 18:00 كل يوم فيه عمل. أوقفه من التفضيلات › الإشعارات.'
                    : 'A summary at 18:00 on days with something to tell. Stop these in Preferences › Notifications.',
                language: $lang,
            );
            $subject = $ar
                ? 'اليوم في ' . $workspace . ': ' . $done . ' أُنجزت، و' . $waiting . ' بانتظارك'
                : 'Today in ' . $workspace . ': ' . $done . ' done, ' . $waiting . ' waiting for you';
            if ($this->sender->send($tenantId, $email, $subject, $content)) {
                $sent++;
            }
        }

        return $sent;
    }

    /** @return list<int> */
    private function tenantsWithAgents(): array
    {
        // Which workspaces to look at: a scheduler run spans every workspace by design.
        // @tenant-guard-ignore: lists the workspaces the daily summary visits; every later query is scoped to one
        $stmt = $this->db->query('SELECT DISTINCT tenant_id FROM agent_assignments WHERE revoked_at IS NULL ORDER BY tenant_id');

        return $stmt === false ? [] : array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return array<int, string> display names by profile id */
    private function peopleWithAgents(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT asg.profile_id, p.display_name
               FROM agent_assignments asg
               JOIN profiles p ON p.id = asg.profile_id
              WHERE asg.tenant_id = :tenant_id AND asg.revoked_at IS NULL
              ORDER BY asg.profile_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $people = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $people[(int) $row['profile_id']] = trim((string) ($row['display_name'] ?? ''));
        }

        return $people;
    }

    /**
     * @param list<int> $agentIds
     * @return array<int, string>
     */
    private function agentNames(int $tenantId, array $agentIds, bool $ar): array
    {
        if ($agentIds === []) {
            return [];
        }
        $in = implode(', ', array_map('intval', $agentIds));
        $stmt = $this->db->prepare("SELECT id, name, name_ar FROM agents WHERE tenant_id = :tenant_id AND id IN ({$in})");
        $stmt->execute([':tenant_id' => $tenantId]);
        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $nameAr = trim((string) ($row['name_ar'] ?? ''));
            $names[(int) $row['id']] = $ar && $nameAr !== '' ? $nameAr : (string) $row['name'];
        }

        return $names;
    }

    private function wants(int $tenantId, int $profileId): bool
    {
        foreach ($this->preferences->listForProfile($tenantId, $profileId) as $pref) {
            if ($pref['type'] === self::TYPE && $pref['channel'] === 'email') {
                return $pref['enabled'];
            }
        }

        return true;
    }

    private function zone(int $tenantId): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) ($this->settings->effective($tenantId)[SettingsRegistry::TIMEZONE] ?? 'UTC'));
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }
}
