# Answers to engineering's asks after 3.3 (design system 3.4)

Your list of 13 November, answered in the order the owner chose: the web app on a phone first, then the screens with no card, then the decisions. Dark and Arabic variants (part 3) follow next. Every card is in English and Arabic, light and dark; the decisions are in `00-decision-register.md`.

## 1. The web app on a phone (WHIT-1006): PW.1–PW.10

| Ask | Answer | Card |
| --- | --- | --- |
| Navigation (PWA) | **The tab bar on phones, in the browser and installed** (owner): Home, Inbox, Create, Chat, More, counts on Inbox and Chat. More lists the sidebar in its groups; Create opens M.11's sheet; the tab bar hides while the keyboard is open. 640–767px keeps the drawer, 768–1023px the rail | `PwNav` |
| Lists | Stacked rows; search with Filter and the main button at the bottom; Sort by the title; Select turns the tab bar into the bulk bar; Clear all / Choose all; filters as chips; nothing found | `PwLists` |
| Document library | A Folder button opens the tree as a sheet (unit filter on top); collections as chips; list or grid; search results split by title and text | `PwLibrary` |
| Editor toolbar | Full-screen, no tab bar. Back, title, Publish; then the mode and save state. Toolbar docks at the bottom and rides on the keyboard; More tools and the publish check are sheets | `PwEditor` |
| Calendar | Week as a list of days, month as counts with the day under it, sub-pages from the title | `PwCalendar` |
| Settings menu | The menu is its own page; the page you came from is marked Last opened; deep pages show the path in small text | `PwSettings` |
| Builders | **Read-only is enough** for route templates and the permissions grid, with Open on a computer | `PwBuilders` |
| Sidebar on short screens | The workspace, Settings and the account row stay put; only links scroll; the account menu opens upward (WHIT-827) | `PwShortSidebar` |
| Messages on a phone | One at a time: list, then the conversation | `PwMessages` |

New icons: `folder`, `folder-open`, `grid`, `star`, `keyboard`, `checkbox`, `square`.

## 2. Screens with no card: SC.1–SC.18

`TplMessagesInbox`, `TplChatBox`, `TplSettingsMessages`, `TplApprovalsDecided` (Decided and Edit), `TplImpactPlan`, `TplAgentCredential`, `TplWhityAgent`, `TplSavedBlocks`, `TplWelcomeWorkspace` (create, waiting, need an invitation), `TplSettingsSignup` (with team invites), `TplJoinWorkspace`, `TplSettingsPublicForms`, `TplPlanLimit`, `TplDocThemes` (WHIT-785), `TplIssueDocument`, `TplSignInTwoColumn`, `TplWhityIdMore`. Routes are in `13-app-map.md`.

The four briefs named in your list (limit warning, issue a document, two-column sign-in, Whity ID) weren't in the repository; those cards follow your notes, the built code and the register. Send the briefs if they differ.

**The earlier cards you asked about:**
- `TplAgents`, `TplAgentRecord`, `TplAgentPresets`, `TplAgentInstructions` cover the agents list, an agent's page and the gallery. The persona editor (WHIT-821) is now `TplAgentPersona` (SU.4).
- `TplAppointments` covers the day view. The calendar on a computer (WHIT-946) is now `TplCalendarStaff` (SU.3): week by time, the booking sheet, the 409, hours with several ranges, time off, services, rooms and settings. On a phone, `PwCalendar`.
- `Preferences` and `TplAccountPrefs` are the account page; the user menu is `PwShortSidebar`.
- `TplPlatformSettings` is the operator's switches; the Settings sub-nav is the left column of every `TplSettings*` card, now with all of D1.

## 4. Decisions

- **Hosted in the EU (Q34.1).** It is: the owner confirms app.whity.dev runs on EU servers and database, with files and backups on Cloudflare in the EU. Put the claims back as WS.18 says (footer badge to Security, "stored in the EU"). No GDPR claim; don't name the server provider.
- **Saved blocks, per-document parts (E.23a).** Stable keys, never positions. Changes in `handoff/editor/whity-document.ts`: `PerDocumentKey`; `perDocument` mark carries `key`; `FieldRef.perDocument` is the key (was a boolean); `SavedBlock.perDocumentParts: PerDocumentPart[]` (label in English and Arabic, `required`, placeholder) replaces `perDocumentKeys`, which stays one release for old rows; `SavedBlockRef.overrides` is keyed by part key; `SavedBlockVersionChange` reports new and orphaned parts. Migration: position `i` → `perDocumentParts[i].key`, once.
- **Privacy (Q34.2).** Still with the lawyer. Don't publish the page with placeholders showing.
- **Store revamp (Q34.3).** Section 19 is the store; nothing else is coming. Align to it.

## 5. Form printouts: FP.1–FP.12

A form's answers printed as a record that doubles as the approval certificate (asked for an ethics committee; built as a generic surface for every client). Cards in "Documents · Form printouts": `DocFormPrintout`, `DocFormPrintoutMore`, `DocFormLooks`, `DocFormBlocks`, `TplFormPrintStyle`. Engine needs, the theme options and the block list: `handoff/print/FORM-PRINTOUTS.md`; types: `whity-document.ts` (`letterhead.layout`, `form?: FormPrintoutStyle`).

- Paid plans and free trials customise every part; free workspaces print the standard layout (letterhead and title, the decision line, B headings in their colour). When a trial ends, new printouts go back to the standard layout and printouts already made never change.

## 6. Every surface: SU.1–SU.10

A sweep of the 117 routes and everything sent or shown outside the app. New cards: `TplVerifyDocument` (`verify/[token]`), `TplPaymentReturn` (`billing/return`), `TplCalendarStaff`, `TplAgentPersona`, `MsgCustomerMessages` (WhatsApp and SMS), `SiteShareCards` (Open Graph), `SiteInstallerTerminal` (`install.sh` output), `TplBookingManage` (`booking/[token]`, WHIT-945), `PwInstall` (PW.11: installing the web app and notifications), and ten more emails, 25 in all, in English and Arabic: `EmailBookings`, `EmailForms`, `EmailJoining` (`handoff/emails/`). Bookings attach an `.ics` with one UID for the booking's life. Every row of `13-app-map.md` now names a card; the "none yet" asks (WHIT-826, 906, 915, 939, 945, 946) point to theirs.

## 7. Dark and Arabic

Every card now follows the theme: the Arabic cards set `dir` and `lang` after load so the theme applies; the email cards show the emails' own dark-mode styles; printed pages stay white on a dark ground.

## Coming next

The Front Office screens in Arabic after the owner's native read, and a client's own form printout (crest and quality marks) once the owner sends the files; it lives in that client's workspace, not the shared system.
