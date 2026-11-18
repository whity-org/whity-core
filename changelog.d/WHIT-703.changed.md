- **A refused cross-site request now says what is missing.** The 403 names the
  `X-Requested-With: XMLHttpRequest` header, so an API client can fix its
  request without guessing (WHIT-703).
