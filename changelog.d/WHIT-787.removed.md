- **The deployments API is gone.** `POST /api/deployments/apply`,
  `POST /api/deployments/rollback` and `GET /api/deployments/status` are removed,
  with their entries in the OpenAPI document and the typed client. Nothing in Whity
  called them, and updates ship as container images (see Core Update) (WHIT-787).
