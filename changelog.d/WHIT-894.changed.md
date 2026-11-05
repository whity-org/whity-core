- **People can connect their own AI apps without an admin.** Anyone signed in can
  create, revoke and adjust their own connections, and each one can still do no
  more than they can. `mcp:tokens:manage` now only covers seeing and revoking
  everyone's. These routes also accept a bearer access token, for native apps and
  headless frontends (#1623).
