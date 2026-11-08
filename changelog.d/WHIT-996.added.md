- **Plugins can read the workspace's templates.** The SDK's `Templates` reader
  (SDK 1.82) lists and reads the published templates the caller can see, with
  the merge fields each one prints and where their values come from. A plugin
  can then offer a choice of template, or check what an issue needs before it
  issues. In a background job it sees only the templates bound to its own slots
  (#1740).
