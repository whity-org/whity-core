- **Plugins can declare and customize agents (SDK 1.64).** A plugin implements
  `PluginAgentsInterface` to declare agents under its own name: a persona in English
  and Arabic, its toolsets, where a person's grant starts, and the feature flags the
  host offers. A workspace starts an agent from one as its own copy, and a later
  declaration never changes an agent already made. A plugin cannot declare an agent
  under another plugin's or core's name, cannot start a write at Allowed, and cannot
  switch on a feature the host does not expose (WHIT-893).
