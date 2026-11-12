- **Every green push to develop publishes images.** The app, web and render
  images are built, scanned and smoke-tested the same way as a release, then
  pushed as `develop-<sha>` and, once the smoke test passes, `develop`. A
  deployment can now follow develop between releases and pin an exact commit,
  which `/api/build` reports (WHIT-951).
