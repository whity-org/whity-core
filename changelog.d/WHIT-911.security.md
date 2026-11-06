- **User hooks no longer carry passwords or set roles.** The `user.creating`
  hook now gives plugins only the new user's email and role, and ignores what a
  plugin returns, so the person creating the user decides the role. A plugin can
  still refuse the create, and the caller then gets a 409. Plugin SDK 1.70
  records the narrower contract (WHIT-911).
