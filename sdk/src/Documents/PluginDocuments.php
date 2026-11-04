<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

use Whity\Sdk\PluginInterface;

/**
 * A plugin's own documents, with QR tracking (SDK 1.63, WHIT-886). Resolve it
 * with `\Whity\app(\Whity\Sdk\Documents\PluginDocuments::class)`, then take the
 * plugin's own store:
 *
 *     $docs = \Whity\app(PluginDocuments::class)->for($this);
 *     $issued = $docs->issue(new IssueRequest(new TemplateRef('invoice', 7), ...));
 *     $url = $docs->qrVerificationUrl($issued->documentId);
 *
 * Issuing goes through {@see Documents}, so the person or background job the
 * call runs under still has to be allowed to issue, and the document is made
 * exactly as the workspace makes its documents. What this adds is ownership:
 * the document is recorded as this plugin's, and everything after that (the
 * verification code, the tracking) works only on documents this plugin issued.
 *
 * A plugin can never reach another plugin's documents, nor a document it did
 * not issue, even one in the same workspace. The host implements this; a
 * plugin never does.
 */
interface PluginDocuments
{
    /** The store for $plugin's own documents in the current workspace. */
    public function for(PluginInterface $plugin): PluginDocumentStore;
}
