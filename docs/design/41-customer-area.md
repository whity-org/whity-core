# 41 · The customer area: a signed-in place for a workspace's customers

**Status: decided by the owner on 25 November 2026 (WHIT-1414): "Accept all (Recommended)", every question in section 16 as recommended.** The register rows are CA.1–CA.24 (section "Customer area"). Building starts with milestone CA1 (section 15).

The owner, 25 November 2026, after reading the LMS gap list (WHIT-1411, `docs/wiki/Plugin-SDK-LMS-Gaps.md`): "for lms student facing pages is very big point for whity and can be generalized for lot's of applications".

A workspace's customers today get links: a booking link, a quote link, a chat box, a form. Each works alone and none remembers them. The customer area is one signed-in place, in the workspace's brand, where a customer sees everything the workspace has for them, and where plugins add their own tabs. It serves:

- **Ordinary customers** of a shop or service: their quotes, invoices, orders and messages.
- **Salon clients**: their bookings, book again, their receipts.
- **Clinic patients**: their appointments, the forms to fill before a visit, their bills. Visit records stay staff-only (AP.21).
- **LMS students**: a Courses tab from the learning plugin, with a full-screen lesson player.

Business model (BM.13, BM.14, 21 November 2026): the customer area is core and free on every plan and on self-hosted installs. A customer who signs in to it counts on the customer meter on Whity Cloud, as BM.3 already says ("uses the customer app or portal"). Taking money online needs Whity Pay (BM.16). Core shows what is due and how to pay by hand.

Native mobile apps are out of scope for 0.2.29. The customer area is the web app, it installs on a phone (PW.11), and it is what the future customer app (MB50, `TmCustomerApp`, M.31) will draw.

## 1. What exists today (origin/develop at 27aa33cce)

- **Customers as members.** `memberships.kind` is `member` or `customer` (migration 222, WHIT-915). Customer sign-up (`/join/[workspace]`, open or approval mode) or SSO with `?join=` makes a customer membership. Every active membership of the person in that workspace being a customer caps their permissions (`RoleChecker`, `CustomerSafeRole`): no `manage`, `delete`, `approve`… and nothing under admin resources. `/api/v1/me` returns `kind` per membership. The hook `membership.customer_joined` (SDK 1.73) fires on join.
- **Nowhere to go after signing in.** A customer who signs in lands on `/dashboard` in the staff shell (`FrontendUrls::SSO_SUCCESS`), filtered down by RBAC to almost nothing. The only customer-aware code in the web app hides Create workspace (`use-workspace-create.ts`) and the role page.
- **Customers are contacts.** `contacts.profile_id` links a contact to a person who signs in. `CustomerContacts::forCustomer()` finds a contact by email, then mobile, then profile, or creates one (`is_customer`). Migration 291 put `contact_id` on bookings, web chat visitors and sign-ups.
- **What core holds for a customer**, and how each row points at them:

  | What | Table | Points at the customer by |
  | --- | --- | --- |
  | Bookings | `calendar_bookings` | `contact_id`, `customer_email`; `customer_profile_id` only for staff-made bookings (public bookings write null) |
  | Quotes | `sales_quotes` | `contact_id` (required) |
  | Invoices and credit notes | `acc_invoices` | `contact_id` (required) |
  | Payments and receipts | `acc_payments` | `contact_id` |
  | Orders | `inv_orders` | `contact_id` (required) |
  | Conversations | `conversations` (`kind` customer) | `customer_profile_id`, and `conversation_contacts` |
  | Form submissions | `form_submissions` | `submitted_by_profile_id` (a staff "My form submissions" page exists) |

