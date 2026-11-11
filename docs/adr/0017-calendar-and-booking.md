# ADR 0017: Calendar and booking in core

- **Status:** Accepted
- **Date:** 2026-11-11
- **Task / Issue:** WHIT-940 (this ADR); the pieces are listed under "Delivery"
- **Deciders:** Owner (scope, 2026-11-11), Whity-Core maintainers

## Context

The owner decided on 2026-11-11 that a minimal calendar ships in core, free and
open: staff calendars, services, availability, bookings made by staff or by a
customer on a public page, links for the customer to move or cancel, email
confirmations and reminders, an ICS feed, and MCP/API access. Deposits through
Whity Pay, Google and Microsoft calendar sync, WhatsApp and SMS reminders,
locations and resources (rooms, equipment) and waitlists are paid store
plugins, built on interfaces core publishes.

What core has today, and what it does not:

- **No calendar.** Nothing named calendar, booking, appointment or ICS exists.
  Meetings (convening, migration 130) are a governance feature with a single
  `scheduled_at`, not availability. Time windows (migration 126) are sealed
  fiscal-style periods. Neither is reused.
- **No customers or contacts tables.** A customer is a global profile plus a
  membership with `kind = 'customer'` (WHIT-915, migration 222). Profiles have
  email (`profile_emails`, globally unique, ADR 0005) and no phone.
- **A workspace time zone exists** (`SettingsRegistry::TIMEZONE`, IANA id,
  default `UTC`). The design register says calendars start on Saturday and
  grey Friday and Saturday as the weekend, both changeable per workspace
  (`00-decision-register.md`, `08-arabic.md`). No setting carries that yet.
- **Public routes** take their workspace from the path only (form slug, or the
  workspace slug on `/join/{workspace}`), never from a header, and every new
  one is an anchored pattern in `EnforceTenantIsolation::PUBLIC_ROUTE_PATTERNS`.
  Throttles are fixed-window counters on `SharedStoreInterface`. A Turnstile
  `BotCheck` exists for plugin public forms.
- **Email has no attachments.** `Mailer::send()` builds `multipart/alternative`
  only. `NotificationDispatcher` routes `email` to a log transport until a real
  one registers. A parallel lane is building a core messages API (channels,
  send) that reminders should move to later.
- **Delayed work:** `QueueService::dispatch(..., ['delay' => s,
  'idempotency_key' => k])`, up to 30 days. Domain events go through the
  outbox (ADR 0013). Agent writes become proposals in `ToolsCallHandler`.
