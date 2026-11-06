- **Plugin role seeding stays within the plugin's own grants.** A plugin now grants
  only permissions it declares itself, never core ones, and only to roles it
  created or the operator approved for it. Uninstalling a plugin removes only the
  grants that plugin made (WHIT-913).
