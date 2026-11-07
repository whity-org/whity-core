- **Whity installs as an app on phones and computers.** It opens in its own window with the
  workspace's name, icon and main colour (a tenant's own domain installs as that tenant), under
  its Arabic name and right to left for Arabic readers, and keeps clear of the notch and home
  indicator. With no connection it shows a page saying so instead of the browser's error, and when
  a new version ships it offers to reload. Nothing anyone sees after signing in, and no API
  response, is stored on the device (WHIT-957, #1733).
- **Operators can switch installing off.** The "Install as an app" feature flag
  (`app.installable`), or the `PWA_ENABLED` environment variable until that flag is saved, removes
  the manifest, serves no service worker, and removes the one browsers installed earlier. It is on
  by default (WHIT-957, #1733).