- **Plan limits (#1799):** `customers.per_month` is warn-only and counted in
  `tenant_monthly_customers` through `CustomerUsage::served($tenant, $ref)`,
  where a customer is "a person outside the workspace's staff that it
  served", counted once per calendar month in the workspace's time zone.

## Decision

### 1. Data model

Migrations 247 to 250 (reserved for this work). Every table carries
`tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE`, is
listed in `TenantOwnedTables` and `CoreTables`, and every `*_id` column has a
foreign key.

| Table | Holds |
|---|---|
| `calendar_staff` | One row per staff member who has a calendar: `profile_id`, `bookable` (offered on the booking page), `sort_order`, `feed_token_hash` (ICS, see 6). It is also the **lock row** for that person (see 3). Unique `(tenant_id, profile_id)`. |
| `calendar_working_hours` | Weekly hours: `profile_id`, `weekday` (ISO 1 to 7), `start_time`, `end_time` as local wall-clock `HH:MM`. Several rows per day are allowed (a lunch break is two rows). |
| `calendar_availability_exceptions` | Dated changes: `kind` = `off` (time off, a closed afternoon) or `open` (extra hours on a day that is normally closed), `starts_at`, `ends_at` (UTC), `note`. |
| `calendar_events` | Staff events that are not bookings (a meeting, a block): `profile_id`, `title`, `notes`, `starts_at`, `ends_at`, `all_day`, `busy` (does it block bookings), `status` (`confirmed`/`cancelled`), `created_by_profile_id`, `external_ref` (for a sync plugin). |
| `calendar_services` | `name` (localized en/ar, the existing localized-name shape), `description`, `duration_minutes`, `buffer_before_minutes`, `buffer_after_minutes`, `price_minor` (nullable integer), `currency` (ISO 4217, default the workspace currency), `bookable_online`, `active`, `sort_order`. |
| `calendar_service_staff` | Which staff provide which service. Unique `(tenant_id, service_id, profile_id)`. |
| `calendar_bookings` | `service_id`, `profile_id` (staff), `customer_profile_id` (nullable), a snapshot of the customer's `customer_name`, `customer_email`, `customer_phone`, `customer_note`; `starts_at`, `ends_at` (the service time) and `blocks_from`, `blocks_until` (with buffers, what availability reads); `status`; `hold_expires_at`; `source` (`staff`, `public`, `agent`); `price_minor`, `currency` snapshotted from the service; `manage_token_hash`, `manage_token_expires_at`; `reminder_due_at`, `reminder_sent_at`; `created_by_profile_id`, `cancelled_by`, `cancel_reason`, `payment_ref` (opaque, for the payment plugin); timestamps. |

Booking states: `pending_payment` (a plugin's payment step is running; holds
the slot until `hold_expires_at`), `confirmed`, `cancelled`, `expired` (the
hold lapsed). `completed` and `no_show` are left for later; nothing in core
needs them yet.

**Customers.** There is no contacts table to reuse, so a booking keeps the
details the customer typed (name, email, optional phone) and links
`customer_profile_id` when it can be known without creating an account: the
customer is signed in as a customer of this workspace, or staff picked an
existing customer or member. **A public booking never creates a profile or a
membership.** Creating a global, email-keyed profile from an unverified form
would let anyone squat an address; joining as a customer stays the WHIT-915
sign-up with its emailed link. The booking page links to sign-up when the
workspace has it turned on.

Prices are integer minor units with the currency's decimals (JOD has 3, so
JOD 12.500 is `12500`). Core only shows them; charging is the payment step's
job (see 4).

Workspace settings (in `SettingsRegistry`, so they validate and show in
Settings): `calendar.booking_page_enabled` (default off), `calendar.min_notice_minutes`
(120), `calendar.max_advance_days` (60), `calendar.slot_step_minutes` (15),
`calendar.reminder_hours_before` (24, 0 turns reminders off),
`calendar.payment_hold_minutes` (15), `calendar.week_start` (default Saturday)
and `calendar.weekend_days` (default Friday and Saturday). The last two record
decision-register rules that had no setting.

### 2. Time zones

- **Store UTC, always.** Every instant (`starts_at`, `ends_at`, exceptions,
  holds) is a UTC `TIMESTAMP`. The API sends and accepts ISO 8601 with an
  offset (`2026-11-12T09:00:00+03:00`) and always answers in UTC with `Z`.
- **Working hours are wall-clock, not instants.** "Sunday 09:00 to 17:00" is
  turned into UTC **per local date**, in the workspace's zone, at the moment
  availability is computed: build `DateTimeImmutable('2026-11-15 09:00',
  new DateTimeZone($zone))` and convert. So the same weekly row means 06:00Z in
  Amman and moves correctly in a zone that changes its clocks.
- **Daylight saving.** Jordan has had no clock change since 2022, but other
  installs do. The rules, tested with `Europe/London` and `America/New_York`:
  - a local time that does not exist (the spring-forward gap) moves forward
    to the first instant after the gap, as PHP does;
  - a local time that happens twice (fall-back) means the **first**
    occurrence;
  - a day's hours are computed from that day's own offsets, so a 09:00 to
    17:00 day is 8 hours of wall clock even when it is 7 or 9 hours of
    elapsed time across a change.
  - Slots step through local wall-clock time, then every candidate is
    checked as an UTC interval, so nothing is offered twice or skipped.
- **Display** is in the workspace's zone (the web's `useDateDisplay()` with
  the zone passed in). The public page says which zone the times are in and,
  when the visitor's browser is in another zone, shows both. Confirmation
  emails state the zone by name.
- **Changing the workspace zone** moves future working hours with it (they
  are wall-clock) and leaves existing bookings at the instant they were made
  for. The settings screen says so.
