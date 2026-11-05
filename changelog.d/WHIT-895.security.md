- **A development dependency is updated for a Symfony path advisory.**
  `symfony/filesystem` moves to v8.1.11 for GHSA-6prc-h956-hxjq. It is a
  development tool here and isn't in the production image. Composer now resolves
  dependencies for PHP 8.4.27, the version the image runs (WHIT-895).