- **Public pages** (`PublicShell`, `web/components/auth/public-shell.tsx`): `/book/[workspace]`, `/booking/[token]`, `/chat/[workspace]`, `/f/[slug]`, `/join/[workspace]`, `/quote/[token]`, `/verify/[token]`. Each wears the workspace's logo and colour (`BrandingService::forPublicPage()`, `WorkspaceBrandProvider`) and needs no sign-in (SU.1, SU.10, SC.12). The links are 256-bit tokens, stored hashed.
- **The workspace page `/@handle`** is decided (HD.5, section 36 Q10) as the one address to share, served from `web/app/w/[handle]` by a rewrite in `web/proxy.ts`. It is planned in milestone H2 and not built yet.
- **Brand.** `branding_logo_wide`, `branding_logo_square`, `branding_favicon`, `branding_main_color`, `branding_theme`. A workspace can have its own host (`tenants.branding_host`, `HostResolver`); today it changes only what is shown, never who is signed in.
- **Plugins.** `PluginFrontendInterface` screens (`crud`, `action`, `blocks`, `embed`, `custom`) all render in the staff app at `/admin/x/[featureId]`. `embed` frames talk over the `frame/1` bridge (`sdk/src/Frontend/FrameBridge.php`, `web/lib/plugin-frame-bridge.ts`) with a 5-minute token bound to the plugin's route namespace and the session. `PluginCustomerPermissionsInterface` (SDK 1.104) lets a plugin say which of its permissions reach only the caller's own records, so customers keep them. Core does not check the "own records" promise itself. Plugin public routes are only signed `webhooks`, anonymous `forms` and keyed `read` links. The SDK is at 1.168.
- **The design** already describes the shape: `TmCustomerApp` (MB50, `docs/design/components/TmCustomerApp/`) and `TmCreatorCustomer`: the tenant's brand, "the tenant's plugins decide the tabs and pages", "Whity supplies the shell, sign-in, payments and notifications", tabs Home, Orders, New order, Messages, Account, an account page with Whity ID and "Built on Whity", and no story, tour, Manage or agent approvals (M.29, M.31, 26-mobile "The customer app").

## 2. The gaps

1. **No signed-in place.** A customer who signs in sees the staff shell, cut down.
2. **Customers can't see their own things.** No list of their bookings, quotes, invoices, orders or conversations. Every link is a separate page that forgets them (the CRM survey found the same).
3. **Plugins can't reach customers.** No plugin screen renders outside the staff app (LMS gap 1).
4. **"Own records" is a promise, not a check.** Core trusts a plugin's `PluginCustomerPermissionsInterface` list. Nothing stops a plugin route that a customer can call from returning another customer's rows.
5. **Public bookings don't remember the person.** `customer_profile_id` is null on every public booking. Only `contact_id` and the email tie it to them.
6. **Quote links may be broken for people who aren't signed in.** `/public/quotes/*` is not in `EnforceTenantIsolation::PUBLIC_ROUTE_PATTERNS` (READINESS CE-21, "To verify"). This is checked and fixed in milestone CA1, since the Quotes tab links to the same page.

## 3. Words

| English | Arabic | Where |
| --- | --- | --- |
| Customer area | منطقة العملاء | Staff side only: Settings › Customer area, help |
| Your account | حسابك | What the customer sees on the sign-in button and in the header ("Example Bakery · Your account") |
| Home | الرئيسية | Tab |
| Bookings | الحجوزات | Tab |
| Bills | الفواتير | Tab: quotes, invoices, receipts, orders (Q6) |
| Messages | الرسائل | Tab (Q7) |
| Account | الحساب | Tab |
| Sign in | تسجيل الدخول | |
| Pay now | ادفع الآن | Only with a payment plugin (BM.16) |
| How to pay | طريقة الدفع | Without one: the workspace's own payment instructions |

Customers never see the words "customer", "portal", "tenant" or "workspace". They see the business's name.

## 4. Address and brand

- **Address.** The area lives under the workspace page: `/@handle` is the front door (HD.5), and the area's tabs are `/@handle/home`, `/@handle/bookings`, `/@handle/bills`, `/@handle/messages`, `/@handle/account`, and plugin tabs at `/@handle/x/{screen-id}` (and `/@handle/x/{screen-id}/{recordId}`). Like `/@handle` itself, the proxy rewrites these to `web/app/w/[handle]/…`. A signed-in customer opening `/@handle` goes straight to Home. Anyone else sees the public workspace page with Sign in next to Book, Chat and Join (CA.1).
- **The handle never decides access** (HD.5). It only names the workspace. Every API call checks the person's active membership in that workspace.
- **Brand.** Every screen wears the workspace's logo, name and main colour, from the same `forPublicPage()` brand the public pages use, so the `primary` token takes the workspace's colour in light and dark (CA.2). "Built on Whity" sits at the foot of Account only, and the workspace can switch it off for free (BM.5 as changed by BM.14).
- **Own domain, later.** When a workspace's own host (`branding_host`, say `my.examplebakery.jo`) is set up for sign-in, the area moves to that host's root (`/home`, `/bookings`…) and `/@handle/…` redirects there. That needs a session cookie on the workspace's host and Whity ID redirect URIs per host, so it is its own milestone (CA6) after the area works on the main address (CA.3, Q3).
- **Installable.** Each workspace's area has its own web manifest (`/@handle/manifest.webmanifest`, scope `/@handle/`) with the workspace's name, square logo and colour, so "Install on this phone" (PW.11) installs "Example Bakery", not Whity (CA.4).

