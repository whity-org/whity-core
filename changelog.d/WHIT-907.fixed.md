- **Upgrading now loads a release's new labels.** `migrate run` also loads labels
  in every language, so an upgraded install no longer shows English for labels added
  since it was first seeded. Strings edited in `/admin/translations` and workspace
  overrides stay as they are. If you've upgraded to 0.2.25 already, run
  `php bin/whity-cli i18n:sync --all` once and restart the app, or wait for the next
  release (WHIT-907).
