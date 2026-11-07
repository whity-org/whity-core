- **Plugin jobs can render and issue documents.** The SDK's `DocumentRenderer`
  now resolves in queue jobs and CLI commands as well as in requests (SDK 1.76).
  Each process builds one per-tenant storage stack, so a document a job issues
  is stored where the same workspace's request would store it (#1151).
