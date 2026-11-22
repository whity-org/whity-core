# Hiding pages

For products that run the official Whity web image next to their own app
(whity-org/whity-core#2386). An operator can remove Whity pages they don't want
from the whole installation, by the ids in [Stable routes and ids](Stable-Routes-And-Ids.md).

## How to hide a page

Use either of these, or both. A page hidden by either one is hidden.

- **The environment variable** `WHITY_HIDDEN_NAV_IDS` on the backend (`app`)
  container, for example:

  ```bash
  WHITY_HIDDEN_NAV_IDS=calendar,boards,tasks,settings.import
  ```

  Restart the backend to apply a change.
- **The instance setting** `navigation.hidden_ids`: Settings › General
  (`/admin/settings`) in the system workspace, section **Pages**, or the
  settings API (`PATCH /api/v1/settings` in the system workspace, key
  `navigation.hidden_ids`).
  It applies without a restart; the web server's route guard sees it within 30
  seconds.

The value is a list of ids separated by commas:

| To hide | Write | Example |
|---|---|---|
| A menu item and its pages | its menu id | `calendar`, `contacts`, `users` |
| A plugin's screen | `plugin-<featureId>` | `plugin-reports` |
| One Settings page | `settings.<id>` | `settings.sso`, `settings.import`, `settings.profile` |

The setting refuses `website-settings` and `settings.general`, because those
are the pages where it is changed. The environment variable can hide them.

## What happens

- The item leaves every menu: the sidebar, the phone tab bar and More page,
  the command palette (its Go to entry and any "New …" command that opens it),
  the Settings area, the settings search, and the account menu.
- Its pages answer **404** with the app's normal "not found" page, for
  everyone, signed in or not. A menu id covers every page under its link: hiding
  `calendar` hides `/calendar/rooms` and `/calendar/services/12`, but not
  `/calendar/meetings`, which is `convening-meetings`. The route manifest
  (`/web-build/routes.json`) lists each route's ids.
- Public pages are never hidden. Hiding `chat` hides the team's `/chat`, not a
  workspace's public chat page at `/chat/<workspace>`.

Hiding is not a permission. The API behind a hidden page keeps its own
permission checks and stays reachable to callers who hold them, for example an
integration or an MCP client. To take access away, change the roles.

## How it works

- The backend reads both sources in one place,
  `src/Core/Navigation/HiddenNavIds.php`, and leaves hidden items out of
  `GET /api/navigation` and `GET /api/settings/tabs`.
- `GET /api/navigation/hidden` (public) returns the list as
  `{"data": {"nav": [...], "settings": [...]}}`.
- The web server's route guard (`web/proxy.ts`) asks for that list, keeps it
  for 30 seconds, maps the requested path to its ids through the route
  manifest, and answers 404 for a hidden page. If the backend can't be
  reached, it uses the last list it got, and hides nothing before the first
  one.
