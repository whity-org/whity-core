# 39 · Appointments you can rely on: no-shows, resources, clinics and the front desk

**Status: proposed, 25 November 2026 (WHIT-1408). Waiting for the owner's answers to section 13. Nothing here is built yet.** The register rows are AP.2–AP.31 (section "Appointments you can rely on"). AP.1 (version 3.1) stays as it is.

The owner, 25 November 2026: "we want FTA rates and better appointment system, something we can rely on for general audiences like clinics, dentists, salons, etc...". FTA is "failed to attend", which most businesses call a no-show. The owner chose four focus areas, in this order:

1. **Reliability first.** No double bookings. Rooms, chairs and equipment as resources. Buffers, staff hours and time off. A waitlist. Reminders and no-show tracking that always work.
2. **Clinic features.** A record for each visit, treatment plans of several visits, chairs and rooms, services that depend on the provider, and intake forms before the visit.
3. **Online booking.** The public page and a widget, deposits through Whity Pay, and customers moving and cancelling their own bookings.
4. **The front desk.** A fast day view, check-in, walk-ins, the queue, running late, and moving by drag or keyboard.

Business model (BM, 21 November 2026): all of this is core and free. Only usage and seats, and outside integrations, are paid: WhatsApp, SMS, Google and Microsoft calendar sync, and payment gateways through Whity Pay. This replaces ADR 0017's line that resources, waitlists and deposits are paid store plugins. Deposits keep a paid part, because the payment gateway is an outside integration (§9.3).

## 1. What exists today (origin/develop at 7711bb64b)

The calendar in core is good. ADR 0017 built it, and versions 3.5 (CV.1–CV.12) and L4 (SL.1–SL.12) extended it. It already has:

- **Data**
  - Staff calendars (`calendar_staff`) and weekly hours (`calendar_working_hours`, wall-clock `HH:MM`).
  - Time off and other busy time, stored as `calendar_events` with `kind = time_off`.
  - Services, with a length, buffers before and after, a price, a room, "needs approval", "bookable online", and who provides them (`calendar_service_staff`).
  - Rooms (`calendar_rooms`, at most one per service).
  - Bookings with several services (`calendar_booking_services`).
  - The walk-in queue (`calendar_walkins`).
  - Commission and the price list.
- **Time.** Every instant is stored as UTC in `TIMESTAMP` columns. Hours become UTC for each local date in the workspace's zone, and DST is handled: a time in the spring-forward gap moves to the jump, and a repeated time takes its first occurrence. Five DST unit tests cover Amman and New York (`tests/Unit/Calendar/AvailabilityTest.php`).
- **Booking safely**
  - Every write that takes time opens a transaction, locks each person's `calendar_staff` row and then the room's row (`SELECT … FOR UPDATE`, people first, in id order), re-reads busy time, and only then inserts (`BookingService::locked`, `whyNotFree`).
  - Busy time includes buffers, holds still waiting for payment, time off, meetings and plugin busy sources.
  - Two Postgres-only tests prove that two connections booking the same time make exactly one booking (`BookingRealEngineTest`).
- **Statuses.** `requested`, `pending_payment` (a hold that expires), `confirmed`, `cancelled` and `expired`. Check-in is `checked_in_at`. Staff can choose **Book anyway** over a busy person, but never over a taken room or closed hours.
- **Public page.** `/book/[workspace]`, with slots, holds and limits: per IP, per workspace each day, a bot check, and at most three open bookings per email address. The customer's link `/booking/[token]` lets them move or cancel until the booking starts. It is a 256-bit random token, and only its hash is stored.
- **Reminders.** One reminder per booking, `calendar.reminder_hours_before` (default 24 hours). It is sent by email with an `.ics` file, and by text on any messaging plugin. It is sent from a delayed queue job, claimed once (`reminder_sent_at`), and skipped if the booking moved.
- **Payment seam.** `BookingPaymentStep` in the SDK, with `HostBookings::confirmPaid` and `releaseHold`.
- **Front desk.** The Today counts, check-in and undo, walk-ins behind `calendar.walkins_enabled`, drag and keyboard moves on the grid (CV.9, CV.10), and phone views (CV.11).
- **Customers.** Bookings link to a contact (`contact_id`), and a contact's activity lists their bookings.

## 2. The gaps

### 2.1 Reliability (the bugs and risks)

