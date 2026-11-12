- **Plugins can add blocks to documents.** SDK 1.103 lets a plugin declare
  document blocks: their fields, a view and an optional print view in Whity's
  display blocks. Whity draws them with its own components, so a Verified
  plugin's block looks built in while no plugin code runs in the app. Each
  plugin has its own group in the / menu; a block without a print view is for
  notes and wiki pages only, and a block whose plugin is off keeps its data,
  says which plugin it needs, and blocks publishing (WHIT-993).
