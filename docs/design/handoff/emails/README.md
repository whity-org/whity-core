# Whity emails (EM.1)

Twenty-five emails, each in English (`.en.html`) and Arabic (`.ar.html`), ready to fill. Tables and inline styles, 600 px wide, tested shapes for Gmail, Outlook and Apple Mail; dark mode through `prefers-color-scheme`.

| Group | Email | From | Can be turned off |
| --- | --- | --- | --- |
| Account | `invitation`, `verify-email`, `reset-password`, `new-sign-in`, `two-step-off` | the workspace, via Whity | No (security) |
| Money | `invoice`, `quote`, `payment-reminder`, `receipt` | the workspace, to its customers; replies go to the workspace | The workspace chooses |
| Agents | `needs-approval`, `daily-summary` | the workspace, via Whity | Yes, Preferences › Notifications |
| Workspace and billing | `workspace-ready`, `plan-limit`, `payment-failed`, `whity-receipt` | `workspace-ready` from the workspace; the other three from Whity itself (Whity's own fees) | No |
| Bookings (3.4) | `booking-confirmed`, `booking-reminder`, `booking-moved`, `booking-cancelled` | the workspace, to its customer; confirmed and moved carry an `.ics` file | The reminder only (Settings › Bookings) |
| Forms and signing (3.4) | `form-received`, `form-decision`, `sign-request` | the workspace; the decision carries the printout PDF with its QR code (FP) | No |
| Joining (3.4) | `join-approved`, `join-declined` | the workspace, to someone who asked to join | No |
| Messages (3.4) | `message-reply` | the workspace; the reply-to address threads the answer back into the same conversation in Messages | No |

## Rules

- **White-label:** the workspace's logo (or its initial on its colour), name and colour on everything it sends. The line "Sent with Whity" (class `wl`) is removed on plans with white-label; the sending domain is the workspace's own when set up (Settings › Email domains).
- **Readable:** body 17 px, line height 1.6, one main button 52 px tall, never more than one main button.
- **Only open the app for decisions** (N9): approval emails never approve from the email; they open the workspace.
- **Plain words, no blame:** reminders assume the customer may have paid; payment-failed says everything keeps working and how long they have.
- **Security emails** (`new-sign-in`, `two-step-off`, `reset-password`, `verify-email`) can't be turned off and never contain a password.
- **Money:** amounts in normal units with the currency code, inside `dir="ltr"` so they read correctly in Arabic. Invoices and receipts attach the Whity Render PDF.
- **Arabic:** `dir="rtl"`, Noto Sans Arabic then Tahoma; the same layout mirrored; codes and amounts stay left to right.

## Placeholders

`{{tenant_name}}`, `{{tenant_initial}}`, `{{tenant_color}}`, `{{tenant_address}}`, `{{url}}` (the button's link), and per email: `{{inviter}}`, `{{role}}`, `{{email}}`, `{{expires}}`, `{{code}}`, `{{device}}`, `{{place}}`, `{{when}}`, `{{inv}}`, `{{total}}`, `{{due}}`, `{{customer}}`, `{{quote}}`, `{{qtotal}}`, `{{valid}}`, `{{what}}`, `{{method}}`, `{{agent}}`, `{{owner}}`, `{{ptitle}}`, `{{pdetail}}`, `{{more}}`, `{{done}}`, `{{waiting}}`, `{{fee}}`, `{{plan_fee}}`, `{{wl_fee}}`, `{{reason}}`, `{{month}}`, `{{service}}`, `{{date_time}}`, `{{new_time}}`, `{{place_name}}`, `{{staff}}`, `{{refund}}`, `{{form_name}}`, `{{ref}}`, `{{next}}`, `{{decision}}`, `{{conditions}}`, `{{sender}}`, `{{signer_doc}}`, `{{reply_text}}`, `{{subject_line}}`. The daily summary's list of what agents did is a repeated row.

- **Calendar file:** `booking-confirmed` and `booking-moved` attach `booking.ics` (METHOD:REQUEST, the same UID for the booking's life, so a move updates the same calendar entry); `booking-cancelled` sends METHOD:CANCEL with that UID.
- **Replies:** emails from the workspace use a reply-to that lands in Messages (`message-reply` threads by conversation).

Design cards: `EmailAccount`, `EmailMoney`, `EmailAgents`, `EmailWorkspace`, `EmailBookings`, `EmailForms`, `EmailJoining`, `EmailArabic`, `EmailDark`. The cards follow the Design System's theme: in dark mode the emails show their own dark-mode styles.
