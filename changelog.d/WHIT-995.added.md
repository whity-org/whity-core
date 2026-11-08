- **Plugins ship starter templates.** A workspace plugin declares templates
  under `documents.starters` in its plugin.json (SDK 1.81). When the plugin goes
  live in a workspace, each is added as a published template every member can
  see, and bound to the plugin's slot when that slot has no template, so the
  plugin can issue at once. It is added once per workspace. The workspace's
  edits are never overwritten, and a copy nobody changed follows the plugin's
  updates (#1739).
- **Arabic starters have Arabic samples.** The editor's Arabic starter
  templates showed English example names and months in their field samples;
  they are Arabic now (codes and amounts stay as they are). New workspaces get
  the fix; an existing workspace keeps the copy it has.
