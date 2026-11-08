<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * Issue documents from the workspace's templates (SDK 1.56, WHIT-742,
 * DOCUMENT-AUTOMATION.md 3.9). Resolve it with
 * `\Whity\app(\Whity\Sdk\Documents\Documents::class)`.
 *
 * The host implements it; a plugin never does. It acts as the person whose
 * request it is in, with their permissions, and records them as the issuer:
 * a plugin can't widen what that person may do.
 *
 * In a job of a plugin that declares `issuesInBackground()` (SDK 1.58), it
 * acts as the plugin: only from the plugin's own slots, only with the
 * permissions the plugin declares, and only in workspaces whose admins
 * allowed it, otherwise {@see DocumentNotIssuedException::BACKGROUND_NOT_ALLOWED}.
 * Anywhere else with no person it is {@see DocumentNotIssuedException::NO_ACTOR}.
 *
 * Many documents from one template: {@see issueMany()} (SDK 1.83), a
 * background job with a result per document.
 *
 * For documents from templates this replaces `DocumentRenderer::issue()`:
 * they carry the workspace's letterhead, theme, fields and QR code.
 */
interface Documents
{
    /** Whether this host can issue now: there is a workspace and a person. */
    public function canIssue(): bool;

    /**
     * Issue a document, or return the one `originKey` already names.
     *
     * @throws DocumentNotIssuedException when nothing was issued, with why
     */
    public function issue(IssueRequest $request): IssueResult;

    /**
     * Would this issue, and if not, why (SDK 1.66)? Runs the same checks as
     * {@see issue()}, including `document.issuing` listeners with `check_only: true`
     * in the payload, and writes nothing. A real issue still checks everything again.
     *
     * @throws DocumentNotIssuedException with {@see DocumentNotIssuedException::NO_ACTOR}
     *         when there is nobody to ask for. Anything else that would refuse the
     *         issue is in the result.
     */
    public function preflight(IssueRequest $request): PreflightResult;

    /**
     * Issue many documents from one template, in the background (SDK 1.83).
     * Nothing is issued in this call: the batch is recorded and queued, and a
     * worker issues each item as whoever is calling now, checking and
     * preflighting it when it runs. Read progress with {@see batch()}.
     *
     * Sending the same `batchKey` again returns the batch it already started
     * (`resubmitted: true`), so a retry can't issue twice; the same key with
     * different items is {@see DocumentNotIssuedException::BATCH_KEY_CONFLICT}.
     * A person needs `documents:issue`, otherwise
     * {@see DocumentNotIssuedException::FORBIDDEN}.
     *
     * @throws DocumentNotIssuedException when no batch was started
     */
    public function issueMany(BulkIssueRequest $request): BulkIssueStatus;

    /**
     * A batch this caller started, with a result per item; null for any other
     * batch (SDK 1.83). Batches belong to their workspace and their sender: the
     * plugin, for a plugin's background job, or the person, in a request. A
     * batch key is scoped the same way, so two plugins never share one.
     */
    public function batch(int $batchId): ?BulkIssueStatus;

    /**
     * Stop a batch this caller started (SDK 1.83): its pending items become
     * `cancelled` and the documents already issued stay. Null for any other
     * batch. Cancelling a finished batch changes nothing and returns it.
     */
    public function cancelBatch(int $batchId): ?BulkIssueStatus;
}
