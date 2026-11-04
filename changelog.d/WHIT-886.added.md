- **Plugins get their own file storage and document tracking (SDK 1.63).** A plugin
  keeps files per workspace through `PluginFiles`, in the platform's storage layer,
  and a generic upload and download controller can be wired into its routes. It
  issues documents through `PluginDocuments`, which records each document as the
  plugin's, and reads back the QR verification code and scan tracking for those
  documents only (WHIT-886).