## 5. Signing in and joining

- **Sign-in page.** `/@handle/sign-in`, in the workspace's brand (CA.5). It offers what the installation offers:
  - **Whity Cloud** (app.whity.dev): Continue with Whity ID only (BM.12). Whity ID already does phone and email codes, Apple and Google, and up to 5 accounts per phone.
  - **Self-hosted**: the install's own sign-in, the same methods `/login` offers (password, SSO providers, Whity ID if configured). No new sign-in method in core.
- **After sign-in** the person comes back to the area page they asked for, never to `/dashboard`. `FrontendUrls` gains a customer return: when sign-in starts from `/@handle/…`, the return path is kept (same-origin, path only).
- **Not a customer yet.** The person signed in but has no membership in this workspace:
  - **Sign-up open:** one tap, "Join Example Bakery", reusing customer sign-up (WHIT-915) with the identity they just proved, no second form.
  - **Sign-up with approval:** "Ask to join". Until approved they see a plain "Example Bakery will let you know" page.
  - **Sign-up off:** "Example Bakery hasn't opened accounts. Ask them for an invitation", with the workspace's Chat button if chat is on.
- **Invited by staff.** A contact record gains **Invite to their account** (permission `contacts:write`). It sends a branded email (EM.1) to the contact's address. Accepting signs in or creates the identity and makes a customer membership linked to that contact. This works whatever the sign-up mode (CA.6, Q4).
- **Staff who are also customers.** A person can be staff in one workspace and a customer of another, or both in the same one. The area always shows them as a customer of the workspace in the address: their own records only, with the customer cap applied even if they are staff there. The account menu has "Open the team app" when they are staff (CA.7).
- **Links in emails.** Booking confirmations, quotes, invoices and chat replies gain a second link, "See it in your account", next to the token link. The token links keep working without sign-in. Nobody is ever forced to make an account to see a quote or move a booking (CA.8).

## 6. Who sees what: their own records

This is the part that must never be wrong.

