# 37 · The forms flow: how a submission travels, arrives and is decided

**Status: phase 1 (WHIT-1372), waiting for the owner's answers (section 9). Nothing here is built.** The proposed register rows are FF.0–FF.16 (section 8). They go into `00-decision-register.md` only once the owner has answered.

The owner, 22 November 2026: "We need to fully enhance the UI/UX of the forms flow/how people receive in their inbox and act".

What happened on app.whity.dev that day (AAU IRB, route template 4):

- The owner sent an application. Kamel Saleh, as IRB coordinator, approved step 1.
- Step 2 went to the owner only, because the route leaves out anyone named in the application, and Kamel was named as the applicant account.
- The owner sent it back to the author, then asked why Kamel couldn't see it in his inbox. Nothing on screen had explained it.
- Another application had a missing PDF, fixed by #2434. #2437 adds a message and Try again.

This section walks the whole flow as it is today, lists what goes wrong, and proposes how it should work. It builds on section 21 (patterns), 22 (forms), 23 (documents and routing), the FP printout rules, PW (the web app on a phone) and EM.1 (emails). The cards `TplInbox`, `TplMySubmissions`, `TplDocRouting`, `TmInbox` and `PwLists` already draw parts of the answer. Much of this section is "build what the cards say", plus what the cards never covered.

**Design rule 1 (the owner, 22 November 2026): "inbox should be the action room... everything happens from thier".** Every action on a submission or document happens inside the Inbox. Every other page is a read-only view or a deep link back into the Inbox item. Section 4.0 sets it out; it is register row FF.0.

## 1. How it was walked

**Stack.** A private local stack (compose project `wffux`, images `develop-9dac8eb`, the same commit as this branch) restored from the 22 November backup of app.whity.dev. AAU IRB's form 5 and route template 4 were used exactly as they are live. Before anything started:

- mail was set to log only and SMTP removed;
- push subscriptions and the event outbox were cleared;
- the real members' review roles were removed;
- no real application was opened.

**People.** Fictional, from the Whity team (WS.4):

| Person | Holds | Language |
| --- | --- | --- |
| Ibrahim Mansour | applicant, plain member | English (Arabic for section 2.10) |
| Awss Haddad | IRB coordinator, Faculty research ethics committee, Ethics committee | English |
| Ahmad Khalil | Faculty research ethics committee, Faculty dean | Arabic |
| Emad Nasser | Ethics committee, IRB secretary | English |

**One local change.** Step 4 (IRB committee review) was set to "everyone must approve", so a quorum step could be walked. Live, every step is "the first to answer decides".

**Three runs:**

- **A, the full route.** Submit, approve steps 1–4 (step 3 by a colleague covering the dean through "I'm away"), send back from step 5, resubmit, approve. Certificate, decision email, verify page.
- **B, the conflict of interest.** The coordinator names the dean as project supervisor at step 1, so the dean is left out of steps 2–5. Step 3 then has nobody.
- **C, the PDF fails.** The renderer is stopped before the last approval.

Screens were taken at 1440×900 and at 390×844 (phone, PW), in English and Arabic. They are in `screenshots/forms-flow/` and named below by number. Example: `10` is `screenshots/forms-flow/10-inbox-coordinator-desktop.png`.

## 2. Each moment as it is now

### 2.1 Filling in and sending (`01`–`05`)

- **The fill page works well.** The form's banner, "Step n of 10" with All sections, the draft coming back ("You started this earlier…"), a Review step with Change per section, and the phone tab bar (`02`).
- The main button says **Submit application**, not what the form calls it, and not "Send application" (section 22).
- **The sent page (`05`) says only** "Submitted · … was recorded. It appears under your own submissions." It doesn't say who reviews it, how many stages there are, or what happens next.
- The receipt email says "Your … Application Form **is with Whity**" and "Next: It goes for review". It names Whity, not AAU IRB (BM.6).
- **People questions are invisible to members.** A member's fill page drops every `profile_ref` question: "Applicant's AAU staff account" and the staff column of "Other researchers". This is the public-form safety rule (`PublicFormView::answerableFields`). So on the members' path, `exclude_named_in` can only act on people a reviewer names later. Live, the applicant account was filled on the staff path.

### 2.2 My submissions (`06`–`08`, `38`–`45`, `62`–`64`)

- **The list can't tell submissions apart.** Columns are Form, Sent, Where it is, Reference, Actions. Three applications show as three identical "Human Research Ethics Application Form" rows (`63`). The card `TplMySubmissions` asks for a one-line summary (the project title).
- **The action is hidden.** "Edit and send again" is only inside the row's ⋯ (A.2 says common actions are visible) and at the foot of the dialog.
- **The progress is at the bottom of the dialog.** Opening a row shows a dialog with all 150 answers first. "Where it is" (stages and their state) and "Notes for you" come only after them (`43`).
- **Several states are wrong:**
  - Run B: the route stopped at a stage with nobody in it, yet the list says **Finished**, with reference `IRB-AAU 6//2026-2027`, which is missing its meeting number (`62`). See P1.
  - Run C: the list says **Finished** while the certificate is still waiting (`63`). The dialog says IRB decision **Approved** when the decision was Conditionally approved, and doesn't show the conditions (`64`).

