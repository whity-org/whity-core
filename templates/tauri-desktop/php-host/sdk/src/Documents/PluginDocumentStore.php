<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * One plugin's documents in the current workspace (SDK 1.63, WHIT-886). Get one
 * from {@see PluginDocuments::for()}.
 *
 * `IssueRequest::$originKey` is your own name for the document. It is kept in
 * your own namespace, so another plugin's key of the same text is a different
 * document, and issuing again with the same key returns your first document,
 * as {@see Documents::issue()} does.
 */
interface PluginDocumentStore
{
    /**
     * Issue a document from one of this plugin's own template slots, and record
     * it as this plugin's.
     *
     * @throws DocumentNotIssuedException as {@see Documents::issue()} does
     * @throws \LogicException            when there is no workspace in scope
     */
    public function issue(IssueRequest $request): IssueResult;

    /**
     * Would {@see issue()} go through, and if not, why (SDK 1.66)? Writes nothing.
     *
     * @throws DocumentNotIssuedException when there is nobody to ask for.
     */
    public function preflight(IssueRequest $request): PreflightResult;

    /**
     * The verification URL to print as a QR code on a document this plugin
     * issued, minting the code if the document has none yet. Null when the
     * document is not this plugin's, or when this instance cannot mint a code
     * (the workspace has QR verification off, or the address is not set).
     */
    public function qrVerificationUrl(int $documentId): ?string;

    /**
     * How a document this plugin issued has been tracked: whether its code is
     * still honoured, how many times it was scanned, and the most recent scans.
     * Null when the document is not this plugin's, so the answer never says
     * whether another plugin's document exists.
     *
     * Scans carry when and how each one was answered, never who scanned: the
     * tracking record says nothing about the person who did the scanning.
     */
    public function tracking(int $documentId): ?DocumentTracking;
}
