# Whity mobile

The Whity app for phones and tablets: a **do-on-the-go companion** to the web app (M.1), built once in Flutter with one Whity look and each platform's own behaviour (M.3). It is designed for one hand and for people of every age. Decisions M.1–M.47 (with CH.3 renaming the Agents tab to Chat); cards in the "Mobile" group. The work and operations areas add their own phone cards (section 27).

## Principles

- **One thumb.** Everything a person does often sits in the lower half: the tab bar, the main button in a bottom bar (M.4), search (M.7), sheets, steppers. Titles stay at the top, where they're read, not reached.
- **Large and plain.** 17px text, never below 14px; 48 × 48 targets at least; the app follows the phone's text size up to the largest accessibility sizes (M.5).
- **Decide with the facts in front of you.** Approvals happen in the app, after Face ID where needed, never from a notification (M.44).
- **Motion means something.** Every animation explains an interaction: loose and dashed is "not yet", set down is "done", heavy is "this matters", stuck is "this needs you" (M.19–M.25).
- **Wears the installation's brand.** The app takes each installation's tokens when it connects, so every Whity installation gets a mobile app for free (M.13–M.17).

## Sizes

| What | Value |
| --- | --- |
| Drawn at | 390 × 844 (iPhone 15); works from 360 wide |
| Body text | 17px, minimum 14px; titles 26–30px; amounts in Geist Mono |
| Targets | 48 × 48 minimum; rows 56px (settings) and 64px (lists); main buttons 52px; steppers 72px; PIN keys 80px |
| Corners | Buttons 13px, cards 18–22px, sheets 28px at the top |
| Tab bar | 64px plus the home indicator; centre Create button 56px |
| Text size | Follows the phone, from default to the largest accessibility size; rows stack instead of truncating |

## The web app on a phone

The web app under 640px wide uses the same tab bar, rows, sheets and bottom bars as this app (PW.1–PW.11), so a person moving between them sees one product. It installs as the workspace’s own app and asks for notifications once (PW.11). Cards: `PwNav`, `PwLists`, `PwLibrary`, `PwEditor`, `PwCalendar`, `PwSettings`, `PwBuilders`, `PwInstall`.

## Navigation

A **bottom tab bar** (M.2, CH.3): **Home**, **Inbox**, the centre **Create** button, **Chat** (people and agents together; it was Agents), **More**. Pushed pages keep the tab bar; full-screen tasks (a decision, a form, the camera) hide it and show a bottom action bar instead. Holding Home switches workspace (M.17).

| Tab | What it holds | Cards |
| --- | --- | --- |
| Home | Waiting for you, one decision at a time by default or as a list (M.10); today's numbers | `TmHome`, `TmDecisionVariants` |
| Inbox | Waiting for me, Mentions, Updates; search and filters at the bottom (M.43) | `TmInbox` |
| Create | Ask an agent, then six tiles chosen by role and editable (M.11) | `TmCreateAgents`, `TmCreateFlows` |
| Chat | People and agents in one chat: direct chats and groups (CH.1–CH.3), and Agents today | `TmChat`, `TmCreateAgents`, `TmSearchMobile` |
| More | Your card, workspaces, My work, Manage (admins), You, Help (M.41) | `TmMore` and the pages below |

## Screens

| ID | Screen | Cards |
| --- | --- | --- |
| MB01 | Story, three Lottie screens | `TmOnboardStory` |
| MB02 | Create a Whity ID: details, email code, your picture, protect your account, workspace | `TmOnboardAccount`, `TmOnboardPicture` |
| MB03 | Preferences with real UI in motion; guided tour | `TmOnboardPrefs` |
| MB04 | Connect an installation and take its theme; switch workspace | `TmConnect` |
| MB05 | Tenant creator setup; a customer joining in one tap; approving a sign-in by number | `TmCreatorCustomer` |
| MB06 | Unlock, app PIN, a new phone, a lost phone | `TmSignBack` |
| MB10 | Home, a decision full screen | `TmHome` |
| MB11 | Edit before approving, decline with a reason, tier 3 after Face ID, decided by me | `TmDecisionVariants` |
| MB12 | Inbox | `TmInbox` |
| MB13 | Create sheet; scan a receipt; ask by voice; a quick note | `TmCreateAgents`, `TmCreateFlows` |
| MB14 | Chat with people and agents, agents today, an agent asking first | `TmChat`, `TmCreateAgents`, `TmSearchMobile` |
| MB15 | Search everything | `TmSearchMobile` |
| MB16 | Push notifications and in-app banners | `TmPush` |
| MB20 | More, workspaces, profile, help and about | `TmMore` |
| MB21 | Preferences: display, swipes, notifications, which hand | `TmPreferences`, `TmHandAccess` |
| MB22 | Sign-in and security: status, devices, sign-ins, app lock, password, authenticator, recovery codes, passkeys | `TmSecurity`, `TmSecuritySetup` |
| MB30 | People: list, a person, asking to join, inviting | `TmPeople` |
| MB31 | Managing agents: list, an agent, pausing, activity | `TmAgentsManage` |
| MB32 | Documents: to sign, reading, routing, signing | `TmDocumentsMobile` |
| MB33 | Forms and my requests; filling; check and send; stock count | `TmFormsMobile` |
| MB34 | Billing; activity and security; workspace settings; sending to the web | `TmWorkspaceMobile` |
| MB40 | States: offline, installation not answering, updating, all caught up, session ended, update needed, no access, error | `TmStatesMobile`, `TmStatesMore` |
| MB41 | Motion and resistance | `TmMotion` |
| MB50 | The customer app: home, paying, tracking, account | `TmCustomerApp` |
| MB60 | Arabic | `TmArabicMobile` |
| MB61 | Tablets | `TmTablet` |
| MB62 | Hands and accessibility | `TmHandAccess` |

