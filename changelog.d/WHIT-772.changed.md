- **The plugin tenant-isolation check now covers every plugin, not just
  HelloWorld.** CI runs the SDK conformance kit over each plugin under
  `plugins/`, bundled or installed, reading each plugin's tenant tables from its
  own migrations and scanning all of its code outside migrations. Migrations are
  left out of tenant scoping by path, so their `@tenant-guard-ignore` tags are
  gone and the reasons stay as plain comments (WHIT-772, #1262).
