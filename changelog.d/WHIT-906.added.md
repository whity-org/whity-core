- **Plugins can take forms from anonymous visitors, with no proxy.** A route declared
  `'public' => 'form'` on POST `/api/<slug>/forms/<name>/{key}` is reached only through checks the
  host runs: a per-address limit, a ceiling for each form and workspace, a size cap, an allowed-sites
  check and a bot check (Cloudflare Turnstile to start with). The workspace comes only from a key an
  admin issues and can revoke in Settings, Public forms, never from the request. With no bot check
  set up, forms stay closed unless the operator chooses to allow that (WHIT-906, SDK 1.67).
