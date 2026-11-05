- **Ask whether a document would issue before issuing it.** `POST /api/documents/preflight`
  and `Documents::preflight()` take the same request as an issue and return every check with
  its reason, including a plugin's own. Nothing is written and no number is allocated, and the
  real issue still checks everything again (WHIT-892, #1629, SDK 1.66).
