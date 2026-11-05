- **Outgoing email can sign in with OAuth2 as well as a password.** Choose OAuth
  in the Email settings and enter a Google or Microsoft client ID, client secret
  and refresh token. Whity fetches and refreshes the access token itself, and the
  screen says in plain words when the account needs reconnecting. Secrets are
  encrypted at rest and never shown again, and installs using a password carry on
  unchanged (WHIT-901).
