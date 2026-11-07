- **Every route is now tested against crossing between workspaces.** A new
  test starts the real app, signs in as one workspace's administrator and
  calls every route and verb with another workspace's ids, also naming that
  workspace or the system workspace in the request. It fails if anything comes
  back from the other workspace, if a row outside the caller's workspace
  changes, if the caller's rows end up pointing at the other workspace, or if a
  sign-in token for another workspace is handed out. It also plants routes that
  leak on purpose and fails if any of them goes unnoticed (WHIT-971, #1144).