- **ICS** writes `DTSTART`/`DTEND` in UTC (`...Z`), which every client
  converts, so core does not ship `VTIMEZONE` blocks.

### 3. Concurrency: no double booking

Two requests for the same person and time must never both succeed, on any
number of FrankenPHP workers. Every write that can take time on a staff
member's calendar (create, move or confirm a booking, create or move a busy
event) runs as:

1. `BEGIN`
2. `SELECT id FROM calendar_staff WHERE tenant_id = :t AND profile_id = :p FOR UPDATE`
   (the per-person lock row);
3. re-read that person's busy intervals in the requested range **inside the
   transaction** (bookings in `confirmed`, or `pending_payment` whose hold has
   not expired, using `blocks_from`/`blocks_until`; busy events; time off) and
   check the request against working hours, notice and window;
4. insert or update, then `COMMIT`.

Every reader of a person's calendar for a write takes the same lock, so the
check-then-insert is serialised per person and parallel writes for different
people do not wait on each other. "Any staff" tries candidates one at a
time, in a fixed order, taking each person's lock in turn, so no request
holds two locks and no deadlock is possible. A losing request gets **409**
with "That time was just taken" and fresh slots.

SQLite (tests and the desktop host) serialises writers anyway; the same code
runs there with `FOR UPDATE` dropped by the dialect helper. A Postgres test
fires parallel requests at one slot through separate connections and asserts
exactly one booking.

Rejected: a Postgres exclusion constraint on `tstzrange` needs the
`btree_gist` extension, which managed Postgres does not always allow and
SQLite does not have, and it cannot express "a pending hold stops counting
after `hold_expires_at`". A unique index on `starts_at` misses overlaps.

### 4. Core and plugin boundary (SDK 1.98)

Core owns the calendar, availability, bookings, the booking page, links,
notifications, ICS and the API. Plugins add to it through these contracts,
all in `Whity\Sdk\Calendar\*`, purely additive:

- **Payment step.** `PluginBookingPaymentInterface::getBookingPaymentStep():
  BookingPaymentStep`. One per deployment, like the billing portal; a second
  is refused and logged.
  - `requirement(BookingQuote $q): ?PaymentRequirement` says whether this
    booking needs paying first (amount in minor units, currency, a label such
    as "Deposit"). `null` means confirm straight away.
  - When it returns a requirement, core creates the booking as
    `pending_payment` with `hold_expires_at = now + calendar.payment_hold_minutes`,
    holding the slot, and calls `start(PendingBooking $b, string $returnUrl):
    PaymentHandoff` (a URL to send the customer to).
  - The plugin settles it through the host service `Whity\Sdk\Calendar\Bookings`:
    `confirmPaid(int $bookingId, string $paymentRef)` moves it to `confirmed`
    (and fails if the hold already expired and the slot was retaken; the
    plugin then refunds), `release(int $bookingId, string $reason)` cancels it.
  - Core expires lapsed holds itself (a queued job at `hold_expires_at`, plus
    the check in step 3 that ignores lapsed holds), so a silent plugin never
    blocks a slot for good.
  - Core never stores card data and never moves money.
- **External calendars.**
  - Events, each with an `.async` twin, after commit, ids only, in
    `Hooks\Events`: `calendar.event.created|updated|deleted`,
    `booking.created|confirmed|rescheduled|cancelled|expired`. A sync plugin
    writes outwards from these.
  - Inbound changes use the same host service: `Bookings` and
    `CalendarEvents::upsertExternal(profileId, externalRef, ...)`, so a plugin
    never writes core tables.
  - **Busy times from outside:** `PluginCalendarBusyInterface::busyTimes(int
    $tenantId, int $profileId, DateTimeImmutable $fromUtc, DateTimeImmutable
    $toUtc): list<BusyInterval>`. Core asks every provider when computing
    availability and re-asks inside the lock before a write. A provider is
    given a time budget (1.5 s); when it fails or times out, core logs it and
    **carries on without it** (fail open), because refusing every booking
    while Google is slow would be worse than the rare clash a sync plugin can
    flag. The plugin is expected to cache.
- **Not in core:** payments, external sync, SMS/WhatsApp, locations and
  resources, waitlists.

### 5. Notifications

