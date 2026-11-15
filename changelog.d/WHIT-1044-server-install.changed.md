- **Installing Whity is now `npm install -g whity`, then `whity install`, on your own server
  with your own domain.** The owner chose the installer card's server install over the local one.
  `whity install` asks for the web address and your email, checks the server first ("Nothing was
  changed" when something is missing), downloads the latest release, starts it, puts Caddy in
  front to get a Let's Encrypt certificate, makes your owner account and ends with the address and
  `whity update`. It shows ✓ and ✗ marks, speaks Arabic when the system language is Arabic, and
  `--verbose` shows the detail. `curl -fsSL https://whity.dev/install.sh | sh` still works: it now
  checks for Node.js 20 and runs the same two lines (WHIT-1044).
- **`whity install` and `whity update` no longer fail on Docker Compose 2.33 and newer.** They
  waited on every service, and Compose refuses to wait on the workers, which turn their
  healthcheck off, even though everything was running. They now start everything, then wait for
  the services that have a healthcheck. `whity update` also works from inside the install folder
  (WHIT-1044).
