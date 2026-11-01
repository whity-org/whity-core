---
"@whity-org/features": minor
---

Add `local` to the MCP client specs in `@whity-org/features/mcp` (WHIT-823): for each app, the executables and per-OS config files that show it is installed, whether a URL-only entry makes it sign in by itself (`signsInItself`, distinct from `requiresSignIn`) or it needs a local command bridge (`stdio`), and its own command to add a server (`addCommand`). Never a token. Marked `verified: false` until whity-agent's detectors are checked against each vendor's docs.
