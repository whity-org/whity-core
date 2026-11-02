# Installed plugins

The Installed tab of A41: every plugin in the workspace, its state, version and billing.

- **States** (the plugin lifecycle): Active; Trial (with the end date and what it costs after, S.8); Update waiting (S.6: the update asks for new permissions or domains; the old version keeps running); Failed (didn't load; its blocks show the plugin-off placeholder, E.20; row menu offers "Try again" and "Roll back"); Turned off (by whom and when; data kept).
- **Updates** install automatically and say so in the Version column. One that needs approval also raises a warning banner above the table, naming exactly what's new, with "Review access", which opens the Access step of the install dialog showing only the changes.
- **Row menu:** Open, Settings, Details, Agent tools (opens MCP tools filtered to this plugin), Turn off / Turn on, Uninstall. Uninstall confirms with what happens to the data (S.7).
- **Details** opens a drawer: version, publisher, permissions granted, domains, who installed it and when. For a failed plugin it leads with the error in plain words, then "Technical details" (the error and stack trace), collapsed (S.11).
- **⋯ menu (S.11):** Upload plugin (a .zip of unreviewed code, up to 32 MB) and Reload plugins, shown only to people with `plugins:upload` (S.9); Store sources links to Settings › Advanced, where stores and their access tokens are kept. Everyone else sees no ⋯ button.
- **Recently removed:** for 30 days after uninstalling (S.7), with who removed it, when the data goes, and Reinstall, which restores everything.
