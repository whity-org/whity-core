<?php

declare(strict_types=1);

namespace Whity\Core\Agent;

use Whity\Core\Hooks\HookManager;
use Whity\Sdk\Hooks\Events;

/**
 * Tells plugins what happened to agents' proposals (WHIT-991): one was filed,
 * a person decided it (or it expired), or what it did was undone.
 *
 * Notifications only. Each fires after the change is saved, carries ids and the
 * tool's name, never the call's arguments, and ignores what listeners return. A
 * listener that throws, {@see \Whity\Sdk\Hooks\HookVetoException} included, is
 * logged and changes nothing: only the person who granted the agent decides a
 * proposal, never a plugin.
 */
final class AgentProposalEvents
{
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const EXPIRED  = 'expired';

    public function __construct(private readonly ?HookManager $hooks)
    {
    }

    public function created(int $tenantId, int $proposalId, string $agentPrincipalId, int $profileId, string $tool): void
    {
        $this->announce(Events::AGENT_PROPOSAL_CREATED, [
            'tenant_id'               => $tenantId,
            'proposal_id'             => $proposalId,
            'agent_assignment_id'     => AgentAssignmentRepository::idFromPrincipal($agentPrincipalId),
            'on_behalf_of_profile_id' => $profileId,
            'tool'                    => $tool,
        ]);
    }

    /**
     * @param string   $decision  {@see self::APPROVED}, {@see self::DECLINED} or {@see self::EXPIRED}
     * @param int|null $decidedBy the person who decided; null when it expired
     * @param bool     $failed    approved, and a check refused it when it ran
     */
    public function decided(int $tenantId, int $proposalId, string $decision, ?int $decidedBy, int $profileId, string $tool, bool $failed = false): void
    {
        $this->announce(Events::AGENT_PROPOSAL_DECIDED, [
            'tenant_id'               => $tenantId,
            'proposal_id'             => $proposalId,
            'decision'                => $decision,
            'decided_by_profile_id'   => $decidedBy,
            'on_behalf_of_profile_id' => $profileId,
            'tool'                    => $tool,
            'failed'                  => $failed,
        ]);
    }

    public function undone(int $tenantId, int $actionId, ?int $proposalId, int $profileId, string $tool): void
    {
        $this->announce(Events::AGENT_ACTION_UNDONE, [
            'tenant_id'               => $tenantId,
            'action_id'               => $actionId,
            'proposal_id'             => $proposalId,
            'on_behalf_of_profile_id' => $profileId,
            'tool'                    => $tool,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function announce(string $event, array $payload): void
    {
        if ($this->hooks === null) {
            return;
        }
        try {
            $this->hooks->dispatch($event, $payload);
        } catch (\Throwable $e) {
            error_log('[agents] a listener on ' . $event . ' failed: ' . $e->getMessage());
        }
    }
}
