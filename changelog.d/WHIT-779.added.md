- **Unit and role names without the directory.** `POST /api/v1/labels/resolve`
  names a few organizational units and roles by id for a caller holding the
  permission of the screen asking (`documents:read`, or `documents:route` for
  routing), not `ous:read` or `roles:read`. It returns names only, from the
  caller's own workspace; unknown and other-workspace ids are left out, and
  each request is capped by the new `labels.resolve_max_ids` setting (default
  100, per workspace). The document library, the document record and the
  routing panel now name units and roles through it (#1019, WHIT-779).
