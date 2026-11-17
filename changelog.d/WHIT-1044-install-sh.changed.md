- **`curl -fsSL https://whity.dev/install.sh | sh` now runs `npm install -g @whity/cli` and `whity install`.**
  The one-line installer is a short bootstrap for the server install: it checks for Node.js 20 or
  newer (saying where to get it if missing), installs (or updates) the `whity` command from `@whity/cli`, and hands over to
  `whity install`, with the terminal attached so its two questions still work (WHIT-1044).
