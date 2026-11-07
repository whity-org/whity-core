- **A delegated permission now follows its grantor.** It counts only while the
  person who delegated it still holds that permission in the workspace, so
  moving them to a smaller role or ending their membership takes effect for
  everyone they delegated to. The server also drops its cached permissions
  after every request, whichever way it is hosted (WHIT-975, #1144).