### 2.3 The Inbox (`10`, `13`, `14`, `23`, `27`, `30`, `37`, `50`, `72`)

- **It opens on the wrong tab.** The Inbox opens on "Agents waiting in chats". There, a coordinator with an application waiting reads **"Nothing is waiting for you"** under a "Waiting for me 1" tab. The application sits in a second-row tab, "Documents awaiting you" (`10`). This alone explains "he couldn't see it in his inbox".
- **There are two rows of tabs.** The first is Waiting for me / Mentions / Updates; the second is the sources. Then comes a segmented Waiting for me / Including done. At 390px the second row scrolls sideways and cuts "Agents waiting in chats" in half (`14`).
- **A row says too little.** Columns are Item (form name, "Documents awaiting you"), From, State, Arrived (`13`). Nothing says:
  - which application it is (no project title, applicant or reference);
  - which stage, or why this person;
  - what is expected ("decide", "recommend", "fill in the meeting number");
  - by when. There are no due dates at all.
- **"State" is always "Your decision".** It says so for a reviewer, for the applicant whose application was sent back (`37`), and for a resubmitted one (`50`). The card's states ("Returned to you", "To review"…) aren't used.
- **"From" is wrong for a delegate.** For the colleague covering the dean, From shows the dean (`30`). For the applicant's sent-back item it shows "—" (`37`).
- **Rows don't open on click.** Clicking a row doesn't open it; only the title link does.
- **Arabic.** The page is translated, but the form's name stays English and the role under the name reads "user +2 أخرى" (`23`, `27`).

### 2.4 Opening a submission (`15`–`21`, `25`, `51`)

- **The page is one long column.** The route page `/admin/routes/form_submission/{id}` is a header, "Discuss in chat", then **every answer, including the ~80 hidden by conditions, as "No answer"**, then "Your decision", then "What happened" (`16`). On run A the decision panel starts about 10,000px down. A reviewer must scroll past the whole application to act, and loses it once they do.
- **Dates are raw.** They show as `2027-01-15` here and as "15 Jan 2027" on the review page (`15`, `07`).
- **A table answer disappears.** Run A's "3.9b Time and details for each selected activity" shows "No answer" on the route page, though the applicant's own view shows the row.
- **The applicant gets a dead end.** Their sent-back Inbox item opens this page, which tells them **"This could not be found. It does not exist, or it was never sent to you."** (`41`).
- **Arabic.** The labels stay English (the form has no Arabic yet), and the phone number renders as `962790000000+` (`25`).

### 2.5 Acting (`19`–`22`, `26`, `34`–`36`, `52`)

- **Two filled buttons.** Approve and Reject are both filled, Reject in red (`19`).
- **Disabled actions explain themselves in system words.** Forward and Return to sender sit beside them, disabled, each with a paragraph: "Step 1 is a decision step: it is answered with a verdict, and where the document goes next follows from that verdict…". The same paragraph is printed twice under step 2 onward.
- **More system words.** "Reason (optional, appended to the trail beside your verdict)". "This step names that rule itself."
- **Send back is separate.** "Send back for changes" is a separate outline button below the others (`20`).
- **The person picker repeats people.** "Project supervisor" lists everyone once per role membership: Ahmad Khalil three times, Awss Haddad four times (`19`, `21`).
- **A value is shown as an id.** Run B's supervisor shows as "15" in "Entered during review" (entered through the API there; check the picker's path too).
- **Approve has no Undo.** It acts at once, with no Undo and no "what happens next" line. The card's Undo for Reject and Return isn't built.
- **After approving, the page moves on.** The page reloads into the *next* step's decision when the same person holds it (step 1 → step 2 for Awss), with no "you approved step 1" line.
- **A choice can contradict the verdict.** Choosing "Do not recommend" (step 2/3) or "Revisions required / Not approved" (step 5) still goes through Approve. This was found on 16 November and is still open.
- **Quorum (`34`).** Before acting, the page says "This step was put to 2 people at once. Every one of them must approve…". After the first approval the page says "Nothing on this route is awaiting you", with no "1 of 2 approved, waiting for Emad Nasser".
- **The send-back dialog is good (`35`).** It has "What needs to change", "The person who sent it reads this", and **Back to this stage / Start again from the first stage**. After sending, though, the reviewer's page says only "Nothing on this route is awaiting you". It never says "Waiting for Ibrahim Mansour to change it; it comes back to IRB decision".

### 2.6 Notes and comments

- **A note says what kind it is, not who it's for.** A note appears in the trail as "Note added · Awss Haddad" or "Note for the sender · Emad Nasser". Nobody can tell who it was meant for, whether the applicant sees it, or whether it needs an answer. The composer is a plain "Note (optional, appended to the trail)" under the panel.
- **There is no comment on a single answer.** Reviewers can't point at "3.6 Recruitment" and say what to change. Their only tool is a send-back message that quotes it in prose.

