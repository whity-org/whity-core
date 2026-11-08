- **Plugins issue documents in bulk.** `Documents::issueMany()` (SDK 1.83)
  issues up to 500 documents from one template as a background job, and
  `Documents::batch()` reads its progress with a result per document. Each
  document is checked and preflighted as the sender when it runs. A batch key
  makes a resend return the same batch, and per-document origin keys mean a
  resumed batch never issues twice. Migration 229 (#1741).
