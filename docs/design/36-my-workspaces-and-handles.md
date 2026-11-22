# 36 · My workspaces and @handles

**Status: decided by the owner on 22 November 2026 (WHIT-1354): "Accept all", every question in section 9 as recommended.** The register rows are HD.1–HD.9 and MW.1–MW.7 (section "My workspaces and @handles").

The owner, 22 November 2026: "as a normal user they need a manage workspaces/organizations space so that he can hand them over to someone, delete them, etc... Organisation slugs should become like the @handles in social media per whity instance... and we might later enable cross tenant communication".

This section has three parts:

- **My workspaces.** One personal page where a person sees every workspace they belong to and manages the ones they own.
- **@handles.** Each workspace's `slug` becomes its public @handle, unique on the installation.
- **Later: messages between workspaces.** Designed for now, not built.

The register rows are in section 8 (MW.\*, HD.\*) and in `00-decision-register.md`.

It builds on the ownership work of 17 November 2026: WHIT-1169, WHIT-1170 and WHIT-1171, migrations 300 and 301. The owner's rules from that day stay: co-owners are equal, an offer must be accepted within 7 days, and a person owns at most 3 workspaces on app.whity.dev. One rule changes: deleting a workspace was operator-only, and the owner now asks for owners to delete. Question 1 confirms that change.

The System workspace (tenant 0) is out of scope. Its operator console is a separate lane (WHIT-1351). The one point that touches it here: operators see each workspace's handle in their Tenants list and can change or free a handle (HD.9).

## 1. What exists today (develop 22b7d8aa4)

| Area | Today | File |
| --- | --- | --- |
| Slug column | `tenants.slug VARCHAR(255) UNIQUE`, nullable, case-sensitive unique, no CHECK | migration 001, baseline `tenants_slug_key` |
| Making a slug | Sign-up slugifies the name, so an all-Arabic name is refused ("use some letters or numbers"). The newer flows (`WorkspaceFounder::freeSlug`, used by self-create and SSO) give an opaque `ws-` + 12 hex so that a typed name can never take a subdomain like `app.` or `admin.`. Operators type any `^[a-z0-9-]+$`, which allows a leading dash | `RegisterApiHandler:142,431`, `WorkspaceFounder:193`, `TenantsApiHandler:392,975` |
| Reserved words | None. `HostResolver` refuses only `www` and nested labels | `HostResolver:52` |
| Changing a slug | `PATCH /api/tenants/{id}` with `tenants:write`, which a workspace admin also has for their own workspace. It is a plain overwrite with no history and no redirect | `TenantsApiHandler:608` |
| Where the slug is used | `/book/{slug}`, `/join/{slug}`, `/chat/{slug}` pages and their share cards; `/api/public/booking/{slug}`, `/api/public/signup/workspaces/{slug}`, `/api/public/chat/{slug}`; the inbound message webhook `/api/public/messages/inbound/{channel}/{slug}`; the `<slug>.BRANDING_BASE_DOMAIN` subdomain; the "book again" email link; the `WEBSITE_CONTACT_WORKSPACE` setting. Forms (`/f/{public_slug}`), verify, booking and quote links do **not** use it | `public/index.php:6416–6677`, `web/app/{book,join,chat}/[workspace]` |
| Owners | `workspace_owners` and `workspace_ownership_offers` (transfer or co-owner, 7 days, `leave_after`). Offers go only to people already on the workspace's staff | `WorkspaceOwners`, `WorkspaceOwnership::checkOffer` |
| Owner screens | Settings › Owners; `/ownership-offers/[id]` to accept an offer | `web/app/(protected)/admin/settings/owners`, `ownership-offers/[id]` |
| Creating | `/workspaces/new` and `POST /api/workspaces`; gated by `workspaces.self_create_enabled` and `workspaces.max_owned_per_person`; later workspaces start a Team trial (WHIT-1203) | `WorkspaceCreator`, `WorkspacesApiHandler` |
| Switcher | Lists memberships with an "Owner" mark and ends with "Create workspace" | `web/components/sidebar.tsx:62`, `packages/ui/src/tenant-switcher.tsx` |
| `/api/me` | One entry per workspace (name, roles, kind, `is_owner`), without the slug | `AuthHandler::handleMe` |
| Leaving | **There is no way to leave.** A person leaves only when an admin removes them, or when an accepted hand-over had `leave_after` set | |
| Deleting | Operator only: `DELETE /api/tenants/{id}` with `tenants:delete`. It is a hard delete, refused while any member is active, so a workspace can't delete itself. Foreign keys cascade, except platform `invoices`, `invoice_lines`, `payment_transactions`, `licensed_devices` and others that RESTRICT, so a workspace with invoices fails with a 500. Nothing cancels billing, exports or touches backups. The audit log survives (migrations 347, 348) | `TenantsApiHandler:695` |
| Export | **None for a workspace.** Only the audit log, form responses and themes export, although BM.11 promises "export everything any time" | |
| Billing portal | `BillingPortal` can start a checkout, read access, change quantity and change plan. It has **no cancel** | `src/Core/Billing/External/BillingPortal.php` |
| Backups | `scripts/backup-db.sh` makes whole-database backups, keeps local copies 14 days by default (`BACKUP_RETENTION_DAYS`), and leaves remote copies to the bucket's lifecycle policy | |
| Chat | Every row (`conversations`, `chats`, `chat_members`, `messages`) is bound to one `tenant_id`. People are global profiles, but a chat lives in one workspace. A customer reaches a workspace by slug through public chat or an inbound channel. There are no handles for people, and `@` in chat picks a member (UC.10) | migrations 243, 253, `ChatRepository` |

