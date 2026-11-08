<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * Ask the host to re-read one billing subject's access now (SDK 1.86).
 *
 * Resolve it from the container:
 *
 *     \Whity\app(\Whity\Sdk\Billing\AccessRefresh::class)->refresh('tenant-12');
 *
 * The host asks the deployment's billing portal what is true for that subject
 * and records it, the same way a verified provider notification does. A billing
 * plugin uses it when it knows something changed without a notification, for
 * example when the operator switches from test to live and every workspace that
 * test mode billed must lapse at once.
 *
 * IT TAKES ONLY THE SUBJECT. Nobody can pass a state, a plan or a status, so a
 * call can never grant more than the portal itself reports. It is idempotent,
 * throttled per subject, and audited.
 */
interface AccessRefresh
{
    /**
     * @return bool True when the subject was re-read and recorded; false when it
     *              is not a workspace, was refreshed moments ago, no portal is
     *              configured, or the portal could not answer.
     */
    public function refresh(string $subjectRef): bool;
}
