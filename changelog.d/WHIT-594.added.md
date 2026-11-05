- **Real-engine tests for a person in two workspaces.** The people routes are
  now proven, on SQLite and PostgreSQL, to keep each workspace to its own
  memberships, roles and rows when the same person belongs to both. This is
  the first slice of the cross-tenant isolation work in #1144 (WHIT-594).
