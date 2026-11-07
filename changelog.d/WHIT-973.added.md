- **A workspace can have its name in Arabic and English, and say which one is
  the original.** For many institutions the Arabic name is the legal one, so
  English is no longer assumed. The workspace record edits both names, and the
  workspace switcher, home page and workspaces list show the reader's language,
  falling back to the original. Search finds a workspace by either name. The
  names live in a new `localized_texts` table that organizational units use
  next (#821, WHIT-973).
