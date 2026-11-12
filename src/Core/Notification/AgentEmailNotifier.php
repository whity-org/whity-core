<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Agent\AgentAssignmentRepository;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Sdk\Hooks\Events;

/**
 * Agent notifications by email (A12, card `TplAgentNotify`, N9, EM.1
 * `needs-approval`): when an agent proposes something, the person it acts for
 * hears by email that it waits for them.
 *
 * - **Only opens the app.** The email shows the proposal as a dashed card (not
 *   happened yet) and one button, Open in the workspace. Nothing in it approves,
 *   declines or changes anything; the decision happens after signing in.
 * - **Per agent:** an agent's page in admin/agents turns its emails off
 *   (`agents.notify_by_email`). Agents that are personal credentials (not a
 *   workspace agent) always may.
 * - **Per person:** Preferences › Notifications turns the kind off
 *   (`agent.needs_approval` on `email`).
 * - **At most one an hour** per agent and person, except sign-in approvals.
 *   The email says how many more wait, so a quiet hour loses nothing.
 *
 * Sent with core mail in the workspace's brand, not through Messages: Messages
 * is the workspace's conversation with its customers, and a note to a team
 * member about their own agent isn't one. WhatsApp stays the WhatsApp plugin's
 * channel. Best-effort throughout: never breaks the proposal that caused it.
 */
final class AgentEmailNotifier
{
    public const TYPE_NEEDS_APPROVAL = 'agent.needs_approval';
    public const CHANNEL = 'email';
    private const HOUR = 3600;

    private LoggerInterface $logger;

    public function __construct(
        private readonly PDO $db,
        private readonly AgentProposalRepository $proposals,
        private readonly NotificationPreferenceRepository $preferences,
        private readonly ProfileEmailRepository $emails,
        private readonly SharedStoreInterface $store,
        private readonly BrandedEmailSender $sender,
        /** The approvals page in the app. */
        private readonly string $approvalsUrl,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function subscribe(HookManager $hooks): void
    {
        $hooks->listen(Events::AGENT_PROPOSAL_CREATED, function (array $data, array $context = []): array {
            $this->proposalCreated($data);

            return $data;
        });
    }

    /**
     * @param array<string, mixed> $data The `agent.proposal.created` payload.
     * @return bool Whether an email went out.
     */
    public function proposalCreated(array $data): bool
    {
        try {
            return $this->notify(
                (int) ($data['tenant_id'] ?? 0),
                (int) ($data['proposal_id'] ?? 0),
                (int) ($data['on_behalf_of_profile_id'] ?? 0),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[agents] needs-approval email failed: ' . $e->getMessage());

            return false;
        }
    }

    private function notify(int $tenantId, int $proposalId, int $profileId): bool
    {
        if ($tenantId <= 0 || $proposalId <= 0 || $profileId <= 0) {
            return false;
        }
        $proposal = $this->proposals->find($proposalId, $tenantId);
        if ($proposal === null) {
            return false;
        }
        $principal = (string) ($proposal['agent_principal_id'] ?? $proposal['agentPrincipalId'] ?? '');
        $tool = (string) ($proposal['tool'] ?? '');
        $assignmentId = AgentAssignmentRepository::idFromPrincipal($principal);

        if ($assignmentId !== null && !$this->agentEmails($assignmentId, $tenantId)) {
            return false;
        }
        if (!$this->personWants($tenantId, $profileId)) {
            return false;
        }
        if (!self::isSignInApproval($tool)) {
            $key = 'agent-notify:' . $tenantId . ':' . $profileId . ':' . $principal;
            if ($this->store->increment($key, self::HOUR) > 1) {
                return false; // One an hour; the next one says how many more wait.
            }
        }

        $row = $this->emails->findPrimaryForProfile($profileId);
        $email = trim((string) ($row['email'] ?? ''));
        if ($email === '') {
            return false;
        }

        $lang = $this->sender->language($tenantId);
        $ar = $lang === 'ar';
        $names = $this->proposals->agentNames($tenantId, [$principal]);
        $agent = $names[$principal] ?? null;
        $agentName = $agent === null
            ? ($ar ? 'وكيل' : 'An agent')
            : ($ar && ($agent['nameAr'] ?? null) !== null ? (string) $agent['nameAr'] : (string) $agent['name']);
        $person = $this->displayName($profileId) ?? $email;
        $workspace = $this->sender->branding($tenantId)->siteName;
        $summary = trim((string) ($proposal['summary'] ?? ''));
        $title = $summary !== '' ? $summary : $tool;
        $more = max(0, $this->proposals->countWaiting($tenantId, $profileId) - 1);

        $content = new EmailContent(
            heading: $ar ? 'هذا يحتاج موافقتك' : 'This needs your approval',
            ctaLabel: $ar ? 'افتح في ' . $workspace : 'Open in ' . $workspace,
            ctaUrl: $this->approvalsUrl,
            footnote: $ar
                ? 'تقرّر بعد تسجيل الدخول، والتفاصيل أمامك.' . ($more > 0 ? ' و' . $more . ' أشياء أخرى بانتظارك.' : '')
                : 'You decide after signing in, with the details in front of you.' . ($more > 0 ? ' ' . $more . ' more ' . ($more === 1 ? 'is' : 'are') . ' waiting for you.' : ''),
            preheader: $ar ? $agentName . ' يحتاج موافقتك.' : $agentName . ' needs your approval.',
            proposal: [
                'meta' => $ar ? $agentName . ' · لـ ' . $person : $agentName . ' · for ' . $person,
                'title' => $title,
                'detail' => $ar ? 'لم يحدث بعد.' : 'Not done yet.',
            ],
            notice: $ar
                ? 'تصلك هذه لأنك توافق على طلبات هذا الوكيل. غيّر الرسائل التي تصلك من التفضيلات › الإشعارات.'
                : 'You get this because you approve this agent’s requests. Change which emails you get in Preferences › Notifications.',
            language: $lang,
        );

        return $this->sender->send(
            $tenantId,
            $email,
            ($ar ? $agentName . ' يحتاج موافقتك: ' : $agentName . ' needs your approval: ') . $title,
            $content,
        );
    }

    /** The agent's own setting on its admin page; on unless turned off. */
    private function agentEmails(int $assignmentId, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT a.notify_by_email
               FROM agent_assignments aa
               JOIN agents a ON a.id = aa.agent_id AND a.tenant_id = aa.tenant_id
              WHERE aa.id = :id AND aa.tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $assignmentId, ':tenant_id' => $tenantId]);
        // A row, not a column: fetchColumn() answers false both for "no row" and
        // for a false the driver returns as a real boolean.
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['notify_by_email'] === null) {
            return true;
        }

        return \Whity\Core\Db\DbBool::of($row['notify_by_email']);
    }

    /** Preferences › Notifications; on unless the person turned it off. */
    private function personWants(int $tenantId, int $profileId): bool
    {
        foreach ($this->preferences->listForProfile($tenantId, $profileId) as $pref) {
            if ($pref['type'] === self::TYPE_NEEDS_APPROVAL && $pref['channel'] === self::CHANNEL) {
                return $pref['enabled'];
            }
        }

        return true;
    }

    private function displayName(int $profileId): ?string
    {
        $stmt = $this->db->prepare('SELECT display_name FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);
        $name = $stmt->fetchColumn();

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * A sign-in waiting on the person (an agent asking to sign in for them) is
     * never held back by the hourly limit: it is useless an hour late.
     */
    private static function isSignInApproval(string $tool): bool
    {
        return str_starts_with($tool, 'auth.') || str_contains($tool, 'sign_in');
    }
}
