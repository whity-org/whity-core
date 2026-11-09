- **Upgrading an existing install no longer takes the API down.** The app
  container now starts on a database that has not been migrated yet, so the
  migrations can run inside it. On v0.2.27 it restarted forever until they were
  run some other way. The upgrade steps now say which command to use for image
  deployments, and every change is tested by upgrading from the last release
  (WHIT-930).
