# Workspace, billing and settings

Version 2.9: the Settings area, a person's account, billing, the audit log and licensed devices, and the operators' Platform pages (decisions W.1–W.4). Cards: "Templates · Settings", "Templates · Account and billing", "Templates · Platform". With it, every screen in the built app has a design.

## Settings

One Settings entry in the sidebar opens a page with a grouped **sub-nav** beside it (W.1): Workspace (General, Branding), Sign-in and security (Security, Single sign-on, Sign-up), Email (Email domains), Data (Tags, Translations), Advanced (Error tracking), and Devices (Licensed devices). Each page keeps its own address and permission check; pages a person can't open aren't listed.

| Page | What it holds |
| --- | --- |
| General | Workspace name, support email, time zone and language (saying when a value follows the platform default); company details with the tax number tax invoices need |
| Security | Two-step rules in sentences, with deadlines; who hasn't set it up, with Remind |
| Single sign-on | A card per provider: how it appears, which domains, On or Off, the address to give it with Copy |
| Email domains | A card per domain: the DNS record with Copy, the role new people get, Check now |
| Tags | Tag groups with their tags and where they're used, on one page |
| Translations | Coverage per language, then wording with what's missing first and Add Arabic |
| Licensed devices | Serial numbers, names, status; Issue a code shown once |

## Your account

From the person's name at the foot of the sidebar, in tabs (W.3): **Profile**, **Sign-in and security** (password, two-step, recovery codes, each a visible button), **Sessions and devices**, and **Preferences**: language, theme, **text size** (Standard 15px or Larger 17px) and **Show technical details**.

## Billing

The plan and what's billed with it, the next payment with **Pay now**, and Whity's invoices to the workspace. Amounts are JOD with 3 decimals; plan names and prices stay `[plan name]` and `[price]` in designs.

## Audit log

What happened, in sentences grouped by day (W.4): people in bold, agents as actor chips "for" their person, a muted line with how, and Open for anything still waiting. Filters for who, agent, area and date; Export. Codes, ids and IP addresses appear only with Show technical details.

## Platform (operators)

A **Platform** sidebar group, shown only to the system tenant (W.2): Tenants (workspaces, with server-side search), Plans and pricing (plans as cards; prices typed as money, never minor units), Promotions (in JOD by default), System (the former statistics page, refreshing every minute), Errors (in plain words first; the stack trace on request), Notification delivery (whether mail and messages get through), and Platform settings (install-wide switches in sentences, with Email, File storage, Languages and Error tracking tabs).

The 2.9 Arabic card is billing (`TplBillingAr`).
