# Page patterns and people

Version 2.5 sets the patterns every remaining screen uses (decisions P.1–P.4, V.1–V.4), then applies them to the People screens and the Inbox. Cards: "Patterns" and "Templates · People and inbox".

## The sidebar

The sidebar follows the built app's groups, in sentence case: (top) Dashboard, Inbox, Approvals, My submissions; People; AI; Documents; Workspace; and Platform for system-tenant operators. Only the current group is open; the others fold to their label with a chevron (`AppShell` nav groups take `collapsed`). Labels are translated like every other string.

## List pages

Page header with the one main action (Invite people, New role) and a ⋯ for the rest; tabs when the page has views; a filter bar (search, filter buttons that tint and name their value when set, a segmented status switch); then the table. Selecting rows brings the bulk bar. Every row opens something (P.1): a full **record page** for things with history and settings (people, roles, workspaces), a **drawer** for small things (a group, a unit, a delegation, a relative).

People appear as names with avatars, never as ids ("Role #3", "OU #5", a Tenant ID column). Units, roles and dates are words.

## Record pages

A header with the icon or avatar, the name, a status badge, a line of key facts, and actions (outline, plus ⋯). Then tabs: **Overview**, **Access**, **History** (P.2). History is written in words ("Amro changed his unit from Sweifieh to Abdoun"), never event codes.

Fields are edited where they are (P.3). A changed field gets the primary ring and a "Was …" note; a sticky save bar names the changes and offers Discard and **Save changes**, the page's one main button. Leaving with unsaved changes asks first.

## States

Drawn once, used everywhere (V.2): loading (skeleton rows in the real layout), empty for the first time (what the place is for and the action that fills it), empty after a filter (names the filter, Clear filters), error (in the failed area, with Try again), read-only (an alert naming the permission and who grants it; fields disabled, actions hidden) and access denied (what's behind it, the permission, who can give it, Ask for access).

## Confirming

By how undoable an action is (P.4): undoable actions happen at once with **Undo**; hard-to-undo ones ask in a dialog that names the consequence; security-sensitive or irreversible ones also need the name or email typed. The button repeats the verb. Two-step and password-reset approvals, sign-up rejections and user deactivation, unconfirmed in the built app, now follow these tiers.

## The People screens

| Screen | Pattern | Notes |
| --- | --- | --- |
| Inbox (A02) | List | Tabs per source; Waiting for me or Including done; states the app composes and translates |
| Users (A10) | List + record | Invite people; filters for role, unit, two-step; bulk change role; the record has Overview, Access, History and the save bar |
| Roles (A12) | List + record | Built-in roles marked and read-only; the Access tab is a grid of areas by See, Create, Change, Delete, Approve |
| User groups (A11) | List + drawer | Groups are rules; the drawer shows who is in it right now |
| Organisational units (A13) | Tree + drawer | Tree or chart; actions only with `ous:write` |
| Delegations (A14) | List | From → to in names, what, where, until |
| Family relations (A15) | List + drawer | Relatives without accounts; search across everyone |
| Approval gating (A16, A48) | Tabs + rows | Request dates; tier-3 confirmation for resets |

The 2.5 Arabic card is the user record (`TplUserRecordAr`).
