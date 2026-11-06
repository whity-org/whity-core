- **Migration routes check a permission, not a role name.** Listing migrations
  needs `migrations:read`, and running or rolling them back from the command line
  needs `migrations:manage`. Upgrading grants them to the roles that already read
  the server statistics (and, for `migrations:manage`, may also install plugins),
  so the administrator keeps access under any role name (WHIT-771).
