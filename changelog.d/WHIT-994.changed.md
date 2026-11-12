- **Signing in with no workspace now leads somewhere.** Somebody the
  installation's own sign-in provider (Whity ID on app.whity.dev) signs in, who
  belongs to no workspace, used to see a "no active workspace" error, or, with
  `auth.sso_self_provision_workspace` on, got a workspace named after them
  without being asked. They now land on a new page, `/welcome`. With the setting
  on, it invites them to create their workspace: its name (their own name to
  start with), main language and main colour, then takes them straight into it
  as its owner. With it off, it says plainly that they need an invitation and
  which address they signed in with. Somebody already invited or waiting for
  approval somewhere is told their place is waiting, and is never offered a
  second workspace. The page holds only a 15-minute sign-in state, not a
  session, and creating re-checks every condition on the server, so a
  workspace's own sign-in provider is never offered the step and two clicks
  make one workspace. A headless frontend can point the page elsewhere with
  `SSO_NO_WORKSPACE_URL` and use `GET`/`POST /api/v1/auth/sso/workspace`.