### 2.7 Delegation and "on behalf of" (`28`–`33`, `54`)

- **"I'm away" can be set**, from Delegations (`28`, `29`).
- **The covering colleague can see the item but not act on it.** The dean's item reaches the colleague's Inbox, with From = the dean (`30`). Opening it says **"Nothing on this route is awaiting you"** (`31`). Acting only works through the API.
- **The covering colleague isn't told.** No notification reaches them when the dean's item arrives.
- **"On behalf of" is lost everywhere after that.**
  - The trail says "Approved · Awss Haddad" (`33`).
  - "Entered during review" credits Awss with the dean's recommendation.
  - **The certificate prints "Awss Haddad, Faculty dean"** (`54`). That is an official record naming the wrong person in the dean's role.

### 2.8 The conflict of interest (run B, `60`–`62`)

- **The record exists but nobody sees it.** The left-out people are recorded in `document_route_steps.left_out` and only the printout's "Not counted" line reads them (FP.6). No screen, Inbox, trail or notification says anything.
- **The dean, left out, sees nothing.** The application doesn't appear, and opening its link says "This could not be found" (`60`).
- **The coordinator approves without knowing.** Naming the supervisor at step 1 empties step 3 (the dean is the only Faculty dean), and nothing warns them.
- **P1: an empty step finishes the route as approved.** This is the serious one. The router announces `route.completed` whenever nothing is open on the route (`announceIfCompleted`, `DocumentRouter.php:3155`). So a step that exclusions leave empty ends the route. On run B:
  - the applicant received **"Approved: … IRB-AAU 6//2026-2027"** by email;
  - a reference was issued without its meeting number;
  - My submissions says Finished;
  - no certificate was made, and steps 3–5 never ran.

  On AAU IRB, two or three people hold every review role, so naming one of them can empty a step today.

### 2.9 The certificate, PDF, email and verify page (`53`–`57`, `63`–`66`)