- **Which contact is "me".** In a workspace, the signed-in customer's records are those of the contacts whose `profile_id` is their profile (CA.9). A profile gets linked to a contact:
  - at sign-up and join (already, `customer_signups.contact_id`);
  - when staff send Invite to their account from that contact;
  - automatically **only through a verified address**: on sign-in or join, a contact in that workspace with the same **verified** email (confirmed by customer sign-up or by Whity ID's `email_verified`) or the same **verified** phone (Whity ID) is linked, if it has no profile yet. An unverified match is never linked (Q5).
  - Staff can link or unlink by hand on the contact record, and it's in the audit log.
- **Core tabs** are served by a new customer API, `/api/v1/customer-area/{handle}/…` (CA.10). Every query is filtered in core by `tenant_id` and `contact_id IN (my contacts)` (and `customer_profile_id = me` for conversations, `submitted_by_profile_id = me` for forms). There is no id in the URL that the server trusts without that filter. Another customer's id answers 404, the same as an id that doesn't exist.
- **Fields.** Only what the customer is meant to see: a booking's time, service, staff name and place, never internal notes or no-show flags (AP.21). A quote or invoice as its issued document (the same PDF the token link shows) and its status, never internal notes, cost prices or margins.
- **Plugin screens** follow the rules in section 9: deny by default, own records checked by a test kit, and the customer's contact ids handed to the plugin by core, never taken from the request.

## 7. Navigation and tabs

- **Desktop and tablet (640px and up).** A top bar with the workspace's logo and name at the start, the tabs as a row in the middle, and the account menu (avatar, language, sign out) at the end. No sidebar: customers have few places to go, and the staff sidebar's groups mean nothing to them (CA.11). From 7 tabs on, the rest go into a More menu at the end of the row.
- **Phone (under 640px).** The phone tab bar (`@whity/ui/phone-tab-bar`, PW.1 and PW.3) with up to 5 tabs. With more than 5, the bar is Home, the next 3 in order, and More. More lists the rest with Account first, like PW.2 (CA.12).
- **Order.** Home is always first and Account always last. Between them come the core tabs and plugin tabs in the order the workspace sets in Settings › Customer area. The default is Home, then plugin tabs by their `order`, then Bookings, Bills, Messages, then Account.
- **A tab shows only when it has a reason to** (CA.13):
  - Bookings: online booking is on, or the customer has any booking.
  - Bills: the customer has any quote, invoice, receipt or order.
  - Messages: customer chat is on, or the customer has a conversation.
  - Plugin tabs: the customer holds the screen's permission (section 9).
  - The workspace can hide any core tab except Home and Account.
- **Counts.** Messages shows unread messages. Bills shows what is waiting for them (quotes to answer, invoices due). Plugin tabs may declare a count (section 9).
- **No staff things.** No command palette, no agents, no approvals, no Create, no settings, no story or tour (M.31).

## 8. Core tabs

Each tab follows section 21's list and record pages and the six states, with the customer's words.

### 8.1 Home

What needs them first, then what's coming (CA.14):

1. **Waiting for you**, one card each, in the workspace's colour: a quote to answer, an invoice due (Pay now or How to pay), a form to fill before a booking, a booking that needs confirming.
2. **Next booking**: when, what, who and where, with Move, Cancel and Add to calendar.
3. **Messages**: the latest unread message, with Reply.
4. **Plugin cards**: small `blocks` cards plugins add to Home (section 9), such as "Continue lesson 4".
5. **Quick actions**: Book again (the last service), Start a chat, and the workspace's public forms marked for customers.

An empty Home says so in plain words and offers Book or Chat, whichever is on.

### 8.2 Bookings

- **Upcoming** and **Past** as segments. Each row has the date and time in the workspace's zone (or the branch's, AP), the service, the staff member's name and avatar, the place, and the status in words.
- A booking opens its page with Move, Cancel and Add to calendar, under the same rules as `/booking/[token]`: the window, late-cancel counting and deposits (AP.3, AP.4, AP.13). A customer's booking asks before it moves (CV).
- **Book** is the main button. It opens the booking flow already filled with their name, email and phone (WHIT-1179), and the booking it makes carries their `customer_profile_id` and contact (closing gap 5).
- Forms tied to a booking (intake forms, AP) show on the booking as "Fill in before your visit".

### 8.3 Bills

One list of documents with a Kind filter: Quotes, Invoices, Receipts, Credit notes, Orders (CA.15).

- Each row has the number (Geist Mono), the date, the amount in its own currency (CU.1–CU.4) and the status in words: "Waiting for you", "Accepted", "Due 12 Dec", "Paid", "Overdue".
- A quote opens the same view as `/quote/[token]`, with Accept and Decline.
- An invoice shows the issued PDF, what's due, and either **Pay now** (only when a payment plugin is installed and configured, BM.16) or **How to pay** (the workspace's own instructions, a core setting: bank transfer, CliQ alias, cash at the desk).
- Receipts and credit notes are read-only, with Download.
- An order shows its lines and status. Tracking steps are left for later (section 17).

### 8.4 Messages

The customer's conversations with the workspace (UC.1–UC.13), from the customer's side (CA.16):

- A list of conversations, newest first. One is normal; a workspace may have several, by topic or branch.
- The thread uses the customer chat parts already in `/chat/[workspace]`, signed in, so there is no chat token to lose and the history stays.
- Staff, bots and agents appear as the workspace says (UC.3). Agents' replies to customers still follow approvals (UC.13).
- **New message** starts a conversation when there is none open.

### 8.5 Account

- Name, avatar (M.37 pattern), email and phone. Changes go through Whity ID where it owns them (Whity Cloud), or the install's profile page.
- Language (English or Arabic), and notifications: email always; push on this device when installed (PW.11), asked once and never marketing.
- **Your forms**: the forms they sent this workspace, with each one's status and printout.
- **Your other places**: the other workspaces they are a customer of, each with its logo, a link to its area, and a link to My workspaces (MW.2, "Where you're a customer").
- **Download my data** (their own records in this workspace, a ZIP like MW.5 cut to them) and **Leave Example Bakery** (ends the customer membership, keeps the records with the business, confirmed by a plain dialog, MW.4).
- Whity ID ("also works in other apps built on Whity") on Whity Cloud, Sign out, and "Built on Whity" unless switched off.

