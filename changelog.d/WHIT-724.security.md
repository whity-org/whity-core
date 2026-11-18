- **The desktop app template reads plugin packages with a patched zip library.**
  zip 4.6 had a critical and a high advisory; the template now uses zip 9.7,
  together with tauri-plugin-updater 2.14.1 (which needed the same bump) and the
  Tauri 2.12 it brings. A package entry whose name is not valid UTF-8 is now
  refused with a plain message (WHIT-724).
