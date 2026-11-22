<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * MakeExpiredWorkspacesReadOnly — an expired workspace is read-only, the same
 * as a trial that ended unpaid (2026-11-22).
 *
 * Sign-up, the federated provisioner and the workspace founder wrote their
 * closed payment gate as `expired` / `block_all`. `block_all` walls every
 * READ as well as every write, so the owner of such a workspace could not
 * open it (switch-tenant answered 402) and so could never reach Billing to
 * pay: Almolakhas Org. on app.whity.dev, whose trial ended with no plan.
 *
 * Moves every expired workspace on `block_all` to `block_writes`: it can be
 * opened and read, changes are refused, and Billing, the plan picker and
 * checkout stay reachable. Nothing else changes; `block_all` set on any other
 * status, and the global default, are left as the operator chose them.
 *
 * Idempotent. `down()` cannot tell the rows this moved from ones set to
 * `block_writes` by hand afterwards, so it leaves them.
 */
final class MakeExpiredWorkspacesReadOnly
{
    public static function up(Database $db): void
    {
        $db->query(
            "UPDATE tenant_plan
                SET enforcement_mode = 'block_writes'
              WHERE status = 'expired'
                AND enforcement_mode = 'block_all'"
        );
    }

    public static function down(Database $db): void
    {
        // Deliberately nothing: see the class comment.
    }
}