## 9. Plugin tabs: `Contribute\PluginCustomerScreensInterface`

An additive SDK contract (1.x, owner 24 November 2026). Shape (CA.17):

```php
namespace Whity\Sdk\Contribute;

interface PluginCustomerScreensInterface
{
    /** @return list<array<string, mixed>> */
    public function getCustomerScreens(): array;
}
```

Each descriptor:

| Key | Meaning |
| --- | --- |
| `id` | Kebab-case, unique across plugins (the same rule as staff features). |
| `label` | Default English text. The Arabic comes from the plugin's translations, as staff screen labels do today. |
| `placement` | `tab` (a tab in the navigation), `home` (a card on Home, `blocks` only, at most 3 blocks deep), or `page` (no tab; reached from a link, a Home card or another screen). |
| `screen` | `blocks` or `embed`. `crud`, `action` and `custom` are staff kinds and are refused here. |
| `requiredPermission` | Required. It must be one of the plugin's customer-grade permissions (`PluginCustomerPermissionsInterface`). Anything else and the screen is not registered, with a logged reason. |
| `blocks` | The same block vocabulary and validation as staff `blocks` screens. Data-bound blocks' `apiPath` must be one of the plugin's routes that has `customer: true` (below) and the same permission. |
| `embed` | `{path, fullScreen?}`. `path` is the plugin's own GET route with `customer: true` and the same permission. `fullScreen: true` hides the area's navigation behind a Close button, for a lesson player or a test. |
| `icon`, `order` | As for staff screens. The icon is one of the 137 Tabler names. |
| `count` | Optional `{apiPath}` returning `{count: int}`, shown as the tab's count. Same route rules as `blocks`. |

**How core keeps it to their own records** (CA.18):

1. **Deny by default.** A plugin route is callable from the customer area only when its declaration in `getRoutes()` has the new key `customer: true` and a `requiredPermission` from the plugin's customer list. A customer session calling any other plugin route gets 403 before the handler runs. This closes gap 4 for every route that doesn't opt in, whatever the plugin's permission list says.
2. **Core tells the plugin who the customer is.** On a `customer: true` route, `Http\CustomerCall::from($request)` gives the profile id and the list of contact ids in this workspace (section 6), resolved by core. The plugin scopes its queries by them and never trusts an id from the request body or path.
3. **The test kit checks it.** The SDK test kit gains `CustomerIsolation::assert…`: it calls each `customer: true` route as customer A with customer B's record ids and expects 404. It runs in the plugin's own tests and in store review. Verified plugins (store) must pass it.
4. **Frames.** A customer `embed` uses `frame/1` unchanged, with the 5-minute frame token now also bound to the customer audience: it reaches only the plugin's `customer: true` routes. `init` gains `audience: 'customer'` and `brand` (name, colour, logo URL), so a frame can match the workspace's look. The host page asks for the token through the customer API, so a staff session's token never leaks into a customer frame.
5. **No privileged slugs.** A screen's permission must already pass `CustomerGradePermissions`. Nothing in this contract widens what a customer can hold.

**Who gets the plugin's tab.** A customer holds a plugin permission only through their role, which the workspace admin chooses (WHIT-915). When a plugin with customer screens is enabled, Settings › Customer area lists its screens with a switch each (on by default). Turning one on adds its permission to the workspace's customer sign-up role and to existing customers' roles, after the admin confirms (Q8).

**Hooks.** `customer_area.opened` is not needed: plugins learn of a new customer through `membership.customer_joined` (SDK 1.73) as today. A new `CustomerAreaNotifier` lets a plugin put a "Waiting for you" card on Home (a title, a link to one of its screens, an optional due date) for one customer, so a course can say "Your next lesson is ready" (CA.19).

