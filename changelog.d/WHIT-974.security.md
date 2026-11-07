- **Workspace isolation exemptions are now only the ones that matter.** Of the
  248 places marked as reviewed exceptions to the "every query names its
  workspace" rule, 136 exempted nothing. They sat on tables shared by every
  workspace, which the check already allows. They are now plain comments, and
  the check fails if a marking that exempts nothing comes back. Looking up a
  translation by id now only finds the global text and the caller's own
  workspace's text. Two lookups that read across workspaces are held to the
  callers that check the result themselves (WHIT-974, #1144).