| # | Gap | Why it matters |
| --- | --- | --- |
| G1 | **The database does not prevent overlaps.** Only the application's row locks keep two bookings apart. Any writer that skips `BookingService::locked` can double-book without anything stopping it: a future import, a plugin writing through a host service that forgets the lock, a migration copy, a manual fix. SQLite (used by tests and self-hosted trials) has no `FOR UPDATE` at all. | "No double bookings" should be something the database guarantees. Today it holds only as long as every code path is written correctly. |
| G2 | **An expired hold blocks time until its row is read again.** Busy time filters holds by `hold_expires_at`, so the time is correct. But nothing ever records that a hold is gone, so it can't be told apart from a hold still waiting for payment. | Fine today, but a database guarantee (G1) has to treat expired holds explicitly. |
| G3 | **A lost reminder is never sent.** Each reminder is one delayed queue job. If the queue loses it (Redis flushed, a worker crash beyond its retries, a deploy that clears delayed jobs), nothing sweeps `reminder_due_at`. | "Reminders that always work." Missed reminders cause no-shows. |
| G4 | **Only one reminder, with no reply.** The customer can't answer "I'm coming" or "I can't come" from the reminder. | Confirmation replies are the cheapest way to cut no-shows. |
| G5 | **External busy sources fail open without telling anyone.** If Google sync errors, slots are offered as free and the error goes only to `error_log`. | Correct as a default (never block all booking), but the desk should see that a calendar wasn't checked. |
| G6 | **Time off can be added over existing bookings** with no list of the bookings it hits. | The desk finds out when the customer arrives. |
| G7 | **One time zone per workspace.** Branches (units) have none of their own. | A global product (WS.17) with branches in two zones books the wrong hour. |
| G8 | **The public page can send the same booking twice.** A double tap or a retry on a slow network sends the request again. The time can't be booked twice, but a second, different service or person can be. There is no idempotency key. | Duplicate bookings, and a duplicate no-show later. |
| G9 | **Customers can cancel up to the start time** with no late-cancel window, and the change isn't recorded as late. | Clinics lose the slot without knowing why, and late cancels are a large share of missed appointments. |

### 2.2 No-shows (FTA)

- There is no state for "didn't come". A confirmed booking whose time has passed and that was never checked in looks the same as one where the desk forgot to press Check in.
- Nothing counts no-shows or late cancels for a staff member, service, customer or period, and nothing shows them.
- There are no policies: no flag for a customer who misses often, and no deposit after repeated misses.

### 2.3 Resources

- At most one room per service. Rooms have no kind and no quantity: there is no "chair", no "any free chair", and no equipment such as an X-ray unit or a laser shared by two rooms.
- A service can't need two things at once, such as chair 2 and the X-ray.

### 2.4 Clinics

- **No record of each visit.** Notes and forms can't be attached to a booking, and there is no permission for sensitive notes.
- **No treatment plans.** Linked visits ("6 sessions, 2 weeks apart") don't exist.
- **Provider-specific services are partial.** `calendar_service_staff` says who provides a service, but not their own length or price (the dentist's cleaning takes 45 minutes, the hygienist's 30).
- **No intake forms.** Forms exist (section 22, FP), but a booking can't ask for one, link to one, or show at the desk whether it's done.

### 2.5 Online booking

- **No embeddable widget.** The page sends `frame-ancestors 'none'`, so there is only a link and a QR poster.
- **No Whity Pay booking step.** The SDK seam exists, but the Whity Pay plugin doesn't implement `BookingPaymentStep`, so deposits can't be taken today.
- **The manage page shows no cancellation policy,** and doesn't say when a cancel counts as late or loses the deposit.

### 2.6 Front desk