**What it serves.** An LMS plugin declares a Courses tab (`blocks` with a `dataList` of the student's courses), a course page (`page`, `blocks`), a full-screen lesson player (`page`, `embed`, `fullScreen`) and a "Continue lesson" Home card. A clinic plugin might add Treatment plans. A salon needs nothing beyond the core tabs.

## 10. Phone (under 640px)

Following PW.1–PW.11 and the `Pw*` cards (CA.20):

- The tab bar at the bottom (section 7), padded for the safe area, hidden while the keyboard is open, with the main button riding on the keyboard (PW.3).
- The top bar keeps only the logo, the screen title and the avatar.
- Lists are stacked rows of 48px or more, 17px text. Filters (Bills' Kind, Bookings' Upcoming or Past) are a segmented control or a sheet (PW.4).
- Record pages put their actions in a bottom bar: one main button (Pay now, Accept, Move) and an outline secondary.
- Full-screen plugin frames take the whole screen under a slim bar with Close.
- Messages open full screen with the composer at the bottom.
- Install and notifications follow PW.11 in the workspace's name.

## 11. Arabic

- Every string has English and Arabic. The area's strings go in a new i18n domain `customer` (`database/i18n/customer.json`, `database/i18n/ar/customer.json`). The direction follows the customer's language with logical CSS properties, and the bottom bar's order mirrors (CA.21).
- **First visit language:** the customer's own choice if they have one. Otherwise the workspace's default language (the name language it was created with), then the browser's.
- Dates, times and amounts use Western digits and stay left to right (CLAUDE.md). Month and weekday names follow the language.
- Workspace and service names show in the customer's language when the workspace has both (WHIT-973 localized names).
- Plugin screens get `locale` and `dir` in `frame/1`'s `init` as today, and the block renderer mirrors as it does for staff.

## 12. The staff side: Settings › Customer area

A new settings page (`settings:write`), following section 21 and PW.8 on a phone (CA.22):

- **On or off.** Off hides Sign in on `/@handle` and turns the area's API off. Customers' records stay. Q2 asks whether new workspaces start with it on.
- **Tabs:** each core tab's switch (except Home and Account) and the order, plugin screens' switches (section 9).
- **Welcome line** on Home, in English and Arabic.
- **How to pay** text, in English and Arabic.
- **Preview as a customer:** opens the area with the example customer (Ahmad Khalil, WS.4) and example records, read-only, marked "Preview" (Q9).
- **Your address:** `/@handle`, with Copy and the QR (HD.5).

## 13. Security

- **Tenant and customer isolation** are enforced by the customer API itself (section 6), not by the web app. The isolation tests that exist for staff routes (WHIT-971) gain a customer case for every new route: customer A with customer B's ids, and a customer of workspace X calling with workspace Y's handle.
- **The cap still applies.** The area never calls staff routes. The staff `/api/v1/…` routes keep answering a customer session with the RBAC cap as today.
- **No ids trusted from the client.** "My contacts" is always resolved on the server from the session's profile.
- **Linking by address** only on verified email or phone (section 6). A wrong link would show one person's bills to another.
- **Sign-in returns** are path-only and same-origin, so they cannot become an open redirect.
- **Rate limits** on the customer API per person, like the public routes.
- **Audit:** joining, leaving, linking a contact and inviting are audited. Reading one's own records is not.

## 14. Register rows

| # | Decision | Choice |
| --- | --- | --- |
| CA.1 | Address | `/@handle` is the front door; a signed-in customer goes to Home, everyone else sees the workspace page with Sign in; tabs at `/@handle/home`, `/bookings`, `/bills`, `/messages`, `/account`, plugin screens at `/@handle/x/{id}`; the handle never decides access |
| CA.2 | Brand | The workspace's logo, name and main colour on every screen, from the public-page brand; "Built on Whity" only at the foot of Account, removable for free |
| CA.3 | Own domain | Later (CA6): the area at the root of the workspace's own host once sign-in works there; `/@handle` redirects |
| CA.4 | Install | A manifest per workspace (`/@handle/` scope), named and iconed as the workspace (PW.11) |
| CA.5 | Sign-in | `/@handle/sign-in` in the workspace's brand; Whity ID only on Whity Cloud (BM.12); the install's own methods when self-hosted; return to the page asked for |
| CA.6 | Joining | Join in one tap when sign-up is open, Ask to join with approval, a plain message when off; staff can Invite to their account from a contact in any mode |
| CA.7 | Staff as customers | The area always shows the person as a customer of the workspace in the address, capped, own records only; "Open the team app" when they are staff |
| CA.8 | Links stay | Token links keep working without an account; emails add "See it in your account" |
| CA.9 | Whose records | Records whose `contact_id` is a contact with `profile_id` = the person; contacts link at join, by invitation, by hand, or automatically on a verified email or phone only |
| CA.10 | Customer API | `/api/v1/customer-area/{handle}/…`, every query filtered in core by tenant and the person's contacts; another customer's id answers 404 |
| CA.11 | Desktop navigation | Top bar: logo and name, tabs, account menu; no sidebar; More after 6 tabs |
| CA.12 | Phone navigation | Phone tab bar, up to 5; above 5: Home, 3 tabs, More (Account first) |
| CA.13 | When a tab shows | Only with a reason: Bookings when booking is on or they have one; Bills when they have a document; Messages when chat is on or they have a conversation; plugin tabs when they hold the permission; the workspace can hide core tabs except Home and Account |
| CA.14 | Home | Waiting for you, Next booking, latest message, plugin cards, quick actions; plain empty state |
| CA.15 | Bills | One list of quotes, invoices, receipts, credit notes and orders with a Kind filter; Pay now only with a payment plugin, How to pay otherwise |
| CA.16 | Messages | The customer's own conversations, signed in, using the public chat parts; agents still go through approvals |
| CA.17 | Plugin screens | `Contribute\PluginCustomerScreensInterface::getCustomerScreens()`: `placement` tab, home or page; `screen` blocks or embed; a customer-grade `requiredPermission`; optional `count` and `fullScreen` |
| CA.18 | Plugin own records | Deny by default: only routes declared `customer: true` with a customer-grade permission are callable from the area; core hands the plugin the customer's contacts (`Http\CustomerCall`); the test kit's isolation check must pass for Verified plugins; frame tokens are bound to the customer audience |
| CA.19 | Plugin Home cards | Plugins add Home cards by `placement: home` and one-customer "Waiting for you" items through `CustomerAreaNotifier` |
| CA.20 | Phone layout | PW rules: bottom bar, 17px rows of 48px, filters as sheets, actions in a bottom bar, full-screen frames under a slim Close bar |
| CA.21 | Arabic | i18n domain `customer`; customer's language, else the workspace's, else the browser's; Western digits; mirrored bar |
| CA.22 | Settings | Settings › Customer area: on or off, tabs and order, plugin screens, welcome line, How to pay, Preview as a customer, the address and QR |
| CA.23 | Words | Staff say "Customer area" (منطقة العملاء); customers see the business's name and "Your account" (حسابك); tabs Home, Bookings, Bills, Messages, Account |
| CA.24 | Price | Core and free on every plan and self-hosted; customers who sign in count on Whity Cloud's customer meter (BM.3); taking money online needs a payment plugin (BM.16) |

## 15. Milestones (after the owner answers)

One branch and PR each, cut from `origin/develop`. Migration numbers and SDK minors come from the orchestrator before each PR.

| # | What | Migration | SDK |
| --- | --- | --- | --- |
| CA1 | **Core area and core tabs.** `/@handle` front door (or the part of H2 it needs), the rewrite for `/@handle/*`, the customer API, contact linking on verified addresses, the shell (top bar, phone tab bar), Home, Bookings, Bills, Messages, Account, Settings › Customer area, the per-workspace manifest, sign-in return, the `customer` i18n domain in English and Arabic, isolation tests. Checks CE-21 (quote links) and fixes it if broken. | 1 (settings, link audit) | — |
| CA2 | **The SDK contract.** `PluginCustomerScreensInterface`, the `customer: true` route key and deny-by-default gate, `Http\CustomerCall`, the customer audience on frame tokens and `init`, `CustomerAreaNotifier`, the test kit's isolation check, the ledger, coverage and README. Plugin screens render in the area (blocks and embed, full screen). | 0–1 | 1 minor |
| CA3 | **A sample plugin screen.** A small plugin in `examples/` (a "My courses" list with a lesson page and a full-screen embed) that adds a tab, a Home card and a count, with the isolation test passing. It proves the contract end to end and is the LMS plugin's starting point. | 0 | — |
| CA4 | Invite to their account from a contact, and "See it in your account" in emails. | 0 | — |
| CA5 | Brand-team cards replace the section-21 screens when they arrive. | — | — |
| CA6 | Own domain (CA.3). | 0–1 | — |

Done for this piece of work means CA1–CA3 merged, and the area works for a customer on a local stack in English and Arabic, on desktop and phone, with a plugin tab added through the SDK.

## 16. Questions for the owner

Each has a recommendation. **Answered 25 November 2026: "Accept all (Recommended)"**, so every recommendation below is the decision.

1. **The address.** **Recommended:** under the workspace page, `/@handle/home`, `/@handle/bills`… (CA.1). The alternative is a separate prefix such as `/c/{handle}/…`. Putting it under `/@handle` means one address to share for everything, which is the point of HD.5.
2. **On by default?** **Recommended:** on for every workspace, with Sign in on `/@handle`. A workspace with no customer data shows customers an almost empty area, but turning it on later is a step most won't find. The alternative is off until an admin turns it on.
3. **Own domain later, not now.** **Recommended:** yes. Ship on `/@handle` first. The own-domain step (CA6) needs a session cookie per host and Whity ID redirect URIs per host, and is its own milestone.
4. **Staff inviting customers.** **Recommended:** yes, Invite to their account on a contact, working even when public sign-up is off. A clinic usually doesn't want open sign-up but does want its patients in.
5. **Linking past records automatically.** When someone signs in with a verified email or phone that matches an existing contact (from a public booking, a quote), link them so their past bookings and bills appear. **Recommended:** yes, on a verified address only, never an unverified one, and staff can unlink. The alternative, linking only by invitation, is safer but means most customers see an empty area at first.
6. **One "Bills" tab or separate tabs?** **Recommended:** one tab, "Bills" (الفواتير), holding quotes, invoices, receipts, credit notes and orders, with a Kind filter. Separate Quotes, Invoices and Orders tabs would fill the 5 phone slots on their own. If a workspace sells mostly by order (`TmCustomerApp` shows Orders), should Orders be a tab of its own when it has any? *Recommended: not now; revisit with the customer app.*
7. **Messages or Chat?** UC.1 renamed Messages to Chat in the team app. **Recommended:** customers see "Messages" (الرسائل), as `TmCustomerApp` draws it, because to a customer it is writing to a business, not a team chat.
8. **Who gets a plugin's tab.** **Recommended:** when a plugin with customer screens is enabled, its screens are on by default in Settings › Customer area, and turning one on adds its permission to the customer sign-up role and existing customers' roles, after the admin confirms. The alternative is the admin editing roles by hand, which few will do.
9. **Preview as a customer for staff.** **Recommended:** yes, with the example customer and example records, read-only. Not "view as" a real customer, which would show staff a real person's area in a way the audit log doesn't cover.
10. **Plugins' own-records check.** **Recommended:** deny by default (`customer: true` routes only), core hands the customer's contacts to the plugin, and the test kit's isolation check is required for Verified plugins and in store review, and advised for others. Core cannot check a plugin's own tables, so this is the strongest check that stays additive.
11. **Paying online.** **Recommended:** Pay now appears only with a payment plugin (Whity Pay), and core's How to pay text covers everyone else (BM.16). Nothing to decide unless you want Pay now held back until Whity Pay's customer checkout exists.
12. **Visit records and treatment plans.** AP.21 keeps visit records from customers. **Recommended:** keep that. Patients see their appointments, their forms and their bills, and a treatment plan's booked visits as normal bookings. Should patients ever see a summary the clinician chooses to share? *Recommended: later, as an explicit "Share with the patient" on a record.*
13. **Learners on the customer meter** (LMS gap 4). **Recommended:** not decided here. Learners who sign in count like any customer until you decide gap 4 in the LMS work.
14. **Screens without cards.** Only `TmCustomerApp` exists (phone, an ordering business). **Recommended:** build to section 21 and the `Pw*` cards now, and send the brand team an ask for the area's desktop and phone screens (section 17). The cards replace ours when they arrive (CA5).

## 17. Asks for the brand team

A request file goes to `D:\Projects\Whity\brand-asks\` once the owner answers: the area's desktop top bar with tabs, the phone bar with a plugin tab and More, Home with Waiting for you cards, Bills list and an invoice with Pay now and How to pay, Bookings, Account, the branded sign-in and join pages, an empty area, and a full-screen plugin frame, all in light, dark and Arabic.

## 18. Not in scope

- Native mobile apps (0.2.29). The area is the web app, installable.
- Order tracking with steps and a driver (`TmCustomerApp` Track).
- Selling courses and subscriptions to customers (LMS gap 5, Whity Pay).
- Realtime events beyond what chat already does (LMS gap 3).
- An interactive lesson block set or a `lesson/1` bridge (LMS gap 2). Full-screen `embed` is the way in for now.
- Cross-workspace customer identity beyond Whity ID (HD.8).
