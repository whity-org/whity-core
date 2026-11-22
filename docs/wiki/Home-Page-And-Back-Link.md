# Home page and back link

For products that run the official Whity web image next to their own app
(whity-org/whity-core#2386). Two instance-wide choices: where people land, and
a way back to your own app.

## The home page

Out of the box, people land on `/dashboard` after signing in, and every "home"
link goes there. Choose another page with:

- the instance setting `navigation.home_path`: Settings › General
  (`/admin/settings`) in the system workspace, section **Pages**; or
- the environment variable `WHITY_HOME_PATH` on the backend container, used
  while the setting is empty.

The value is a path on this site, such as `/admin/x/forms-catalog` or
`/tasks`. Addresses on other sites are refused, so a sign-in can never be
redirected off the site.

What follows it:

- the landing page after every kind of sign-in and sign-up: password, two-step,
  SSO (unless `SSO_SUCCESS_URL` is set, which still wins), registration, the
  welcome page, workspace set-up and payment;
- the sidebar logo, which is now a link;
- the phone tab bar's Home tab;
- the not-found page's button, and the back links on the new-workspace and
  ownership-offer pages;
- `/` and `/admin` for a signed-in person;
- "Open Whity" in workspace-ownership emails and notices.

If you hide the dashboard (see [Hiding pages](Hiding-Pages.md)), set a home
page too. Otherwise the Home links lead to a page that answers 404.

## The back-to-product link

A link at the top of the sidebar, and at the top of the phone's More page,
back to the app Whity runs beside. It is off until it has an address:

| Setting | Environment variable | Value |
|---|---|---|
| `navigation.back_to_product_url` | `WHITY_BACK_TO_PRODUCT_URL` | A path on this site, such as `/` or `/courses`, or an `https://` address |
| `navigation.back_to_product_label` | `WHITY_BACK_TO_PRODUCT_LABEL` | The words on the link, up to 60 characters, such as `Back to Example Bakery`. `Back` when empty |

A stored setting wins over the environment variable.

## For a client

`GET /api/branding` (public) carries both, so a page renders them from its
first paint:

```json
{
  "data": {
    "siteName": "Whity",
    "homePath": "/admin/x/forms-catalog",
    "backToProduct": { "url": "/", "label": "Back to Example Bakery" }
  }
}
```

`backToProduct` is `null` when there is no back link.
