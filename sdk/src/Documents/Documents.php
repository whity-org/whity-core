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
}