- **The certificate and printout are clean (`54`, `55`).** The approvals list one row per approver.
- **The printout's facts are off:**
  - the decision block's Title is the form's name, not the project's;
  - "Page 7 of 7" appears on the 8th page (the certificate isn't counted);
  - the SHA-256 is printed on the record. On screen it shows in the trail without Show technical details (A.3).
- **Run C, while the PDF waits:**
  - the route page says "Final document waiting · Whity · PDFs can't be made right now. Whity will try again." (`65`);
  - the applicant sees "Finished" and "Final document waiting" together (`63`, `64`);
  - the document's library page says **"This document could not be loaded"** (`66`).
- **Sending also needs the renderer.** If the renderer is down when someone presses Send, **the applicant is refused**: "This route ends with a final document, but PDFs can't be made on this install. Remove the final document step, or ask your administrator to set up the render service." That message is for an admin, shown to an applicant.
- **The decision email** has the subject "Approved: …" and the line "**Whity decided** on your Human Research Ethics Application Form". The PDF is attached and there is a link to My submissions. It isn't in AAU IRB's name (BM.6). A conditional approval still says "Approved:" (16 November, still open).
- **The verify page (`56`, `57`)** has the workspace's name and plain words. "Issued on 2026-11-22" is a raw date, and "Reference" shows the QR code's short code, not IRB-AAU 5/7/2026-2027.

### 2.10 Notifications

- **One sentence for every moment.** Every moment produces the same in-app notification: "… is waiting for you · … has been routed to you and is waiting in your inbox". That includes an application **sent back to its author**, and a resubmitted one.
- **English only, even for Ahmad (Arabic).**
- **There are no emails to reviewers at all.** `documents.routing_notification_channels` is unset. The design's `needs-approval` email is unused.
- **Nothing is sent** for a send-back (no listener on `route.sent_back`), for a covering delegate, or to anyone left out.

## 3. The problems, in order

| # | Problem | Seen | Severity |
| --- | --- | --- | --- |
| P1 | An empty step (everyone left out) finishes the route as approved: reference, "Approved" email, Finished | run B | **Wrong outcome.** Fix first, apart from the redesign |
| P2 | Inbox opens on another source and says "Nothing is waiting for you" while an application waits | `10` | People miss their work |
| P3 | Left-out people are told nothing, and neither is anyone else | `60`, live | The owner's question |
| P4 | Where a sent-back application waits isn't said to reviewers; the applicant's own item opens "could not be found" | `41`, after `35` | Dead end |
| P5 | Acting on behalf works in the Inbox but not on the page; "on behalf of" is dropped from trail, values and certificate | `31`, `54` | **Wrong record** |
| P6 | The decision panel is below every answer; reviewers scroll ~10,000px and lose the application while deciding | `16` | Slow, error-prone |
| P7 | The Inbox row has no "which one", no stage, no "why me", nothing expected, no due date | `13` | The owner's point |
| P8 | Two filled buttons, disabled buttons with system-word paragraphs, no Undo, no "what happens next" | `19` | Register (one main button, P.4, A.3) |
| P9 | Notes don't say who they are for; no comment on one answer | 2.6 | Unclear ownership |
| P10 | My submissions can't tell applications apart, hides Edit and send again in ⋯, and shows wrong states (Finished while waiting, Approved for Conditionally approved) | `63`, `64` | Applicant confusion |
| P11 | A choice that contradicts the verdict (Not recommended + Approve) is accepted | 2.5 | Wrong outcome |
| P12 | Notifications: one English sentence for everything, no reviewer email, nothing on send-back | 2.10 | People miss their work |
| P13 | A failed PDF: "Finished" to the applicant, library page fails to load; renderer down blocks sending | `63`–`66` | Partly fixed by #2437 |
| P14 | Workspace name: emails say "Whity decided" / "is with Whity" | 2.1, 2.9 | BM.6 |
| P15 | Duplicate people in the picker; raw dates; ids for people; SHA-256 without technical details; repeating rows lost on resubmit and on the route page | various | Polish, but some lose data |
| P16 | Members can't answer people questions, so conflict rules only see people named by reviewers | 2.1 | Gap in the rule |

## 4. The proposed experience

### 4.0 Design rule 1: the Inbox is the action room (FF.0)

The owner, 22 November 2026: "inbox should be the action room... everything happens from thier".

Every rule below follows from this one.

**Everything a person does on a piece of work happens inside the Inbox.** That covers:
- approve, recommend, reject, send back;
- fill in a stage's values;
- comment on an answer, write a note;
- forward, return;
- delegate or hand it to someone covering;
- sign and stamp (#1726, #1729);
- answering a send-back (the applicant).

**Opening an item shows everything in the Inbox, with no jump to another page.** The opened item has:
- the form's answers, or the document (the PDF);
- the route timeline;
- the actions.

Its address is the Inbox's own (`/admin/inbox/{source}/{itemId}`), so it can be shared, bookmarked and reached from a notification.

**The next item follows.** After acting, the next waiting item opens in the same place. A line says what you just did, with Undo while it lasts, and offers "Back to the list". When nothing is left, the empty state says so and names what you finished.

**Other pages become read-only views or deep links:**
- the route page `/admin/routes/{type}/{id}`;
- the document's Routing tab (D.3);
- the submission in Responses (F.4);
- the applicant's My submissions;
- the home's "Waiting for you" (H.2).

They show the timeline and the content, but no decision buttons. Where the person has an open item, they show one button, **Open in Inbox**, which lands on that item. A page never says "Nothing is awaiting you" when something is: if the item belongs to someone they cover, the button says **Open in Inbox (covering for Ahmad Khalil)**.

**Approvals (agent proposals) follow the same rule.** They already merge into the home (H.2). In the Inbox they are items like any other, decided in place. Whether the Approvals page stays as a separate filter is question 12.

**Rule-of-thumb test for every screen in this section:** can the person finish the job without leaving the Inbox? If not, the design is wrong.

### 4.1 The Inbox card (FF.1–FF.3)

The Inbox opens on **Waiting for me**: every source merged, oldest due first, as the home's "Waiting for you" already does (H.2). Sources become a filter chip, not a second row of tabs. An empty source tab is never the landing view.

Each row, at desktop, is a card-row with five things:

```
[avatar Ibrahim Mansour]  Sleep habits and exam performance among first-year students        Due Thu 26 Nov
                          Human Research Ethics Application · IRB-AAU 5/…  · Stage 2 of 5: Faculty research ethics committee
                          Why you: Faculty research ethics committee member · Any one of 2 decides
                          [ Review ]                                                         Arrived 22 Nov, 21:47
```

1. **Who:** the applicant, with avatar (people as names, A.3). For "on behalf of": "For Ahmad Khalil (away) · from Ibrahim Mansour".
2. **What:** the submission's own title (a question the form marks as its title; AAU's 1.1), then the form's name and reference.
3. **Why me:** the role or rule that reached them, and the stage's rule in words ("Any one of 2 decides", "All 2 must approve, 1 has").
4. **Due:** a due date per stage, from the route template (FF.4). Overdue turns the date to the warning colour and adds "2 days late". No due date set: nothing shown.
5. **The one action:** an outline **Review**, which opens the item in the Inbox (4.2). The list has no filled button, like the home. The whole row opens it too. The state word says what is expected, chosen from:
   - Decide
   - Recommend
   - Fill in and decide
   - Changes asked (for the applicant)
   - Came back with changes
   - For your information
   - Covering for …

On a phone (PW.4), the same row stacks as title, applicant and due, then the stage line and the state chip; Review is the row's tap.

### 4.2 The open item, inside the Inbox (FF.5–FF.7)

The opened item lives in the Inbox, beside the list. There is no separate review page.

| Width | Layout |
| --- | --- |
| 1280px and up | three columns: the list (narrow, about 320px, the current row ringed); the item; the sticky action panel |
| 1024–1279px | the list folds to a slim rail of avatars and due dates; the item and the action panel |
| 640–1023px | the item full width with the action panel as a bottom bar and sheet; ‹ Inbox returns to the list |
| under 640px | the phone layout (4.11) |

The item has three tabs at the top: **Answers** (or **Document** for a routed document), **Timeline** and **Files**.
- Timeline is the same component as 4.4.
- On the Answers tab, the stage's current position on the timeline also shows as one line under the sticky header ("Stage 2 of 5 · with you · next: Faculty dean").

Keyboard (for the over-50 desk workers who live in it):
- J and K move through the list;
- Enter opens an item;
- A approves (with its Undo);
- S sends back;
- C comments on the focused answer;
- Esc closes the item.

All of these are listed under "?" and never required.

Inside the item:

- **The application.** Sections fold. Questions hidden by conditions are left out (not "No answer"), with a "Show empty and hidden answers" switch. Dates and tables are written as the applicant saw them. A sticky header carries the project title, applicant, reference and stage. Changed answers since the reviewer last saw it are marked "Changed" with "Was …", and a "Show changes only" switch (the data exists: `subject_versions`).
- **The action panel, sticky beside the item:**
  - what is asked of you ("Faculty research ethics committee · Any one of 2 decides · due Thu");
  - the stage's values (recommendation, meeting number…);
  - a note box with **who reads it** (FF.9);
  - then **one filled button** named for the act ("Approve", "Recommend approval", "Approve with conditions"), with outline **Send back for changes** and a quiet **Reject** beside it;
  - one sentence on what happens next: "It goes to the Faculty dean (Ahmad Khalil)."
- **Rare actions in ⋯.** Forward and Return to sender move into ⋯ and appear only when the step allows them. No disabled buttons with paragraphs.
- **Hand it on, from the panel (FF.16).** "Hand to someone" (in ⋯) covers both cases: give this one item to a colleague who holds the stage's role, or set "I'm away" from here for every item. Whoever covers sees it in their own Inbox as "Covering for …" and acts on it there.
- **Sign and stamp.** When a stage asks for a signature, the signing pad and the fresh second factor (C1 of #1718) open as a sheet over the item. They never open on another page.
- **Choice and verdict match (FF.7).** A stage choice that contradicts the verdict changes the button: choosing "Do not recommend" turns the main button into "Send my recommendation: do not approve". Choosing "Not approved" at the IRB decision turns it into **Reject**. Choosing "Revisions required" turns it into **Send back for changes**.
- **Confirming by tier (P.4).** Approve and recommend are tier 1: done at once, with Undo for 10 seconds and the next-step line. Reject is tier 2 and names the consequence ("This ends the application. Ibrahim Mansour is told, with your reason."). After acting, the next waiting item opens (4.0), under a line naming what you did, with Undo.
- **Phone (PW.3).** The item fills the screen. The action panel is a bottom bar ("Decide · due Thu") that opens a sheet. The main button rides on the keyboard. After acting, the next item slides in.

### 4.3 Send back, and where it goes next (FF.8)

- **The dialog stays as built.** Keep its reason, plus Back to this stage / Start again from the first stage.
- **It can point at answers.** Add "Point at answers": tick questions that need changing. They reach the applicant as comments on those questions (4.5).
- **Afterwards, everyone on the route sees one line in the timeline:** "Sent back to Ibrahim Mansour for changes · comes back to IRB decision (Emad Nasser)". The reviewer's panel says "Waiting for Ibrahim Mansour since 22 Nov", not "Nothing is awaiting you".
- **The applicant answers in the Inbox too.** Their row says "Changes asked · IRB decision". It opens in the Inbox, showing the reason, the questions pointed at, and their timeline. The applicant never reaches the reviewers' view.
  - Their main button is **Edit and send again**. It turns the item into the form in edit mode, in place: their earlier answers are all kept (P15: table rows were lost), and the form opens on the first question pointed at.
  - Sending closes the item. (Whether a long form is edited inside the Inbox or on the full fill page is question 13.)
- **The return is marked.** Back with the reviewer, the row says "Came back with changes", and the item opens on "Show changes only".

### 4.4 One route timeline for everyone (FF.10)

One timeline component, used in the Inbox item's Timeline tab, in the read-only views (My submissions, the document's Routing tab, D.3, `TplDocRouting`, and Responses). Stages run top to bottom. Each stage shows:

- its name and rule;
- who it reached;
- its state: done (check, name, time), current (main colour, "with Ahmad Khalil · due Thu"), next (grey), skipped, or "nobody to ask" (warning);
- notes and values under the stage that made them;
- **left-out people** (FF.11).

What each person sees:

| | Reviewers and admins | The applicant |
| --- | --- | --- |
| Stages, state, dates | yes | yes |
| Who decided | yes | the workspace's choice (`forms.show_reviewer_names`, A9) |
| Who was left out and why | yes | no; only "Some members were left out to avoid a conflict of interest" when it applies |
| Values and notes | all | only those marked for the applicant |
| On behalf of | "Awss Haddad for Ahmad Khalil (away)" | "Faculty dean" (or the names, as above) |

**The applicant's view.** In My submissions, the applicant's submission is a **read-only view** (`/my/submissions/{id}`), not a dialog over a list.
- The timeline and the state come first, then the certificate, then the answers, folded.
- When something waits for the applicant, the view's one button is **Open in Inbox**. The work itself happens there (4.0).

### 4.5 Comments on single answers (FF.12)

Reviewers can leave a comment on any answer, from the item's Answers tab in the Inbox (a "Comment" link by each answer):

- **Internal** comments are seen by the route's reviewers.
- **For the applicant** comments are released only when the application is sent back, so the applicant gets one coherent list, not a drip.
- Comments carry the author and stage and are answered in place by the applicant on resubmission ("Done", or a reply).

### 4.6 Notes with an owner (FF.9)

Every note says **who reads it** before it is written. The note box has a choice:

- **Reviewers only** (the default);
- **The applicant too**: marked, shown in the applicant's timeline, and only allowed for people the route reached, as today.

In the timeline a note reads "Emad Nasser · note for Ibrahim Mansour" or "Awss Haddad · note for reviewers". A note never stands on its own without the stage it was written at.

### 4.7 The conflict-of-interest explanation (FF.11)

When a stage leaves someone out:

- **The timeline names them at that stage:** "Left out: Ahmad Khalil, named as project supervisor (entered at Pre-check)". Reviewers and admins see this; the applicant doesn't (FP.6's "Not counted" line keeps the same rule on paper).
- **The left-out person** gets an Inbox line "For your information: you were left out of Faculty dean for this application, because you are named as project supervisor". It has no action. A link opens the timeline, so they are never left wondering. (Owner question 3: some committees may want this silent.)
- **The person whose entry causes it** is warned before acting. If naming the supervisor at Pre-check would leave a later stage with nobody, the panel says so before Approve: "Ahmad Khalil is the only Faculty dean. Naming him leaves that stage with nobody. Choose who covers it, or ask an admin." See FF.13.
- **An empty stage never finishes the route.** It stops with "Nobody to ask at Faculty dean". The workspace's admins and the route's owner get an Inbox item to choose someone for this application (one-time cover) or end it. P1's bug fix is the floor; this is the experience.