Core sends a confirmation (with an `.ics` attachment), a reschedule notice, a
cancellation and one reminder (`calendar.reminder_hours_before`). They go
through one small interface, `BookingMessenger` (`confirmed`, `rescheduled`,
`cancelled`, `remind`), whose first implementation renders the branded email
layout and sends through the existing `Mailer`. `Mailer` gains an optional
`AttachmentMailer` interface (`multipart/mixed`) that `SmtpMailer`,
`OAuth2SmtpMailer` and `LogMailer` implement; a mailer without it sends the
email without the file. When the core messages API lands (ADR 0016,
`MessagingService::send()` with `source = 'calendar.reminder'`), a second
implementation sends through it and the first is retired, with no change to
the booking code. ADR 0016 leaves attachments out, so until it carries them
the confirmation with its `.ics` stays on the email path and only reminders,
reschedules and cancellations move over. Staff get an in-app notification through
`NotificationDispatcher` for bookings made on the public page.

Reminders are a queued job created when a booking is confirmed or moved, with
`delay` until `reminder_due_at` and an idempotency key of booking id plus
start time. The job re-reads the booking and does nothing if it was cancelled
or moved (a moved booking has its own job). A reminder more than 30 days out
re-queues itself at the 30-day limit.

### 6. Links, the booking page and the feed

- **Manage links.** Each booking gets a random 256-bit token; only its SHA-256
  is stored (`manage_token_hash`), the same pattern as invitations and
  document QR tokens. It expires at the booking's start time, and is replaced
  on every reschedule, so an old email cannot move a booking again. Chosen
  over an HMAC-signed URL because core has no application signing key to
  manage or rotate, and a stored hash can be revoked one booking at a time.
  The link page lets the customer cancel, or move within the same rules as a
  new booking.
- **Booking page:** `/book/{workspace}` in the web, backed by
  `GET /api/public/booking/{workspace}` (services, staff, settings),
  `GET .../slots`, and `POST .../bookings`. The workspace comes from the path
  slug only (`TenantHostRepository::findIdBySlug`), with the same 404 for an
  unknown workspace, one with the page off, and a Host naming another
  workspace. The manage page is `/booking/{token}` with
  `GET|POST /api/public/bookings/{token}/...`.
- **Rate limits and spam:** per-IP limits counted before the workspace is
  looked up (slots 120 an hour, bookings 10 an hour, manage actions 30 an
  hour), a per-workspace ceiling (200 public bookings a day), a hidden
  honeypot field, a minimum time on the page before submit, at most 3 open
  future bookings for one email in a workspace, and the existing Turnstile
  `BotCheck` when the workspace has set a site key. A refusal past a limit is
  429 with `Retry-After`.
- **ICS feed:** each staff member can turn on a feed URL,
  `/api/public/calendar/feeds/{token}.ics`, listing their bookings and events
  from 30 days back to the booking window ahead. Token 256-bit, hash stored,
  looked up globally (a reasoned `@tenant-guard-ignore`, as the public form
  slug), and replaceable from the calendar screen. Customer names appear in
  the feed; the screen says so when the feed is turned on.

### 7. Tenant isolation

- Every table is tenant-owned and every query binds `tenant_id`; the
  tenant-predicate guard covers the new code with no new ignores beyond the
  two token lookups (manage token, feed token), each of which reads the
  tenant from the row it found and then sets `TenantContext`.
- Cross-row references are checked within the tenant: a service's staff,
  a booking's staff and service, and a linked customer must all belong to the
  booking's workspace (a staff member must hold a `member` membership there,
  a customer a `customer` or `member` one). The API answers 404, not 403, for
  ids from another workspace.
- Public routes take the workspace only from the path, and are added one by
  one as anchored patterns.
- Plugin calls receive the tenant id from core and act through host services
  that re-apply it; a plugin cannot name another workspace's booking.
- Tests: a two-workspace test for each public and staff route, and the
  existing isolation suites.

### 8. Plan limits

Booking counts toward `customers.per_month` because a booked customer is "a
person outside the workspace's staff that it served". When a booking becomes
`confirmed` core calls `CustomerUsage::served($tenant, $ref)` with
`profile:<id>` when the customer is linked to a profile that is not staff,
and otherwise `booking-email:<sha256 of the lower-cased email>` so the same
person booking twice in a month counts once without storing the address in
the count. Bookings for staff members count nothing. It stays warn-only;
nothing is refused.

