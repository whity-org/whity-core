<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Why a person declined an agent's proposal (WHIT-1042, TplImpactPlan).
 *
 * When a Whity Builder publish fails its tests, the person can't approve it;
 * "Ask Whity Builder to fix it" declines it with the failing tests named, and
 * the agent reads that reason back through `get_proposal` and opens the draft
 * again. Any decline may carry one; most don't. Plain text, at most 1000
 * characters (the handler cuts it), and never shown to anyone but the person
 * and their agent. Nothing existing is changed: past declines have none.
 *
 * Idempotent and reversible.
 */
final class AddAgentProposalDeclineReason
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE agent_proposals ADD COLUMN IF NOT EXISTS decline_reason TEXT');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE agent_proposals DROP COLUMN IF EXISTS decline_reason');
    }
}