### 4.8 Due dates and reminders (FF.4)

- **Due dates.** Each stage in the route template gets an optional "Expected within … working days" (AAU: 5). The due date shows in the Inbox, the panel and the timeline.
- **Reminders.** One the day before, one on the day, then a daily line in the person's 18:00 summary (EM.1) while late.
- **Escalation.** It isn't automatic: when late, the route's admins see "Late" on the Responses tab and can remind or reassign. Owner question 5.

### 4.9 Notifications and email only open Whity (FF.14)

Following EM.1 ("no actions inside approval emails") and M.44, emails and pushes carry no Approve button. Each says what, why you, and by when. It opens **the item in the Inbox** (`/admin/inbox/{source}/{itemId}`), never a separate page; for the applicant's decision it opens their read-only view. It is sent in the workspace's name and brand (BM.6) and in the person's language.

| Moment | Who | In-app | Email | Push (PWA) |
| --- | --- | --- | --- | --- |
| Waiting for you | the stage's people, or their cover | yes | yes, from `needs-approval`, one per stage | yes |
| Due tomorrow, late | whoever holds it | yes | in the daily summary | yes |
| Sent back for changes | applicant | yes, with the reason | yes (new `form-changes-asked`) | yes |
| Came back with changes | the stage's people | yes | yes | yes |
| Left out | the left-out person | for your information | no | no |
| Nobody to ask | admins, route owner | yes | yes | yes |
| Decided | applicant | yes | `form-decision`, in the workspace's name, the real decision word | yes |
| Certificate failed | admins (not the applicant) | yes | no | no |