### 9. Permissions, API and MCP

- Slugs: `calendar:read` (see the team's calendars and bookings),
  `calendar:write` (create and change one's own events, book, move and cancel
  for customers), `calendar:manage` (services, everyone's hours and time off,
  other people's events, the booking page settings). Granted by capability in
  migration 249, never to a role by name; audiences are chosen so the
  holder guard passes and an administrator holds all three on upgrade.
- Routes under `/api/calendar/...` with CoreApiSchemas declarations, which
  derive the MCP tools: list availability, list bookings, create, move and
  cancel a booking. Tool labels in en/ar in `mcp-tool-names.json`.
- **An agent never books directly.** Create, move and cancel are marked so an
  agent's call always becomes a proposal, whatever its scope grant says, with
  a preview of the slot, the customer and who it acts for. The person approves
  and the call runs again under the lock; if the slot has gone by then, the
  approval fails with the same 409 and a fresh list of slots.

## Alternatives Considered

- **Bookings as `calendar_events` rows with a type.** One table is simpler to
  draw, but bookings carry a customer, a service, a price, a status machine
  and tokens that events do not. Two tables, read together for busy time.
- **Contacts table now.** A CRM is its own feature. The snapshot plus an
  optional profile link is enough for bookings and does not decide a
  contacts model for the rest of core.
- **Reuse meetings (convening).** Governance meetings have agendas,
  attendance and decisions, no availability or customers. Kept apart.
- **Per-staff time zones.** Useful for remote teams; the owner's first users
  are one-location shops. A `time_zone` column can be added to
  `calendar_staff` later with no change to the stored instants.
- **Exclusion constraint for overlaps.** See 3.
- **Fail closed on external busy times.** See 4.

## Consequences

### Positive

- A workspace can take bookings with no paid plugin and no outside service.
- Payment and sync plugins have a narrow, documented seam and never touch
  core tables.
- Availability is a pure function of stored rows, settings and provider busy
  times, so it is testable across zones and clock changes.

### Negative / Trade-offs

- The per-person lock serialises writes for one person. A busy shop with one
  person sees bookings queue for a few milliseconds each, which is fine at
  this scale.
- Failing open on external busy times can let a clash through while a sync
  provider is down.
- Customer details live on the booking, so a later contacts feature will
  need a backfill to link them.
- Email-only reminders until the messages API lands.

### Impact on existing conventions

- Migrations 247 to 250; tenant-owned tables registered; grants by
  capability.
- `Mailer` gains an optional attachment interface.
- Four new settings and two week-layout settings in `SettingsRegistry`.
- SDK 1.98 adds the `Calendar` namespace, two capability interfaces and the
  booking and calendar events.
- New public routes in the tenant-isolation list, one by one.

## Delivery

One pull request per piece, each with its own WHIT id and GitHub issue:

1. This ADR (WHIT-940, #1812).
2. WHIT-941 (#1813), server: schema, availability engine, bookings with the lock, staff API,
   permissions, MCP tools.
3. WHIT-942 (#1814), server: booking page API, manage links, rate limits and spam checks,
   email with ICS, reminders, feed, plan-limit counting.
4. WHIT-943 (#1815), SDK 1.98: payment step, busy-time providers, events, host services.
5. WHIT-944 (#1816), web: staff calendar (day, week, month), services, hours and time off,
   settings.
6. WHIT-945 (#1817), web: public booking page and the manage page.
7. WHIT-946 (#1818), brand ask for the calendar screens (`brand-ask-calendar`), not blocking.

## References

- ADR 0005 (identity and memberships), ADR 0010 and #1799 (plan limits),
  ADR 0013 (domain events), ADR 0014 (plugin exception).
- WHIT-915 customer sign-up, the public form routes (migration 132,
  `PublicFormsApiHandler`), `PublicFormGate` and `BotCheck`.
- `docs/design/00-decision-register.md` (week start and weekend),
  `docs/design/21-patterns-and-people.md`.