## What the phone does, and what goes to the web

On-the-go essentials (M.40): decide (approve, decline, edit first), sign documents, fill forms, count stock, scan receipts, talk to agents, let people in, invite, pause or remove people, pause agents, see activity and billing, and manage your own account and security. Deep setup — roles and permissions, groups and units, route templates, the document designer, the form builder, branding, plugins, the audit log in full — opens on the web. "Open on the web" sends a one-time link by email that works for 15 minutes; the sign-in is approved on the phone (M.32).

## Gestures and motion

- **Swipes are the person's choice** (M.6): left and right are each chosen (mark read, archive, snooze, delete); every swipe has Undo.
- **Swipe to approve** is off by default and only ever approves tier-1 and tier-2 items (M.9). Its direction follows the hand setting (M.46).
- **Heavy destructive swipes** (M.20, M.24): the row moves at a third of the thumb's speed, red grows, a firm haptic at 70% marks the point of no return; let go early and it springs back.
- **Tier 3 is stuck** (M.21): the card moves 10px at most, shudders twice with two short buzzes, then the verification sheet asks for Face ID.
- **Approving sets the sheet down** onto its plane and turns it solid (the `approve-set-down` Lottie for one card, LT.4; 200 ms in code when approving several); the next card slides up (the story's motion, M.23).
- **Timing:** ease-out, 200–400 ms, no bounce on anything destructive. **Reduce motion** (M.25): fades; resistance stays as haptics and colour; Lottie shows its last frame.

## Security

- **Whity ID**, one account for every installation (M.26), created with email and password, Apple or Google (M.27).
- **This phone is a passkey** and approves sign-ins elsewhere by **number matching**, then Face ID (M.32, M.33).
- **App lock** (M.8): Face ID or fingerprint after 5 minutes by default, a 6-digit app PIN as fallback; five wrong PINs sign the phone out.
- **Tier 3 always asks for Face ID** and can't be swiped or turned off; verification lasts 2 minutes.
- **Sign-in and security** shows a status checklist (strong password, passkey, recovery codes, authenticator; M.42), devices with sign-out, recent sign-ins, recovery codes (Face ID to show), passkeys, and an authenticator set up on the same phone by link or key.

## Theming and identity

- On connecting (email first, or QR or address; M.14) the app pulls the installation's full tokens: colours, type, corners, logo, name, light and dark (M.15). The 17px and 48px minimums always hold; a colour pair that fails contrast falls back to Whity's.
- Free: the Whity app, branded inside. Paid: an installation's own store app built from the same code (M.16). Switching workspace re-themes the whole app (M.17).
- **Pictures** (M.34–M.38): a photo, or a seeded pattern built from the mark's own pieces in the colours of the installation where the Whity ID was made (`Avatar pattern hue`, `W.avatarPattern`, `W.brandHue`). People only: agents keep the robot.

## Notifications

They **only open the app** (M.44). Each names its workspace; amounts are hidden on the lock screen by default; several from one agent stack. Press and hold shows the text and **Open in Whity**. Opening asks for Face ID and lands on that decision. Inside the app, a new decision arrives as a 4-second banner at the top with **View**. Sign-in approvals come first, are never grouped, and can't be turned off. Quiet hours (22:00–07:00 by default) let sign-in approvals through.

## Offline and states

Offline, tier-1 and tier-2 decisions queue and send when the connection returns; signing and tier 3 wait. Every state says what happened, whether anything was lost, and one next step: offline, an installation not answering, updating, all caught up, session ended, update needed, no access (with "Ask for access"), and errors with a reference code.

## Arabic

Fully mirrored (M.45): tab order, arrows, lists, sheets and swipe directions. Noto Sans Arabic at the same sizes. Western digits; amounts, codes and invoice numbers stay left to right in mono. "Whity" is always in Latin letters. **The main button follows the hand, not the reading direction**: with the right hand (default) Approve stays on the right in Arabic too.

## Hands and accessibility

- **Which hand** (M.46), right by default: moves the main button in two-button bars and steppers, and the swipe-to-approve direction. The tab bar and Create stay centred.
- **Screen readers** read a decision card as one sentence (who, for whom, what, how much in words, how long), then its buttons; agents are always introduced as agents.
- Colour is never the only signal; motion follows Reduce motion; every target is 48px or more.

## Tablets

Two panes from 744 points wide (M.47): the list (400 points) beside its detail, a side rail instead of the tab bar (on the right in Arabic), sheets as centred dialogs or popovers, actions at the bottom right of the detail. Narrower windows get the phone layout.

## The customer app

A tenant's customers (M.29, M.31) join from a link or QR in one tap and see only the tenant's product, in the tenant's brand, with the tenant plugin's tabs (here Home, Orders, New order, Messages, Account), payments (CliQ, card, cash on delivery), order tracking and a small account page with "Built on Whity". No story, tour, Manage or agent approvals.

## For the build

The cards draw the screens with web components and `.wm-*` classes in `bundle.css`; they are the visual and behavioural reference, not the Flutter code. Flutter takes its tokens from the Flutter brand extension in `handoff/` (section 12), themed at runtime from the installation's published tokens (M.13, M.15). Lottie files follow the keyframe notes in `TmOnboardStory` and `TmMotion`. Icons are the same 137 Tabler names.
