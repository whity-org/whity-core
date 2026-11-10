<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use Throwable;
use Whity\Core\Hooks\HookManager;

/**
 * Tells plugins about a form submission (SDK 1.94, WHIT-923, #1674).
 *
 * A submission holds personal data and may be anonymous, so a payload carries
 * ids only. Called after the change is saved: a listener that fails cannot undo
 * what the person just did, and a broadcast that fails is logged and dropped.
 *
 * Stateless, worker-safe.
 */
final class SubmissionEvents
{
    /** @param array<string, mixed> $extra More ids. */
    public static function announce(
        ?HookManager $hooks,
        string $event,
        int $tenantId,
        int $submissionId,
        int $formId,
        ?int $actorProfileId,
        array $extra = [],
    ): void {
        if ($hooks === null) {
            return;
        }
        $payload = [
            'id' => $submissionId,
            'tenant_id' => $tenantId,
            'form_id' => $formId,
            'actor_profile_id' => $actorProfileId,
        ] + $extra;

        try {
            $hooks->dispatch($event, $payload);
        } catch (Throwable $e) {
            error_log('[SubmissionEvents] a listener on ' . $event . ' failed: ' . $e->getMessage());
        }
        try {
            $hooks->dispatchAsync($event . '.async', $payload);
        } catch (Throwable $e) {
            error_log('[SubmissionEvents] could not record ' . $event . ': ' . $e->getMessage());
        }
    }
}
