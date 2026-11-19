---
"@whity/api-client": patch
---

Token mode keeps the token pair `POST /api/v1/me/logout-others` re-issues, so
signing out other devices no longer signs out this one on its next request
(#2219).
