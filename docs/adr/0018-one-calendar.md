# ADR 0018: One calendar for everything with a time

- **Status:** Accepted
- **Date:** 2026-11-16
- **Task / Issue:** WHIT-1108 (this ADR), WHIT-1109 to WHIT-1115; issue #1933 (WHIT-1051)
- **Deciders:** Owner ("calendar, meetings, etc need to merge under one solution"; merge map approved 2026-11-16, "Approve all, as recommended"), Whity-Core maintainers

## Context

Whity grew several systems that put something at a time:

- **Bookings** (`calendar_bookings`, ADR 0017): a customer, a service, a price, a payment hold, a manage link, public pages and emails with an `.ics`.
- **Team events** (`calendar_events`): one owner, no guests.
- **Time off and extra hours** (`calendar_availability_exceptions`).
- **Meetings** (Convening, migrations 130 and 134): a body, a number, an agenda, decisions and attendance. A meeting has a start but no end, and guests get an in-app notice without an `.ics`.
- **Task due dates**, kept in Taskaat (T.3).

As a result, meetings did not block bookings (#1933). Meeting guests got no calendar invitation. There were two free-time pickers, two reminder paths and two invite paths.

## Decision

1. **`calendar_events` is the one events table.** A `kind` says what an event is:
   - `event`: a team event, or an everyday meeting once it has people;
   - `meeting`: a formal meeting of a body;
   - `time_off`;
   - `external`: synced from an outside calendar.

   `calendar_event_people` holds who is invited, their role and their answer (invited, accepted, declined, tentative). The organiser is `calendar_events.profile_id` and is never listed among the people. An event may hold a room (`room_id`) and say where in words (`location`).
2. **Bookings stay in `calendar_bookings`.** Their customer, payment and manage-link contract is customer-facing, and the salon's live rows and links must not move. They join the calendar through a read model, not through a copy.
3. **One read model, one list.** `CalendarItems` behind `GET /api/calendar/items` returns bookings and events with one shape:
   - `id` (`"booking:7"`, `"event:12"`), `kind`, title, times, `busy`, `status`;
   - `organiser_id`, `people`, `room_id`, `location`;
   - per kind, `booking` or `event`;
   - `can` {open, move, resize, cancel}.

   It is a PHP read model rather than a SQL view, because the schema is built for SQLite and Postgres alike and no migration defines a view.
4. **One free/busy.** A person is busy for:
   - their live bookings;
   - busy events they organise;
   - busy events they are invited to and haven't declined.

   A room is busy for its bookings and for busy events held in it. The calendar shows exactly what blocks a booking.
5. **Who sees what** (owner):
   - colleagues see the titles of what they can open;
   - someone else's time off reads "Away" and their synced events "Busy", without notes or place.
6. **Formal meetings keep their record.** Body, number, agenda, decisions (routed and signed), attendance and quorum stay in Convening. A meeting's date, place and guests live on its event (`calendar_events.meeting_id`). Meetings with only a start last 60 minutes by default (owner).
7. **Time off is an event** of kind `time_off`, recorded and not approved (owner). Extra working hours become kind `open_hours`: not busy, never shown on the calendar, and only widening the person's hours. Days off and extra hours then share one table and one id space, so the time-off API (`/api/calendar/time-off`) answers as before. `calendar_availability_exceptions` is no longer read and is dropped in T8.
8. **One invite and reminder path.** Every event with people sends:
   - an in-app notice;
   - an email with an `.ics` (one UID for the event's life, a SEQUENCE, CANCEL when it is cancelled);
   - a push.

   One job sends reminders for bookings and events. Customer booking emails keep their own templates and share the `.ics` builder.
9. **Task due dates are read, never stored.** They come from Taskaat as all-day `task_due` items: never busy, and off in Also show by default. Invoice and form dates stay off the calendar for now (owner).
10. **Also show** (CV.2) gains Meetings (on) and Tasks due (off).

## Delivery

| Step | Task | What | Migration |
| --- | --- | --- | --- |
| T1 | WHIT-1108 | This ADR | - |
| T2 | WHIT-1109 | Kinds, event people, rooms and places on events, `/api/calendar/items`, free/busy for people invited and rooms held, answering an invitation | 284 |
| T3 | WHIT-1110 | Time off and extra hours become events (copy keyed by `legacy_ref`) | 285 |
| T4 | WHIT-1112 | Meetings scheduled as events; `MeetingBusySource` retires | 286 |
| T5 | WHIT-1113 | One invite path and one reminder job | - |
| T6 | WHIT-1114 | Task due dates and SDK `CalendarSource` | - |
| T7 | WHIT-1115 | Meetings inside the calendar on the web, redirects from `/admin/x/convening-*`, one free-time picker | - |
| T8 | (later) | Drop `meeting_invitations`, `meetings.scheduled_at/location` and closed exceptions, one release after their copies | ask |

## Consequences

- Copies are idempotent: `legacy_ref` is unique per workspace, and meetings are copied only while `event_id` is empty. Old rows stay for one release before they are dropped (migrate first, drop later).
- `GET /api/calendar/events` and the booking endpoints keep working. `/api/meetings` stays for the SDK and MCP, and scheduling a meeting goes through events.
- Periods (`time_windows`), Taskaat tasks and a formal meeting's record are deliberately not merged. They are not time on someone's calendar, or they belong to another engine.