## 2. Words

| English | Arabic | Means |
| --- | --- | --- |
| My workspaces | مساحات عملي | The personal page in this section |
| Workspace | مساحة العمل | A tenant (organisation). We keep "workspace" on screen, as everywhere else |
| Handle | المعرّف | The `@name` of a workspace, unique on this Whity |
| Owner | المالك | A person in `workspace_owners` |
| Co-owner | مالك شريك | Another owner with equal rights |
| Hand over | تسليم الملكية | Make someone else the owner (a transfer offer) |
| Leave | مغادرة | Stop belonging to a workspace |
| Delete workspace | حذف مساحة العمل | Start the deletion in section 4.6 |
| Closing | قيد الإغلاق | A workspace waiting to be deleted |
| Keep the workspace | الإبقاء على مساحة العمل | Cancel a deletion while it waits |
| Download everything | تنزيل كل البيانات | The workspace export |

Handles are Latin and left to right in both languages, like codes and amounts. They are never translated.

## 3. @handles

### 3.1 What a handle is

The handle is the `tenants.slug` value, shown with an `@` in front (`@aau-irb`). We keep the column's name, so no code that reads `slug` changes meaning. Only the rules, the history and the screens are new. A handle names a workspace on this installation, the way an @name does on a social network.

### 3.2 Rules (HD.1)

- **Characters.** `a–z`, `0–9` and `-`; 3 to 30 characters; starts with a letter; ends with a letter or digit; no `--`. This keeps every handle a valid DNS label, because handles are also subdomains (`aau-irb.<base domain>`), and it leaves room for a later `@handle@installation` form (section 6), since a handle never holds `@` or `.`.
- **Case.** Stored in lower case. Typing `AAU-IRB` gives `aau-irb`. Uniqueness is on `LOWER(slug)`, which closes today's case-sensitive gap.
- **Unique on the installation.** A handle in use, or one held after a change or a deletion (3.5), can't be taken.
- **The `ws-` prefix.** Kept for handles Whity makes itself (`ws-` plus 12 hex). Nobody can choose a handle that starts with `ws-`.

### 3.3 Reserved handles (HD.2)

A handle can't be any of these. One list in code, `src/Core/Tenant/ReservedHandles.php`:

1. **Every first path segment of the web app and API.** Generated from `web/routes.json`: `admin`, `api`, `book`, `booking`, `chat`, `f`, `join`, `login`, `quote`, `verify`, `workspaces`, `settings` and so on. The route manifest check (#2400) fails CI when a new top-level page isn't on the list. This way a future short link like `/{handle}` can never shadow a page.
2. **Infrastructure and roles.** `system`, `admin`, `administrator`, `root`, `operator`, `staff`, `support`, `help`, `security`, `abuse`, `billing`, `payments`, `status`, `docs`, `blog`, `www`, `mail`, `email`, `smtp`, `app`, `apps`, `cdn`, `static`, `assets`, `media`, `files`, `id`, `auth`, `sso`, `oauth`, `login`, `account`, `accounts`, `dev`, `test`, `staging`, `api-docs`, `webhooks`, `mcp`, `store`, `pay`, `render`, `builder`, `studio`, `cloud`.
3. **Chat words.** `everyone`, `here`, `all`, `channel`, `me`, `you`, `team`, `bot`, `agent`, `agents`, so a later `@` address never clashes with UC.10 mentions.
4. **The product's name.** Any handle containing `whity` (question 8). An operator can grant one by hand, for Whity's own workspaces.
5. **The operator's own list.** A new setting `handles.reserved` (Platform › Settings › Sign-up), one handle per line, for local brands and offensive words.

A refused handle says why in plain words: "That handle is kept for Whity's own pages. Try another." It never says "reserved by system".

### 3.4 Arabic names (HD.3)

Handles stay Latin. When the name has no Latin letters, the handle field **suggests** one from a simple Arabic-to-Latin letter map (`مخبز المثال` → `mkhbz-almthal`; short vowels aren't written in Arabic, so the person usually tidies it). The person always sees and edits the suggestion before it's used. Without a usable suggestion, the field stays empty with the hint "Use Latin letters, for example the name in English." Sign-up stops refusing all-Arabic names: the workspace gets a `ws-` handle, and its owner is asked to choose one later (3.7). No machine translation.

### 3.5 Changing a handle and the redirect period (HD.4)

- **Who.** Owners only. That's a change: today any admin with `tenants:write` can change it. Operators can also change it (HD.9).
- **Where.** Settings › Workspace (the Name and handle card) and My workspaces. Both show a live "Available" or "Taken" check and preview the links that will change (3.6).
- **How often.** At most once every 30 days (question 7), so links don't churn and a freed handle can't be flipped back and forth.
- **Redirect period, 90 days.** The old handle goes into `tenant_handle_history (tenant_id, handle, changed_at, changed_by_profile_id, held_until)`.
  - Pages under the old handle answer with a 301 to the new one.
  - The public APIs still resolve it.
  - The inbound message webhook keeps working, and the workspace's admins get a warning to update the address in WhatsApp, the email provider and the rest.
  - The old subdomain redirects too.
  - During the hold, only the same workspace can take the old handle back.
  - After 90 days the redirect stops and the handle is free (question 6).
- **Audit.** "Amro Saleh changed the handle from @alhaj-salon to @alhaj". The SDK event `tenant.handle_changed` (old, new) lets plugins that stored the handle update it.

### 3.6 Where the handle appears (HD.5)

| Place | Address | Change |
| --- | --- | --- |
| Workspace page (new, question 10) | `/@aau-irb` | A public page in the workspace's brand (SU.1, SU.10): logo, name, and buttons for whatever is switched on (Book, Chat, Join, public forms). It's the address to share, and it's the share card. Next.js treats `@` folders as parallel-route slots, so `web/proxy.ts` rewrites `/@:handle` to `web/app/w/[handle]`. The manifest gets `/w/:handle` |
| Booking | `/book/aau-irb` | Unchanged. Its page and share card show `@aau-irb` under the name |
| Join (customer sign-up) | `/join/aau-irb` | Unchanged; shows the handle |
| Public chat | `/chat/aau-irb` | Unchanged; shows the handle |
| Forms | `/f/{form public slug}` | The link stays as is, because a form's slug is already unique on the installation. The form page shows "by @aau-irb" under the workspace's name |
| Subdomain | `aau-irb.<base domain>` | Unchanged. It is now protected by the reserved list instead of only by opaque handles |
| Share cards | `opengraph-image` of book, join, chat, and the new workspace page | Show `@handle` |
| Emails | "Book again" and the sign-up links | Use the handle as today; the footer shows `@handle` |
| Inbound channels | `/api/public/messages/inbound/{channel}/{handle}` | Unchanged, with the warning in 3.5 |
| In the app | Switcher, My workspaces, Settings › Workspace, `/api/me` memberships | Show `@handle` in muted text beside the name. `/api/me` gains `tenant_handle` |
| Operators | Tenants list and record | Show and search by handle (HD.9) |

The handle is never a security boundary. Sign-in, permissions and tenant isolation keep using `tenant_id`, and a public handler keeps checking that the Host agrees with the path (`PublicBookingApiHandler:51`).

### 3.7 Choosing a handle when creating, and the `ws-` workspaces (HD.6)

- `/workspaces/new` and the sign-up form get a **Handle** field under the name. It is suggested from the name, edited freely, and checked live. It's optional: leaving it empty gives a `ws-` handle.
- A workspace whose handle starts with `ws-` shows its owners a quiet card in My workspaces and in Settings › Workspace: "Choose a handle so customers can find you: @…". Choosing it the first time doesn't count against the once-in-30-days rule, and it leaves no redirect, since nothing has been shared with a handle nobody chose.

### 3.8 Moving existing slugs (HD.7)

One idempotent migration, plus `whity-cli handles:check`, a dry run that lists every workspace and what would happen to it. Run it on a restored live copy before the deploy (never on the live server).

| Today's slug | Becomes |
| --- | --- |
| Valid under 3.2 and not reserved (`almolakhas`, `curapass`, `mcp-check`, `aau-irb`) | Kept as it is |
| Upper case | Lower case, if that's free |
| `ws-` + hex | Kept; its owners get the "Choose a handle" card |
| Invalid (leading dash, too short or long, `--`) or reserved | A fixed version (trimmed, shortened) if valid and free, otherwise `ws-` + hex. The old value goes into `tenant_handle_history` with the usual 90 days, so links keep working |
| Null (old seeded workspaces) | `ws-` + hex |
| Tenant 0 | Stays without a handle |

After the move, `slug` becomes NOT NULL for every tenant except 0, and gets a unique index on `LOWER(slug)`. The migration also writes the list of changes to the audit log.

## 4. My workspaces

### 4.1 Where it lives (MW.1)

- **`/workspaces`**, a personal page outside any one workspace, beside the existing `/workspaces/new`. It reads only the signed-in person's own memberships, so it works whichever workspace is active.
- Reached from the switcher (a new last item **Manage workspaces** under **Create workspace**), the account menu, Ctrl+K "My workspaces", and the phone's More tab (PW).
- On a phone it uses `Pw*` rows and sheets (PW.1–PW.11).
- The route manifest gets `/workspaces` and `/workspaces/:id`, and the stable id `my-workspaces`.

### 4.2 The list (MW.2)

A section 21 list page, in three groups, each shown only when it has rows:

1. **Yours.** The workspaces you own.
2. **Where you work.** You're on the staff, not an owner.
3. **Where you're a customer.** You have only a customer membership (question 14).

Each row shows:
- the workspace's logo and name (in the reader's language when it has one, WHIT-973);
- `@handle`;
- your role in plain words: Owner, Owner with Amro Saleh (co-owners as names), Admin, Member, Customer;
- the plan state: Free, Team trial (ends 12 Dec), Read-only until paid, or Closing (deleted on 6 Dec);
- the number of people on the staff.

The row's main action is **Open**, which switches into the workspace. ⋯ holds Leave, plus Delete for owners. The page's one filled button is **Create workspace** (to `/workspaces/new`, with its existing gates and messages).

Open offers sent to you ("Awss Haddad wants to hand over Example Bakery to you") appear above the list, with **Review** going to `/ownership-offers/[id]`. Today the only way to find such an offer is the email.

### 4.3 A workspace's page (MW.3)

`/workspaces/[id]` is a record page (section 21). It works without switching into the workspace.

**For an owner:**

| Card | Does | Built on |
| --- | --- | --- |
| Name and handle | Rename (every language, WHIT-973); change the handle (3.5) | `PATCH /api/v1/tenants/{id}` rules |
| Owners | List owners and open offers; **Make co-owner**; **Hand over**; **Remove** a co-owner; **Step down** | `WorkspaceOwnership` and its identity checks, unchanged |
| Plan | The plan state, and **Open billing**, which switches in and goes to Billing | Existing billing pages |
| Download everything | Start an export and list the recent ones (4.5) | New |
| Leave | 4.4 | New |
| Delete workspace | 4.6, on its own at the bottom, outline and red | New |

**For a member or customer:** the name, handle, your role, the owners as names (so you know whom to ask), and **Leave**.

The owner actions repeat Settings › Owners on purpose. Settings › Owners stays for owners already inside the workspace. Both call the same service.

**API.** New person-scoped routes under `/api/me/workspaces` take the workspace from the path and check membership or ownership there, without needing the session's active tenant:
- `GET /api/me/workspaces`
- `GET /api/me/workspaces/{id}`
- `PATCH /api/me/workspaces/{id}` (name, handle)
- `POST /api/me/workspaces/{id}/leave`
- `POST /api/me/workspaces/{id}/exports`
- `POST /api/me/workspaces/{id}/deletion` and `DELETE …/deletion` (cancel)
- the owner and offer actions, delegating to `WorkspaceOwnership`

They answer 404 for a workspace you don't belong to, the same as a workspace that doesn't exist.

**Handing over to someone outside the workspace (question 11).** Today an offer can go only to a staff member. Recommended: **Hand over** also takes an email. Whity invites that person (the existing invitation, through Whity ID on app.whity.dev) and attaches a transfer offer. Accepting the offer joins them as an admin and makes them the owner in one step. The 7-day expiry covers both, and the 3-owned cap is checked when they accept.

### 4.4 Leaving (MW.4)

- **Anyone can leave**, except the last owner. The last owner sees "You're the only owner. Hand the workspace over or delete it first."
- **An owner who leaves** steps down first, which needs the existing identity check.
- **Before confirming**, a dialog says what you still hold there in plain words: "You're assigned 3 chats and 5 upcoming bookings. They'll become unassigned and the admins will be told." Leaving can only be undone by a new invitation, so the dialog confirms by how undoable it is (section 21): **Leave Example Bakery** in red outline. No typed name and no identity check.
- **What leaving does, in one transaction:**
  - ends every membership row in the workspace (primary and secondary);
  - takes you out of its chats (`chat_members.removed_at`, so the history stays);
  - unassigns your open conversations;
  - removes you from future bookings as staff, which flags those bookings for the admins and never cancels a customer's booking silently;
  - ends your sessions in that workspace;
  - writes an audit entry ("Ahmad Khalil left").
- **Afterwards:**
  - The admins get a notification.
  - Documents, messages and records you made stay with the workspace, under your name.
  - A customer who leaves keeps their contact record at the business, as with any contact. Only their sign-in to that workspace ends.
- If you leave the workspace you're in, you go to the next one in your list, or to `/welcome`.
- **Agents** that work for you in that workspace are switched off with you.

### 4.5 Download everything (MW.5)

The workspace export, which BM.11 promises and the deletion needs.

- **Who.** Owners, from My workspaces or Settings › Data.
- **How.** A background job builds one ZIP, and the owners get a notification and an email when it's ready. The link lasts 7 days, and only an owner can download it. The ZIP holds:
  - one JSON file per kind of record, in open formats;
  - CSV files for the lists people open in a spreadsheet (contacts, bookings, invoices, items, stock levels);
  - the files themselves, in their folders;
  - the issued PDFs as they were issued;
  - a `README.txt` explaining each file, in English and Arabic.
- **Plugins** add their own parts through a new `Contribute\WorkspaceExportPart` (key, label, write into the ZIP).
- **Size.** It counts on the storage meter while it's kept and is cleared after 7 days. Exports are warn-only, like every meter.
- **Secrets stay out.** No passwords, keys, tokens or webhook secrets.

### 4.6 Deleting a workspace (MW.6)

**Who.** Any owner (question 1). The other owners are told at once and can cancel. On a self-hosted installation the operator can switch owner deletion off with `workspaces.self_delete_enabled` (default on).

**The steps, on one page:**

1. **What goes.** Counts in plain words: 14 people lose access, 2,310 contacts, 412 documents, 1.8 GB of files, 36 upcoming bookings (customers will be told), and the public pages and handle `@example-bakery`. It also says what happens to billing, data and backups (below).
2. **Download everything first.** If no export finished in the last 7 days, the page offers **Download everything** and waits for it, or the owner ticks "I don't need a copy" (question 3).
3. **Typed confirmation.** Type the handle, `example-bakery`, to enable the button.
4. **Confirm it's you.** A new GatedApproval kind `workspace-deletion`, with the same identity check as ownership offers (authenticator, emailed code, or a fresh Whity ID sign-in).
5. **Delete workspace** (red). The workspace becomes **Closing**.

**The waiting period: 14 days (question 2).** While it waits:
- Nobody but the owners can sign in. Members see "Example Bakery is closing on 6 December. Ask Amro Saleh if this is a mistake."
- Owners can still open it read-only, download everything, and **Keep the workspace**, which restores everything as it was.
- Public pages (workspace page, booking, join, chat, forms) say "This page is no longer available" in the workspace's brand. Customers with upcoming bookings get one email saying the business has closed its Whity workspace. The wording is plain and never says "error".
- Scheduled emails, routines, agents, automations, webhooks and inbound channels stop. Inbound messages are refused with a clear status, so the sender's system can tell.
- Every owner is emailed when the deletion starts, 3 days before the end, and when it ends. Members are emailed when it starts (question 13).

**At the end** (a daily job, `workspace:purge`):
- The `tenant.deleting` hook runs first. If a plugin vetoes, the purge pauses and the operator is alerted; it never half-deletes.
- The workspace's data is deleted, its files are removed from storage, and its search index and caches are cleared.
- The `tenants` row stays as a **tombstone**: `lifecycle = 'deleted'`, the name becomes "Deleted workspace", and the handle goes to `tenant_handle_history`, held 90 days. The row stays because Whity's own invoices to the workspace (`invoices`, `payment_transactions`), which are legal records, and the audit log point at it. Today's hard delete fails with a 500 for exactly that reason.
- `tenant.deleted` and `tenant.deleted.async` fire as today.

**Billing:**
- **At the start:** the subscription is set not to renew. This needs a new `BillingPortal::cancel(actor, subscriptionRef, atPeriodEnd)`, implemented by Whity Pay for Polar (an SDK minor).
- **Keep the workspace** turns renewal back on.
- **At the end:** the subscription is cancelled for good. No refund for the current period (question 4).
- **Unpaid invoices** stay owed and stay in Whity's books. They don't block the deletion. Dunning stops at the end.
- **Owned-workspace cap.** A closing workspace still counts towards an owner's 3 owned workspaces until it's deleted, so deleting can't be used to get round the paid extra workspaces (WHIT-1203).

**Data and backups**, as the page says it:
- "Your workspace's data is deleted on 6 December."
- "Copies stay in our encrypted backups until those are replaced, at most 30 days later (question 5). We don't restore a single workspace from backups."
- "Whity keeps its own invoices to you and the record of who did what (the audit log), as the law requires."

A self-hosted installation shows its own retention, from `BACKUP_RETENTION_DAYS` or an operator setting, instead of 30.

**The data.** New columns on `tenants`:
- `lifecycle` (`active`, `closing`, `deleted`);
- `deletion_requested_at`, `deletion_requested_by_profile_id`, `deletion_purge_at`.

The SDK gets the events `workspace.deletion_scheduled`, `workspace.deletion_cancelled` and `workspace.deleted`, each with an `.async` twin, in the webhook catalogue.

**Operators.** `DELETE /api/tenants/{id}` keeps working for operators, rewritten on the same purge, so it leaves the same tombstone. Operators can also purge a closing workspace at once. The screen for that belongs to the operator console lane.

### 4.7 Switcher and sign-in (MW.7)

- The switcher gets **Manage workspaces** as its last item and shows `@handle` under each name.
- A Closing workspace shows "Closing" in the switcher and the sign-in picker, and only its owners can choose it.
- The sign-in picker gets the Owner mark the switcher already has (a follow-up noted in WHIT-1171).

## 5. Agents, MCP and the SDK

- **Agent read tools:** `my_workspaces_list`. Agents never leave, delete or change a handle. They can propose a handle change (Ask first) for a person to accept, like any change.
- **SDK:**
  - Services: `Tenant\Handles` (validate, isAvailable, suggest, resolve, which also follows held handles) and `Tenant\WorkspaceExport`.
  - Events: `tenant.handle_changed` and the three deletion events.
  - The `Contribute\WorkspaceExportPart` hook.
  - `BillingPortal::cancel`.
  - All in one SDK minor, number from the orchestrator.

## 6. Later: messages between workspaces (not built now)

The owner: "we might later enable cross tenant communication". The handle is designed so that it can address a workspace from another one: Example Bakery sends a message to `@aau-irb`.

**The address:**
- On the same installation, `@aau-irb`.
- Between installations later, `@aau-irb@app.whity.dev`. A handle never contains `@` or `.`, so the address always splits cleanly.
- Conversations store the other workspace's `tenant_id`, never its handle, so renaming doesn't break them.

**Recommended model: another workspace is a channel.** UC.1 already puts customers "on any channel" in one list, with the channel as a badge. A workspace-to-workspace chat is a new channel, `whity`:
- Each side keeps **its own** `conversations` row and its own copy of the messages, in its own tenant. Delivery goes through a channel adapter, like email or WhatsApp.
- Tenant isolation stays as it is (WHIT-971): no row is ever shared between two tenants.
- The same model later carries messages between installations.

**What the existing Chat (UC.1–UC.13) would need:**

| Need | Where |
| --- | --- |
| A `whity` channel adapter: outbound to the other workspace's inbox, inbound into a `conversations` row with `contact_address = '@handle'` and `conversation_contacts (tenant_id, 'whity', other_tenant_id)` | `Chat` channel adapters |
| A "Workspaces" filter tab beside Customers and Team (UC.1), and the workspace's logo with a small Whity badge as the picture | `TplChatHub` |
| **First contact needs consent.** The first message arrives as a request ("Example Bakery (@example-bakery) wants to message you"). An admin accepts or declines; nothing else gets through until then | New |
| **Blocking** extends to workspaces: admins block a handle in Settings › Chat (UC.12) | Settings › Chat |
| **Directory:** a setting "Other workspaces can find us by handle" (default on, question 15); search by handle only, never a list of every workspace | Settings › Workspace |
| **Who answers:** like a customer chat, assigned to a teammate, with Reply or Internal note (UC.3). The other side sees the workspace, not the teammate, unless the workspace chooses to show names | UC.3 |
| **Blocks in messages (UC.5)** across workspaces show only what the other side may see: a quote's public page, a booking's link. Never a record the reader has no access to | `CustomerBlocks` |
| **Meters:** counted on the sender's messages meter (BM.17) | Meters |
| **Rate limits and abuse reports** per sending workspace | New |
| **Agents** keep CH.1–CH.2. An agent never starts a first contact with another workspace | Agents |

That's a lane of its own, with its own spec, after this one.

## 7. Milestones (after the owner answers)

Each milestone is one WHIT ticket and one PR. Migration numbers and SDK minors come from the orchestrator before each PR opens.

| # | Milestone | Migration | SDK |
| --- | --- | --- | --- |
| H1 | Handle rules, reserved list (with the route-manifest check), `tenant_handle_history`, lower-case unique index, redirects and held handles in `HostResolver` and the public handlers, owner-only change with the 30-day rule, Settings › Workspace card, `handles:check`, moving existing slugs | 1 | `Tenant\Handles`, `tenant.handle_changed` |
| H2 | Handle on create and sign-up (Arabic suggestions, the `ws-` card), `/@handle` workspace page (proxy rewrite, `/w/[handle]`, share card), handle shown on book, join, chat, form pages, emails, switcher and `/api/me` | 0 | |
| M1 | My workspaces: `/workspaces`, `/workspaces/[id]`, `/api/me/workspaces*`, rename, handle, owners actions, offers on the list, switcher item, Ctrl+K, phone rows | 0 | |
| M2 | Leaving (MW.4) for members, owners and customers | 0 | |
| M3 | Download everything: export job, ZIP, `Contribute\WorkspaceExportPart`, Settings › Data entry | 1 | `Tenant\WorkspaceExport`, hook |
| M4 | Deleting: lifecycle columns, Closing state, GatedApproval kind, typed confirmation, emails, `workspace:purge` and tombstone, operator delete rewritten on it, `BillingPortal::cancel` (with Whity Pay's Polar part in whity-plugins-pay) | 1 | events, `cancel` |
| M5 | Hand over to someone outside the workspace (invite plus offer), if question 11 is yes | 1 (offer email) | |

Order: H1 → H2 and M1 side by side → M2 → M3 → M4 → M5.

Brand-team asks go to `brand-asks/` for the cards with no design: the My workspaces list and record page, the deletion page and the Closing state, the workspace page `/@handle`, and the handle field. Until they arrive, screens follow section 21.

## 8. Register rows

In the register as "My workspaces and @handles (decided 22 November 2026)".

| # | Decision | Choice |
| --- | --- | --- |
| HD.1 | Handle rules | A workspace's `slug` is its public @handle: `a–z`, `0–9`, `-`, 3–30, starts with a letter, no `--`, stored in lower case, unique on the installation on `LOWER(slug)`; `ws-` is kept for handles Whity makes |
| HD.2 | Reserved | Every first path segment of the web app (generated from `routes.json`, checked in CI), infrastructure and role words, chat words, anything containing `whity`, and the operator's `handles.reserved` list |
| HD.3 | Arabic names | Handles stay Latin; an Arabic name gets a suggested Latin handle the person edits; no machine translation; all-Arabic names are never refused |
| HD.4 | Changing | Owners only, at most once every 30 days; the old handle redirects for 90 days and only the same workspace can take it back meanwhile; audit entry and `tenant.handle_changed` |
| HD.5 | Where it shows | `/@handle` workspace page (the address to share), book, join, chat and form pages, share cards, email footers, subdomain, switcher, My workspaces, Settings; never a security boundary |
| HD.6 | New workspaces | A Handle field on create and sign-up, suggested from the name, optional; a `ws-` workspace's owners are asked to choose one, and the first choice is free of the 30-day rule |
| HD.7 | Moving slugs | One idempotent migration and `whity-cli handles:check`: valid slugs kept (almolakhas, curapass, mcp-check, aau-irb), others fixed or given `ws-`, every change held and redirected for 90 days |
| HD.8 | Later addressing | `@handle` on one installation, `@handle@installation` between installations; conversations store the tenant id, never the handle |
| HD.9 | Operators | Operators see, search, change and free any handle; reserved handles can be granted by an operator |
| MW.1 | Place | My workspaces at `/workspaces`, outside any one workspace; from the switcher, the account menu, Ctrl+K and More |
| MW.2 | The list | Groups Yours, Where you work, Where you're a customer; each with logo, name, @handle, role, plan state; offers waiting for you on top; Create workspace is the main button |
| MW.3 | Owners | Rename, change the handle, co-owners, hand over (also to an email), step down, download everything, delete; through `/api/me/workspaces`, the same services as Settings › Owners |
| MW.4 | Leaving | Anyone but the last owner leaves; what they hold is listed first and handed back to the admins; what they made stays under their name; confirmed by a plain dialog |
| MW.5 | Export | Download everything: a ZIP of JSON, CSV, files and issued PDFs with a bilingual README, for owners, link valid 7 days, plugins add parts; no secrets |
| MW.6 | Deleting | Any owner, after an export or a ticked "I don't need a copy", the typed handle and an identity check; 14 days Closing (owners only, read-only, Keep the workspace); then purged with a tombstone row; renewal off at once, cancelled at the end, no refund; backups age out within 30 days; Whity's invoices and the audit log stay |
| MW.7 | Switcher | Manage workspaces as the last item; @handle under each name; Closing shown and only owners may enter |

## 9. Questions for the owner

**Answered 22 November 2026: "Accept all".** Every recommendation below is the decision.

1. **Owners delete workspaces.** On 17 November deleting stayed operator-only. Now any owner can start a deletion, the other owners are told, and any owner can cancel it. *Recommended: yes, any owner; self-hosted operators can switch it off.*
2. **The waiting period.** *Recommended: 14 days, closed to everyone but the owners, who can cancel or export.* (Alternatives: 7 or 30.)
3. **Export before deleting.** *Recommended: offered and waited for, but skippable with a ticked "I don't need a copy".* (Alternative: required.)
4. **Billing on delete.** *Recommended: renewal off at once; cancelled at the end; no refund for the current period; unpaid invoices stay owed and don't block.*
5. **Backups wording.** *Recommended: "at most 30 days", after checking app.whity.dev's real remote retention in whity-local-deploy (local copies are 14 days today).* Is 30 right, and should we promise never to restore a single workspace?
6. **Old handles.** *Recommended: redirect 90 days, kept for the same workspace meanwhile, then free; a deleted workspace's handle is also held 90 days, then free.* (Alternative: never free a handle once used.)
7. **How often a handle can change.** *Recommended: once every 30 days.* (Alternative: no limit.)
8. **"whity" in handles.** *Recommended: reserved for any handle containing it; an operator can grant one.*
9. **Handle when creating.** *Recommended: an optional Handle field suggested from the name; empty gives `ws-…` and a reminder card.*
10. **A workspace page at `/@handle`.** *Recommended: yes, in H2, as the one address to share.*
11. **Hand over to someone outside the workspace.** *Recommended: yes, by email; they join as admin and become owner when they accept (M5).*
12. **Handle length.** *Recommended: 3 to 30.* (Some brands want 2 letters.)
13. **Telling members about a deletion.** *Recommended: email every member when it starts, and customers with upcoming bookings once when it ends.*
14. **Customer memberships in My workspaces.** *Recommended: listed in their own group "Where you're a customer", with Leave.*
15. **Messages between workspaces, later.** *Recommended: the channel model in section 6 (each side its own copy, consent on first contact, findable by handle by default); its own spec after this one. Nothing built now.*