### 4.10 The certificate, the failed PDF and the verify page

- **On behalf of in print.** The certificate and the approvals list print "Awss Haddad for Ahmad Khalil, Faculty dean" (FP.6 row), and the trail and values keep the same.
- **The applicant sees one honest state** while the certificate is made: "Approved with conditions · your certificate is being prepared; you'll get an email". Not "Finished". The library page shows #2437's message instead of "could not be loaded".
- **A renderer that is down never blocks sending.** The application goes in, and only the final stage waits (the admin message stays on admin screens).
- **The verify page** shows the decision, the workspace's reference and a written date, plus the title (owner question 8: today it proves only "AAU IRB issued a document on this date").

### 4.11 The phone layout (FF.15)

Under 640px (PW.1–PW.4):

- **Inbox.** Stacked card-rows (4.1), Waiting for me first, sources as a filter sheet.
- **An opened item.** It fills the screen, with Answers, Timeline and Files as chips at the top. A bottom bar shows "Decide · due Thu" and opens the action sheet. Comments open as a sheet on the answer. After acting, the next item slides in; ‹ Inbox goes back.
- **The applicant's "Changes asked".** Also an Inbox item: the reason and the timeline first, then "Edit and send again" as the bottom bar's main button.
- **Route templates.** Read-only on a phone (PW.9).

## 5. What already exists to build on

