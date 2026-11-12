# Whity emails (EM.1)

Fifteen emails, each in English (`.en.html`) and Arabic (`.ar.html`), ready to fill. Tables and inline styles, 600 px wide, tested shapes for Gmail, Outlook and Apple Mail; dark mode through `prefers-color-scheme`.

| Group | Email | From | Can be turned off |
| --- | --- | --- | --- |
| Account | `invitation`, `verify-email`, `reset-password`, `new-sign-in`, `two-step-off` | the workspace, via Whity | No (security) |
| Money | `invoice`, `quote`, `payment-reminder`, `receipt` | the workspace, to its customers; replies go to the workspace | The workspace chooses |
| Agents | `needs-approval`, `daily-summary` | the workspace, via Whity | Yes, Preferences › Notifications |
| Workspace and billing | `workspace-ready`, `plan-limit`, `payment-failed`, `whity-receipt` | `workspace-ready` from the workspace; the other three from Whity itself (Whity's own fees) | No |

## Rules

- **White-label:** the workspace's logo (or its initial on its colour), name and colour on everything it sends. The line "Sent with Whity" (class `wl`) is removed on plans with white-label; the sending domain is the workspace's own when set up (Settings › Email domains).
- **Readable:** body 17 px, line height 1.6, one main button 52 px tall, never more than one main button.
- **Only open the app for decisions** (N9): approval emails never approve from the email; they open the workspace.
- **Plain words, no blame:** reminders assume the customer may have paid; payment-failed says everything keeps working and how long they have.
- **Security emails** (`new-sign-in`, `two-step-off`, `reset-password`, `verify-email`) can't be turned off and never contain a password.
- **Money:** amounts in normal units with the currency code, inside `dir="ltr"` so they read correctly in Arabic. Invoices and receipts attach the Whity Render PDF.
- **Arabic:** `dir="rtl"`, Noto Sans Arabic then Tahoma; the same layout mirrored; codes and amounts stay left to right.

## Placeholders

`{{tenant_name}}`, `{{tenant_initial}}`, `{{tenant_color}}`, `{{tenant_address}}`, `{{url}}` (the button's link), and per email: `{{inviter}}`, `{{role}}`, `{{email}}`, `{{expires}}`, `{{code}}`, `{{device}}`, `{{place}}`, `{{when}}`, `{{inv}}`, `{{total}}`, `{{due}}`, `{{customer}}`, `{{quote}}`, `{{qtotal}}`, `{{valid}}`, `{{what}}`, `{{method}}`, `{{agent}}`, `{{owner}}`, `{{ptitle}}`, `{{pdetail}}`, `{{more}}`, `{{done}}`, `{{waiting}}`, `{{fee}}`, `{{plan_fee}}`, `{{wl_fee}}`, `{{reason}}`, `{{month}}`. The daily summary's list of what agents did is a repeated row.

Design cards: `EmailAccount`, `EmailMoney`, `EmailAgents`, `EmailWorkspace`, `EmailArabic`, `EmailDark`.
