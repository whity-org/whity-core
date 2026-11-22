# Stable routes and ids

For products that run the official Whity web image next to their own app
(whity-org/whity-core#2386). This page covers the route manifest and the ids
you can rely on. The pages on hiding, landing and the shared-origin setup
build on it.

## The route manifest

`GET /web-build/routes.json` lists every route the web app serves. It needs no
sign-in, like `/web-build`, and it is built into the image, so it always
describes the bundle that serves it.

```json
{
  "schema": 1,
  "core_version": "0.2.32",
  "commit": "307d74def…",
  "routes": [
    {
      "path": "/calendar/meetings/:id",
      "kind": "page",
      "area": "protected",
      "nav_ids": ["convening-meetings"],
      "settings_ids": [],
      "since": "0.2.28"
    }
  ]
}
```

| Field | Meaning |
|---|---|
| `schema` | The format's version. It changes only when a field changes meaning or goes away. New fields can appear without a new `schema`. |
| `core_version`, `commit` | The build the list describes, as on `/web-build`. |
| `path` | The path. `:name` is one segment, `:name*` is one or more, `:name*?` is zero or more. |
| `kind` | `page` is a screen. `file` is something the app serves that is not a screen (`/sw.js`, `/manifest.webmanifest`, `/openapi.json`, icons, `/web-build`). `forwarder` passes the request to the backend unchanged (`/api/*`, `/mcp`, `/.well-known/oauth-protected-resource*`). |
| `area` | `auth`: sign-in and onboarding. `protected`: signed-in screens. `editor`: signed-in, full-screen document editor. `public`: needs no sign-in (public forms, booking, verify, status) and every `file` and `forwarder`. |
| `nav_ids` | The menu items the route sits under: the ids whose link is the route's closest ancestor. Empty for routes outside the signed-in areas, and for `/admin/x/:featureId`, whose id depends on the screen (see below). |
| `settings_ids` | The same for the Settings area's pages. |
| `since` | The core version that first listed the route. Routes older than the manifest carry the version git history gives them, and `0.1.0` for anything before versions existed. |

The source is [`web/routes.json`](../../web/routes.json). It is checked in, and
`npm run routes:generate --workspace web` regenerates it after a page is added
or moved. CI (`Route manifest and stable ids`) and every web build
(`prebuild`) fail when it does not match `web/app/`. At release, the notes list
the routes added and removed since the previous release.

## Stable ids

The ids in [`web/lib/routes/stable-ids.json`](../../web/lib/routes/stable-ids.json)
keep their meaning across minor releases. We never rename one and never reuse
one. When a page goes away, its id moves to `removed` with the version, and the
release notes say so. CI fails if an id the server declares is missing from the
file, or if one in the file is no longer declared and was not moved.

There are three kinds.

- **Core menu items.** The `id` of each item on `GET /api/navigation`, registered
  in `public/index.php` (`navigation.register`) and by the calendar and
  convening features: `dashboard`, `calendar`, `contacts`, `users`, `settings`
  and the rest of `nav` in the file.
- **Plugin menu items.** `plugin-<featureId>`, where `featureId` is the id of
  the plugin's frontend feature. The item links to `/admin/x/<featureId>`. These
  are as stable as the plugin's own feature ids, which the plugin's author
  owns. They are not in the file because plugins are installed at run time.
- **Settings pages.** The `id` of each page in the Settings area: the tabs on
  `GET /api/v1/settings/tabs` (`SettingsApiHandler`), your own settings
  (`profile`, `password`, `display`, `notifications`, `signed-in`, `role`) and
  the pages the area adds (`fields`, `import`, `devices`, `document-themes`,
  `document-stamps`, `bookings`, and the workspace pages that left the sidebar:
  `billing`, `plugins`, `audit-logs`, `notification-log`, `tags`, `tag-groups`,
  `translations`). Settings ids and menu ids are separate lists: the same word
  can be in both (`notifications` is your own notification settings in one and
  the notification log's menu item in the other).

The label, icon, group and order of an item are not part of the promise. They
follow the design and can change in any release.
