- **Only a signed-in session's access token now counts as a sign-in on the API.**
  Before this, the API checked a token's signature but not its type. A temporary
  token from the password step could skip two-step sign-in, and refresh, device and
  AI app tokens acted with the person's full permissions, past an app's scope and
  after it was disconnected. A signed-out session's access token now also stops
  working at once, not when it expires. Update if you run an earlier version
  (WHIT-903).