- **The day view is the general calendar grid.** There is no desk view with columns per person or room, statuses beyond checked in (in the chair, done, didn't come), a list of who to mark, or a combined queue of the walk-ins and today's arrivals.
- **No running late.** The desk can't record "customer 10 minutes late", or "Dr Ahmad is 20 minutes behind" and ask to move the bookings after it.
- **No waitlist.** Walk-ins are the only queue.

## 3. Words

The words are general: staff member, not doctor or stylist. The salon and clinic words belong in workspace templates.

| English / Arabic | Code | Means |
| --- | --- | --- |
| Came / حضر | `attendance = attended` | Checked in, or marked as came afterwards. |
| Didn't come / لم يحضر | `attendance = no_show` | The FTA. The time passed, they never came, and they didn't cancel. |
| Late cancel / إلغاء متأخر | `cancel_kind = late` | Cancelled by or for the customer inside the late-cancel window. |
| Cancelled in time / إلغاء في الوقت | `cancel_kind = early` | Cancelled before the window. |
| We cancelled / ألغيناه نحن | `cancel_kind = business` | The business cancelled. Never counts against the customer. |
| Due appointments / المواعيد المستحقة | | Bookings whose time has come: came, didn't come, or late cancel (§4.2). |
| No-show rate / نسبة عدم الحضور | | Didn't come ÷ due (§4.2). The label is question 1. |
| Resource / مورد | `calendar_resources` | A room, chair or piece of equipment that one booking at a time can use. |
| Visit record / سجل الزيارة | `calendar_visit_notes` | What happened at one booking: notes and forms. |
| Treatment plan / خطة علاج | `calendar_plans` | A set of visits in order, for one customer. |
| Waitlist / قائمة الانتظار | `calendar_waitlist` | People who want an earlier or a specific time, offered it when it frees up. |

## 4. No-show (FTA) rates

### 4.1 Recording what happened

Each booking gets `attendance` (null, `attended`, `no_show`) and, when it is cancelled, `cancel_kind` (`early`, `late`, `business`).

- **Check in** sets `attended`. So does a walk-in handover, and **Mark as came** afterwards.
- **Didn't come** is a button on the booking. From the start time plus `calendar.no_show_grace_minutes` (default 15), it also appears in the desk's **Did they come?** list. That list holds today's past bookings with no attendance, one row each, with **Came** and **Didn't come** buttons, and it shows every unmarked booking until someone answers.
- **End-of-day marking** is a workspace switch, `calendar.auto_no_show`, off by default (question 2). When it's on, at the end of the business day in the branch's zone, bookings still unmarked become `no_show`, marked by "Whity (end of day)". A person can always change it.
- **A late cancel.** A customer cancelling on their link, or staff cancelling with the reason "the customer asked", inside `calendar.late_cancel_hours` (default 24) sets `cancel_kind = late`. Before the window it's `early`. Staff cancelling with "we cancelled" sets `business`.
- **Changing a mark.** Every change records who and when (`attendance_by`, `attendance_at`) and writes an audit entry. An agent may only propose a mark, never set it (agents rule).
- **What never counts:** requests that were never approved, holds that expired unpaid, bookings marked "we cancelled", and team events.

### 4.2 The rates

For a period, with every filter applied the same way:

- **Due** = came + didn't come + late cancels.
- **No-show rate** = didn't come ÷ due.
- **Late-cancel rate** = late cancels ÷ due.
- **Missed rate** = (didn't come + late cancels) ÷ due.

Due bookings whose time has passed but which nobody has marked yet are shown as **Not marked: n** and left out of every rate. When more than 10% of due bookings are unmarked, the rate is shown with "Not all bookings are marked". This keeps a forgetful desk from turning into a false 0%.

**Filters:**
- staff member, service, customer, branch, and room or resource;
- source (staff, online, agent, walk-in);
- weekday and hour;
- whether a reminder was sent and whether the customer confirmed;
- new or returning customer.

A booking with several services counts once, under its first service, and under each service when filtering by service.

**Periods:** this week, this month, last month, last 3, 6 or 12 months, or chosen dates, always in the branch's time zone. Each figure has a trend against the period before.

The rates are worked out from the bookings each time, the way the commission report is (SL.5). Nothing is stored except the marks, so a corrected mark changes the past at once.

### 4.3 Where it shows

| Place | What |
| --- | --- |
| **Dashboard widget** "Appointments" | Due this month, the no-show rate and late-cancel rate with their trend, Not marked: n, and the three customers who missed most. A link opens the report. |
| **Calendar booking** (grid, details, desk) | A small flag "Missed 2 of the last 5" on the customer's name when the policy flag applies (§4.4). The words are always written out, never shown by colour alone. |
| **Customer record** (contact page, Bookings tab) | One line: "Came 12 · Didn't come 2 · Late cancels 1 · since March 2026", with the list filterable by these. |
| **Reports › Appointments** (new, Calendar group) | Tables and bars by staff member, service, customer, weekday × hour, source and reminder. CSV export. Needs `calendar:reports` (question 7). |
| **Staff member's page** | Their own rate for the period, read by them and by anyone with `calendar:reports`. |
| **MCP and API** | `GET /api/v1/calendar/attendance` (the same filters) and `calendar.attendance.marked` events. |

### 4.4 Policies (optional, per workspace)

All the policies count over a window, `calendar.no_show_window_months` (default 12):

- **Flag**, on by default. From `calendar.no_show_flag_after` misses (default 2: didn't come, plus late cancels if `calendar.no_show_count_late_cancels` is on) the customer carries the flag in §4.3.
- **Ask for a deposit**, off by default. From `calendar.deposit_after_no_shows` misses (default 2), online bookings by that customer need a deposit (§9.3). If no payment provider is connected, the booking becomes a **request** that staff approve instead.
- **Approve their online bookings**, off by default. From N misses, their online bookings become requests. Never a silent block: the customer is told "The business will confirm your booking" (question 5).
- **Forgiving.** A staff member with `calendar:manage` can clear a customer's count ("Start again from today"). The clearing is recorded.

The customer is never shown the word "no-show". The booking page and emails say only what to do next, such as "A deposit is needed to book online" (SU.10).

## 5. Reliability

### 5.1 The database refuses overlaps (G1, G2)

A new table, `calendar_holds`, holds one row for each thing a booking or busy event takes:

| Column | Notes |
| --- | --- |
| `tenant_id`, `id` | |
| `booking_id` or `event_id` | Exactly one of them is set (CHECK). A booking with several services has one row for each part and for each thing that part takes. |
| `holder_kind` | `person` or `resource` |
| `holder_id` | The profile id, or the `calendar_resources.id` |
| `during` | `tsrange(blocks_from, blocks_until, '[)')`, buffers included |
| `exclusive` | false only for a person on a **Book anyway** booking, or for a person's own non-busy event |
| `expires_at` | Set on payment holds |

On Postgres, with the `btree_gist` extension:

```sql
EXCLUDE USING gist (tenant_id WITH =, holder_kind WITH =, holder_id WITH =, during WITH &&)
  WHERE (exclusive)
```

- **Written in the same transaction** as the booking, by the same service, under the same row locks, which stay. The locks keep error messages friendly ("Dr Ahmad is busy"). The constraint guarantees that no exclusive pair can overlap, whoever writes.
- **A refused insert** (SQLSTATE `23P01`) is turned into the existing 409 (`person_busy` or `room_taken`), so callers see no new error.
- **Cancel, expire and move** delete or rewrite the rows. Before inserting, the writer deletes rows whose `expires_at` has passed for the same holders (G2). The hold-expiry job also removes them.
- **Book anyway** stays for people only (CV.9): the person's row is `exclusive = false`. Resources are always exclusive. Book anyway never passes a taken room, chair or piece of equipment.
- **Time off** and busy team events write person rows. Book anyway can still go over them, because that booking's person row isn't exclusive.
- **Existing data.** One migration fills `calendar_holds` from live bookings and busy events. Overlaps already in the data (made through Book anyway or before the locks) are copied as non-exclusive and listed by `whity-cli calendar:overlaps` for the owner, never changed automatically.
- **SQLite** keeps the row-lock path, and a test-only check asserts that no exclusive holds overlap after every write in the calendar integration tests.
- **Proof.** Parallel-process tests on Postgres write directly through a repository that skips the service, and must be refused by the database (verified-change: "prove a guard can fail").

`btree_gist` ships in every Postgres contrib build that Whity supports (the official image and managed Postgres). Self-hosted installs without it fail the migration with a plain message (question 8).

### 5.2 Reminders always go out (G3, G4)

- **The sweep.** A scheduled job runs every 5 minutes. It takes confirmed bookings whose reminder is due, still unsent, and whose start is still ahead, and sends their reminders through the same claimed send (`markReminded`), so a reminder already sent is never sent twice. The delayed job stays as the fast path, and the sweep is the guarantee.
- **Two reminders.** By default 24 hours and 2 hours before (`calendar.reminders` = `24h,2h`; the version 3.1 value). Each reminder goes by email, which is free, and by WhatsApp or SMS when connected, which are paid integrations. Each send is kept in `calendar_booking_reminders` with the channel, the outcome (sent, failed, no address) and the time. The desk sees "Reminder sent 09:00 by WhatsApp", or "Reminder failed: no phone".
- **Answering.** The reminder carries **I'm coming** and **I can't come** (links to the manage page, not actions inside the email, EM.1). I'm coming sets `confirmed_by_customer_at`. I can't come cancels, counted as early or late by the window, and offers the time to the waitlist (§8).

### 5.3 Time zones (G7)

- **A branch can have its own time zone** (`organizational_units.time_zone`, IANA). If it has none, the workspace's zone is used. A staff member's hours and the branch's bookings use the zone of the branch they work in. Each resource belongs to a branch.
- **The public page** shows times in the branch's zone, with the zone written out ("Amman time"). When the visitor's own zone differs, it adds one line: "That's 10:00 your time".
- **Tests.** Slot generation is run across every DST change in a year for Amman, London, New York, Sydney and a zone with a 30-minute DST change (Lord Howe), comparing the wall-clock hours offered on each date.

### 5.4 Smaller guarantees

- **Idempotent public booking (G8).** The page sends an `Idempotency-Key`, and a repeat within 10 minutes returns the first booking.
- **Time off over bookings (G6).** Saving time off lists the bookings it covers, with **Move them** (each one asks the customer first, CV.9) or **Keep them**.
- **External calendars (G5).** If a busy source fails, booking stays open, and the desk and that staff member see "Couldn't check Dr Ahmad's Google calendar at 09:14". The failure is counted in the health page.
- **Late-cancel window (G9),** in §4.1 and §9.2.

## 6. Resources: rooms, chairs and equipment

- **One table.** `calendar_rooms` becomes `calendar_resources`. It is renamed, with a view keeping the old name for one release, and the columns are kept. New columns: `kind` (`room`, `chair`, `equipment`, `other`), `branch_id`, `group_key` (for example "Chairs" or "Treatment rooms"), `active`. Something you have several of, such as 3 chairs, is three rows in one group, so the exclusion in §5.1 covers each one with no counting.
- **What a service needs.** `calendar_service_resources` lists, for each service, either a specific resource ("the X-ray") or **any one from a group** ("any free chair"). A service can need several. `calendar_services.room_id` becomes the first requirement.
- **Choosing.** For "any from a group", the booking takes the first free resource in the group's order. The desk can change it, and staff can pin one. The public page never asks the customer to choose a chair.
- **Booking.** All the resources are locked in id order after the people (as rooms are today) and written as hold rows. A slot is free only if the person and every requirement are free.
- **Screens.** Settings › Calendar › **Rooms and equipment** (the old Rooms page): a list with kind, group and branch, and a page for each resource with its own day.

## 7. Clinics

### 7.1 The person and the service

`calendar_service_staff` gains `duration_minutes`, `buffer_after_minutes` and `price_minor`, all nullable. The values the booking used are copied onto it. When they're empty, the service's own values apply. The public page shows the length and price for the person chosen, or a range ("30–45 min") when the person is "anyone".

### 7.2 Intake forms before the visit

- **The link.** A service can name forms (section 22) to fill before the visit (`calendar_service_forms`: `form_id`, `when` = `on_booking` or `before_visit`, `required`). New customers only, or every visit.
- **On booking.** The confirmation, and the public page after booking, link to the form, pre-filled with the customer's name, phone and email (as WHIT-1179 does for booking prefill). The submission stores `booking_id` and `contact_id`.
- **At the desk.** The desk and the booking show "Intake form: done", "not done" or "not needed", with **Send again** and **Fill in at the desk**, which opens the same form on a tablet in kiosk mode. A required form never blocks check-in. It only shows "not done".
- **Form printouts** (FP.1) work unchanged, and the printout appears on the visit record.

### 7.3 The visit record

- **What it is.** One record for each booking (`calendar_visit_notes`): the staff member's notes (rich text, from the document editor's note blocks), the forms filled for it, attached files, and the treatment-plan step it belongs to.
- **Who sees it.** People with the new `calendar:visit_notes` permission, which is not granted by any capability. An admin grants it to the roles that need it. The treating staff member can always see their own visits' notes. Customers never see them. Agents can read them only through a permission granted the same way, and every read is audited.
- **Changes.** Notes are signed off by their author with **Finish visit**. After that, changes are added as dated corrections, never edits over the original (question 10).
- **What it isn't.** Not a medical records system: no prescriptions, no diagnosis codes, no claims of medical certification. Data is stored in the EU (WS.18). The register words are "hosted in the EU", never "HIPAA" or "GDPR compliant".

### 7.4 Treatment plans

- **What it is.** `calendar_plans` (customer, title, staff member, status `active`, `done` or `stopped`) and `calendar_plan_steps` (order, service, gap after the previous one in days, with a range such as "14–21 days", and the booking once made).
- **Making one.** From a template ("Braces check-ups", "Laser, 6 sessions"), which is a service setting, or written on the visit record.
- **Booking the steps.** **Book the next visit** on finishing a visit suggests the first free time in the gap range with the same person. **Book all** books every step at once, each one checked as a normal booking. A step that can't be placed is left unbooked and shown.
- **Progress.** "3 of 6", shown on the customer record and the booking. A missed step counts as a no-show like any booking, and the plan shows it.

## 8. Waitlist

- **What it holds.** `calendar_waitlist`: the customer (contact), the service, the staff member or anyone, dates from and to, times of day (morning, afternoon, evening, any), added by staff or by the customer on the public page ("No time that suits you? Join the waitlist"), and status `waiting`, `offered`, `booked` or `removed`.
- **When a time frees up.** A cancel, a move, or "I can't come" frees time. The entries that match are offered it at once, oldest first, by email (free) and WhatsApp or SMS when connected. The offer links to a page with that one slot. **The first person to take it gets it** (a normal booking, under the locks), and the others then see "Someone else took this time. You're still on the waitlist". Offers end at the slot's start minus the minimum notice.
- **No decisions by machine.** This is a rule, offering in order, not a decision. Staff can also offer a time by hand from the waitlist list.
- **At the desk.** Calendar › **Waitlist**, with the count shown on the desk view.

## 9. Online booking

### 9.1 The page and the widget

- **The page** wears the workspace's brand (SU.1) and opens in the workspace's language, with a switch.
- **New on the page:** the cancellation policy in one sentence before **Book**, the intake form step (§7.2), the waitlist (§8), and the deposit (§9.3).
- **The widget.** A script tag the business pastes into its own site (`<script src="…/embed.js" data-workspace="@handle">`) opens the booking page in an overlay iframe. Only the `/book/*/embed` route allows framing. Its `frame-ancestors` is the list of sites the workspace adds in Settings › Calendar › **Your website** (none by default, so nothing is framed until they add one). The route has its own `PUBLIC_ROUTE_PATTERNS` entry.
- **A plain link** and the QR poster stay.

### 9.2 Customers moving and cancelling

- **The manage page** shows the policy: "Free to cancel or move until Tuesday 10:00. After that it counts as a late cancel, and the deposit is kept".
- **Moving** inside the window is allowed once and doesn't count as a miss (question 4). Cancelling inside the window is allowed, records a late cancel, and keeps the deposit (question 3).
- **The link** still expires at the start time and is renewed on every move (unchanged).

### 9.3 Deposits through Whity Pay

- **Core (free):**
  - each service has a deposit: none, a fixed amount, or a percentage;
  - the deposit is in the booking's currency (CU.1–CU.4) and is shown in normal units;
  - the no-show policy (§4.4) can require it;
  - the deposit sits on the booking as `deposit_minor`, `deposit_state` (`due`, `paid`, `kept`, `refunded`, `applied`), with the payment reference.
- **Whity Pay (the paid gateway):** implements `BookingPaymentStep` in whity-plugins. The slot is held for `calendar.payment_hold_minutes`, the checkout is hosted by the provider, and `confirmPaid` confirms the booking.
- **What happens to the deposit:**
  - **Kept** after a late cancel or a no-show.
  - **Refunded** after an early cancel or "we cancelled". A refund asks a person with `calendar:manage` first, as one click from the booking: money moves only on a person's click (question 13).
  - **Applied to the bill** when they came. When the invoice is made (accounting, MO.1), the deposit is applied to it.
- **No gateway connected:** the deposit settings show "Connect a payment provider to take deposits", and the policy falls back to requests (§4.4).

## 10. The front desk

### 10.1 The day view

Calendar › **Front desk** (`/calendar/desk`), for people with `calendar:front_desk`.

- **Columns:** one per staff member working today, or per room or resource (a switch), in a 15-minute grid at 48px an hour, with now as a red line (CV.6). A **List** switch shows one row per booking in time order, which is the default under 1024px.
- **Each booking** shows its time, customer, service and status, and a flag when there is one.
- **Statuses:** **Expected**, **Arrived** (`checked_in_at`), **With staff** (`started_at`), **Done** (`finished_at`), **Didn't come**. Each status change is one labelled button on the booking: Check in, Start, Finish, Didn't come. The keys are I, S, F and N, with the selected booking moved by the arrow keys (CV.10).
- **The side panel:**
  - **Waiting now:** arrived and walk-ins, in arrival order, with minutes waited;
  - **Did they come?** (§4.1);
  - **Waitlist** count;
  - **Forms not done** today.
- **Walk-ins** are added from the main button **Add walk-in** when `calendar.walkins_enabled` is on. Otherwise the main button is **New booking**.
- **Moving.** Drag and keyboard moves work as on the calendar (CV.9, CV.10): ask first for a customer's booking, then Undo.
- **Speed.** It loads under 300 ms for 30 staff and 400 bookings. It is one request that returns the day's bookings, holds, walk-ins and waitlist count, and it is refreshed by a light check every 20 seconds that returns only what changed since the last one (the web app has no live channel yet), and at once after the desk's own changes.

### 10.2 Running late

- **The customer is late.** **Running late** on the booking takes 5, 10, 15 minutes or another value. It is shown on the booking and doesn't move anything. A late arrival past the grace time can still be checked in.
- **A staff member is behind.** **Running late** on a staff member's column takes the minutes. It lists the rest of their bookings today with new times, pushed by that amount where the next booking or a resource allows. Bookings that can't be pushed are marked "needs a decision". **Move and tell them** asks first, then sends each customer "Your appointment is now at 11:20". Nothing moves without that click.

## 11. Phone and Arabic

- **Phone.** Under 640px the web app uses the PW patterns (PW.1–PW.11) and the native app's (section 26):
  - the desk is the **List** view, with each booking as a 48px-high row;
  - Check in is a labelled button on the row, with Start, Finish and Didn't come in a sheet;
  - **Add walk-in** floats on its plane under the thumb;
  - **Did they come?** is a sheet from the bottom bar;
  - text is 17px;
  - reports become one figure per card, with the tables scrolling sideways in a sheet;
  - the manage, waitlist-offer and intake pages are single-column, at 17px with 48px targets.
- **Arabic.** Every new string has an Arabic label (the table in §3 plus the screens):
  - logical CSS throughout;
  - times, amounts, rates and codes stay in Western digits, left to right ("12.5%");
  - time ranges are written "من 10:00 إلى 10:30", never as a reversed range;
  - zone names are shown in Arabic where the platform's ICU data has them.
  - The reminder and waitlist messages have Arabic versions picked by the customer's language, falling back to the workspace's.
- **Sizes for people over 50 (A.1–A.3):** 15px text, nothing under 13px, 40px controls, 48px table rows. Labelled buttons for common actions; ⋯ only for Clear count, Delete resource and similar.

## 12. Milestones (after the owner answers)

Reliability first. Each milestone is one PR with its own WHIT id, migration numbers and SDK minor from the orchestrator, and a changelog fragment.

| # | Milestone | Migrations (about) | Main pieces |
| --- | --- | --- | --- |
| M1 | **No double bookings in the database** | 2 | `calendar_holds` with `btree_gist` and the exclusion, backfill, `calendar:overlaps`, `23P01` → 409, idempotent public booking, parallel and direct-write tests |
| M2 | **Reminders always go out** | 1 | The sweep, several reminders, `calendar_booking_reminders`, I'm coming and I can't come, the desk's reminder line |
| M3 | **Attendance and no-show marking** | 1 | `attendance`, `cancel_kind`, the late-cancel window, Didn't come, Did they come?, optional end-of-day marking, the customer-record line, the flag policy, events and API |
| M4 | **No-show reports and the dashboard widget** | 0 | Reports › Appointments, the widget, `calendar:reports`, CSV, MCP tool |
| M5 | **Resources** | 2 | `calendar_resources` (rename and new columns), `calendar_service_resources`, any-from-group, the Rooms and equipment screens |
| M6 | **Staff and time** | 1–2 | Per-person length and price, branch time zones, time off over bookings, the external-calendar warning, the DST suite |
| M7 | **Front desk** | 1 | `/calendar/desk`, `started_at`/`finished_at`, side panel, running late (both kinds), phone list |
| M8 | **Waitlist** | 1 | `calendar_waitlist`, public join, offers, first-taker-wins |
| M9 | **Intake forms and the visit record** | 2 | `calendar_service_forms`, `booking_id` on submissions, kiosk fill, `calendar_visit_notes`, `calendar:visit_notes`, Finish visit |
| M10 | **Treatment plans** | 1 | `calendar_plans`, steps, Book the next visit, Book all, progress |
| M11 | **Online booking** | 1 | Widget and embed route, policy on the page and manage page, deposits in core, the deposit policy. Whity Pay's `BookingPaymentStep` is a whity-plugins PR after this one |

M1 and M2 touch no screens except one line on the booking. M3 onward each get English and Arabic, both themes, and a phone check on a local stack before they're called done. No brand cards exist for the desk, reports, waitlist, visit record or plans. They are built as core screens from the existing cards (`TplCalendarStaff`, `TplCalendarWeek`, Pw*) until cards arrive (question 14).

## 13. Questions for the owner

Each has a recommendation. "Accept all" takes every recommendation.

1. **What do we call it on screen?** **Recommended:** "No-show" for the rate and "Didn't come" for the mark (Arabic "عدم الحضور" / "لم يحضر"). "FTA" appears only in the API name `no_show` and in help text ("also called FTA or DNA"). Plain words are easier for salons, and clinics know "no-show".
2. **Who marks a no-show?** **Recommended:** staff mark it, helped by the Did they come? list. End-of-day automatic marking is a workspace switch, off by default. A forgotten Check in would otherwise turn into a false no-show against a real customer.
3. **Cancelling inside the window:** allowed and counted as late (the deposit kept), or not allowed online (the customer must call)? **Recommended:** allowed, counted as late, deposit kept. Blocking it makes people simply not come, which is worse.
4. **Moving inside the window:** does it count as a miss? **Recommended:** no. One move inside the window is allowed online and not counted. A second one needs the desk.
5. **What can the no-show policy do automatically?** **Recommended:** the flag is on at 2 misses in 12 months. "Deposit after N" and "approve their online bookings after N" are off by default, each a switch. Never a silent block. Late cancels count toward the flag only if the workspace switches that on.
6. **What is due, for the rate?** **Recommended:** came + didn't come + late cancels. Unmarked bookings are left out and shown as "Not marked". The alternative, dividing by all bookings including early cancels, makes the rate look lower than it is.
7. **Who sees the rates?** **Recommended:** a new `calendar:reports`, granted by capability to roles with `calendar:manage`. Every staff member sees their own rate. The flag on a booking is visible to anyone who can see the booking (`calendar:read`).
8. **Is the database guarantee required everywhere?** It needs Postgres's `btree_gist`. **Recommended:** yes, on every Postgres install. The migration fails with a plain message where the extension can't be created, and SQLite keeps the lock-only path for tests and trials. Book anyway stays for people and never for rooms, chairs or equipment.
9. **Resources:** extend Rooms into "Rooms and equipment" (kind, group, branch, any free one from a group), or keep rooms and add a separate equipment list? **Recommended:** one list. It is one rule ("one booking at a time") and one screen, and quantity is several rows in a group.
10. **The visit record:** **Recommended:** notes and forms per visit, behind `calendar:visit_notes`, which is granted only by an admin; signed off on Finish visit, then corrections only, never edits; never shown to customers; no prescriptions or diagnosis codes; described as "stored in the EU". Is that the right depth for dentists and clinics, or should clinical records be left out entirely for now?
11. **Treatment plans:** **Recommended:** both **Book the next visit** (at the end of each visit) and **Book all** (up front), with plan templates per service.
12. **Waitlist offers:** offer everyone who matches at once with first-taker-wins, or one person at a time with a 15-minute hold each? **Recommended:** everyone at once, first-taker-wins. It fills the slot fastest, and a hold per person leaves slots empty on short notice.
13. **Deposits:** **Recommended:** kept on a late cancel or no-show; refunded on an early cancel or when we cancel, but each refund is one click by a person with `calendar:manage`, never automatic; applied to the invoice when they came. The deposit amount and policy settings are core and free. Taking the money needs Whity Pay (a paid gateway).
14. **Screens without cards:** build the desk, reports, waitlist, visit record and plans as core screens from the existing calendar and Pw cards now, and send the brand team a request for cards? **Recommended:** yes, so the work isn't blocked. The cards replace them when they arrive.
15. **Reminders by default:** **Recommended:** 24 hours and 2 hours before (the version 3.1 value), by email always and by WhatsApp or SMS when connected, with I'm coming and I can't come links.
16. **Branch time zones:** **Recommended:** yes, an optional time zone on each branch that the calendar uses for hours, slots, reports and end of day. Global and hosted in the EU (WS.17) means a business may have branches in two zones.

## 14. Not in scope

- Group classes with several customers per slot.
- Insurance claims, prescriptions, diagnosis codes and medical device integrations.
- Two-way sync (paid, Google and Microsoft plugins).
- A second task engine (T.1–T.4).
- Plan names and prices (`[plan name]`, `[price]`).
- Agents booking on their own: they still propose, and a person accepts.