- **The engine.** `RouteQuorum`, `RouteResume` (back to this stage or start over), `subject_versions` (what changed), `RouteStepInputs`, `RouteLeftOut::record`, `AwayService` (cover), `SubmissionProgress` (the applicant's stages, notes for you), `RouteFinaliser` with retry.
- **Cards to follow and extend.**
  - `TplInbox`: states as words, rows open their item.
  - `TplMySubmissions`: one-line summary, "Returned to you".
  - `TplDocRouting`: stages with people and rule, one main button, Undo.
  - `TmInbox`, `PwLists`.
  - `handoff/emails/needs-approval.*` and `form-decision.*`.

## 6. Milestones (after the owner answers)

Each milestone is one WHIT-ticketed PR. Migration numbers and SDK minors come from the orchestrator first.

| M | What | Needs |
| --- | --- | --- |
| FF-0 | **P1 fix (WHIT-1373), separately and now:** an empty step stops the route ("nobody to ask") instead of completing it; no reference, email or Finished. Admin Inbox item. Tests with exclusions emptying a step | maybe a migration (step state) |
| FF-1 | Inbox list: open on Waiting for me (merged), sources as a filter, the card-row (who, what, why, state word), the whole row opens the item | API: title, stage, rule, role on `InboxItem.meta` |
| FF-2 | The action room: items open inside the Inbox at their own address; three columns, sticky action panel, one main button, ⋯ for rare acts, Undo, next item follows, keyboard; hidden answers folded, written dates, changes since your version. The route page, Routing tab and Responses become read-only with **Open in Inbox** | API: act by item (incl. covered items); one item endpoint for every source |
| FF-3 | Cover and on behalf of: act from the Inbox item, hand an item to a colleague from the panel, delegate notified, "for …" in trail, values, certificate and Inbox | printout row change (FP.6) |
| FF-4 | One timeline: stages, rule, who, left out, notes by audience; in the Inbox item and the read-only views | API: `left_out` names, on-behalf in presenter |
| FF-5 | The applicant in the Inbox (Changes asked, edit in place), the read-only view `/my/submissions/{id}`, My submissions with title, honest states (waiting for certificate, conditions) | `SubmissionProgress` states |
| FF-6 | Send back: point at answers, where it waits, "came back with changes", editing keeps every answer | none |
| FF-7 | Comments on single answers | migration |
| FF-8 | Due dates and reminders on stages | migration, scheduler |
| FF-9 | Notifications and emails per 4.9, in the workspace's name and language | `form-changes-asked` email (EM.1) |
| FF-10 | Conflict-of-interest warning before acting (FF.13), choice and verdict match (FF.7) | none |
| FF-11 | Phone layouts for all of the above | the brand cards |

## 7. Not in scope

- Building forms (section 22) and printouts (FP).
- Signing and stamping themselves (#1726, #1729); this section only says they open as a sheet in the Inbox item.
- Plugin route steps.
- Members answering people questions (P16) needs its own security answer (owner question 9) before any design.

## 8. Proposed register rows

To be added as a section "The forms flow (decided …)" once answered.

| # | Decision | Proposed |
| --- | --- | --- |
| FF.0 | The Inbox is the action room | **Owner, 22 Nov 2026: "inbox should be the action room... everything happens from thier".** Every action on a piece of work (decide, values, send back, comment, note, forward, return, hand on, sign, stamp, answer a send-back) happens in the opened Inbox item, at its own address; the next item follows. Route, document, response and My submissions pages are read-only views with **Open in Inbox**; notifications open the item |
| FF.1 | Inbox landing | Opens on Waiting for me: every source merged, oldest due first; sources are a filter, never a second row of tabs; an empty source is never the landing view |
| FF.2 | Inbox row | Applicant (avatar), the submission's title, form and reference, stage n of m, why you (role or rule, and the stage's rule in words), due, a state word for what is expected, an outline Review; the whole row opens it |
| FF.3 | State words | Decide, Recommend, Fill in and decide, Changes asked, Came back with changes, For your information, Covering for …; composed and translated by the app |
| FF.4 | Due dates | Optional "expected within N working days" per stage; reminders the day before and on the day, then in the daily summary; late shown, not escalated automatically |
| FF.5 | The open item | Inside the Inbox: list, item and action panel from 1280px, list as a rail from 1024px, item and bottom bar below; tabs Answers (or Document), Timeline, Files; sticky decision panel; hidden answers folded; answers written as the applicant saw them; changes since your version marked |
| FF.6 | The decision | One filled button named for the act; Send back outline; Reject quiet; Forward and Return in ⋯ only when allowed; Undo 10 s for approve; a sentence naming the next stage and its people |
| FF.7 | Choice and verdict | A stage choice that contradicts approval changes the main button to the matching act (reject, send back, or send a negative recommendation) |
| FF.8 | Send back | A reason, optionally pointing at answers; the route says who it waits for and where it comes back; the applicant's item opens in their Inbox and is edited in place |
| FF.9 | Notes | Each note says who reads it (reviewers only, or the applicant too) before it is written; shown under the stage that wrote it |
| FF.10 | Timeline | One component for reviewers, applicant and the document's Routing tab; what each sees follows section 4.4's table |
| FF.11 | Left out | Named at the stage with the reason, for reviewers and admins; the left-out person gets a for-your-information line; the applicant sees only that some members were left out |
| FF.12 | Comments on answers | Internal, or for the applicant released on send-back; answered in place on resubmission |
| FF.13 | Nobody to ask | A stage left with nobody never completes the route: it stops, admins and the route's owner choose a one-time cover or end it; the person whose entry would cause it is warned first |
| FF.14 | Notifications | Every moment in section 4.9; email and push only open Whity; the workspace's name and the person's language |
| FF.15 | Phone | Inbox card-rows, the application full-screen with a Decide bottom bar and sheet, the applicant's timeline first |
| FF.16 | On behalf of | Acting for an away person works from the Inbox item, and one item can be handed to a colleague from the panel; trail, values, Inbox and the certificate say "X for Y (away)" |

## 9. Questions for the owner

1. **P1 now.** May the empty-step fix (FF-0) go in at once as a bug fix, before the rest is designed? It changes no screens. *Recommended: yes.*
2. **Nobody to ask.** When exclusions empty a stage, who chooses the cover: (a) the workspace's admins, (b) the route template's owner, or (c) the previous stage's decider? *Recommended: a and b both see it; either may act.*
3. **Telling the left-out person.** Should a left-out reviewer get the "for your information" line (FF.11)? Some committees keep conflicts quiet. *Recommended: yes, with a per-route switch to keep it quiet.*
4. **What the applicant sees.** Should the applicant see stage names and dates (today: yes), reviewer names (today: the `forms.show_reviewer_names` setting, off), and "some members were left out" (new)? *Recommended: as today, plus the left-out line only when it applies.*
5. **Due dates.** Should stages carry due dates with reminders (FF.4), and is 5 working days right for AAU? Should late items ever escalate by themselves? *Recommended: due dates and reminders yes; no automatic escalation.*
6. **Comments on answers.** Build comments on single answers (FF.12) in this round, or after the rest? *Recommended: in this round, after the Inbox list and the open item (FF-7).*
7. **"Not recommended" at the faculty stages.** Does a negative recommendation at Faculty REC or Dean still move the application on (as advice to the IRB), or stop it? *Recommended: it moves on, marked "Not recommended", and the IRB sees it first.*
8. **The verify page.** Should it show the decision and the project title, or stay at "this workspace issued a document on this date"? *Recommended: decision, reference and the workspace's own title line; never the answers.*
9. **People questions on the members' path.** Today members can't answer "Applicant's staff account" or name co-researchers, so conflict rules can't see them. Allow people questions for signed-in members (members only, never public), listing only the workspace's members? *Recommended: yes, for members only.*
10. **Emails to reviewers.** Turn on reviewer emails (`needs-approval`) by default for every workspace, or per workspace? *Recommended: on by default, a person can turn them off.*
11. **The applicant's read-only view.** Move the applicant's submission from a dialog to its own read-only view (`/my/submissions/{id}`), with the work itself in their Inbox? *Recommended: yes.*

Questions raised by the action-room rule (FF.0):

12. **Approvals.** Agent proposals become Inbox items decided in place. Keep the Approvals sidebar item as a saved filter of the Inbox ("Proposals"), or remove it? *Recommended: keep it as a filter, so links and habits still work; it opens the Inbox.*
13. **Editing a long form in the Inbox.** The applicant's "Edit and send again" on a 150-question form: edit inside the Inbox item (the form in edit mode, sections as steps), or open the full fill page and come back? *Recommended: inside the Inbox item; the fill page stays only for a first submission.*
14. **Starting work.** Sending a new form, or putting a document on a route, starts outside the Inbox (Forms, the library). Is that fine, with the Inbox for everything that arrives? *Recommended: yes; Create (PW.2) stays the way to start.*
15. **Admins acting on others' items.** An admin who isn't on the stage (for example, to reassign a stuck item or choose a cover for "Nobody to ask") acts where? *Recommended: the admin also gets an Inbox item for it ("Nobody to ask", "Late"); the route's read-only view links there and never acts by itself.*
16. **The old route page.** `/admin/routes/{type}/{id}` becomes read-only. Should it redirect to the Inbox item when the visitor has one open there? *Recommended: show the read-only view with a clear Open in Inbox, never redirect silently, so a shared link always shows the same content.*
17. **Other sources.** Should leave, purchase approvals and plugin requests follow the same rule now, or only forms and documents in this round? *Recommended: the rule applies to every source; this round builds forms and documents, and the item frame is shared so plugins fill it later (an SDK minor from the orchestrator).*

## 10. Asks for the brand team

In `D:\Projects\Whity\brand-asks\brand-ask-forms-flow.md`: the Inbox card-row (desktop and phone), the open item inside the Inbox (list, item and action panel, the next item following), the timeline (with left out, nobody to ask, on behalf of, notes by audience), the applicant's "Changes asked" item and their read-only view, comments on answers, the "changes asked" email. English and Arabic, light and dark.
