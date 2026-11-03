# Plugin store

Where a workspace adds apps, document blocks and agent tools. It lives inside each workspace first: one sidebar item, Plugins, opens a page with two tabs, Store (`/admin/plugins/store`) and Installed (`/admin/plugins`) (A41, S.10); a public marketplace site will reuse the same pages later (S.1). Decisions S.1–S.12; cards in the "Templates · Plugin store" group, shown on the pages after this section.

## The store

| Part | What it holds |
| --- | --- |
| Search and categories | One search box (names, publishers, what a plugin adds) and one category switch: All, Finance, Sales, People, Operations, Documents, Agents |
| Made by Whity | First-party apps and services (Front Office, Whity Render, Whity ID) as wide cards with a blueprint strip |
| Sections | Most used by workspaces like yours, New, Updated, Tools for your agents; each with See all |
| Plugin tile | The publisher's logo, the name, the publisher and its trust mark, one line, what it adds, how many workspaces use it, the price |

**Names:** third-party plugins are named for their job, as "[Job] for Whity" (Inventory for Whity) or with a plain name (Delivery Tracker). Names that start with "Whity" are kept for first-party products.

**Trust (S.4):** By Whity (first party), Verified (the publisher is checked, and the plugin is a signed declaration drawn with Whity components) or Not verified (its code runs on the server once it's on, and the page says so plainly). Alongside: the permissions it asks for, "Used by N workspaces" and the last update. No ratings or reviews until there are enough installs for them to mean something.

**What it adds:** small chips on every tile: Screens, Document blocks, Agent tools (on the primary tint, like everything agents do), Fields, Connects out. They answer "what changes in my workspace" before anyone opens the page.


## A plugin's page

The header carries the logo, the name, the publisher, the trust mark and usage, one line, what it adds, and **Install**, the one action on the plane, with the price and "billed on your Whity invoice" (S.2). Tabs: Overview, Permissions, Changelog, Support.

The Overview shows screenshots drawn with real Whity components, a short description and **What it adds** in three boxes: Screens, Document blocks (each marked Prints or Notes only, E.18) and Agent tools (dashed), with the mode each tool starts in. The **Facts** panel beside it lists the publisher, how it runs, version and update date, languages, usage, billing and support; anything sent outside the workspace is named in a warning box with its domain.


## Installing

Anyone whose role has `plugins:install` can install (S.3, S.9); `plugins:read` is enough to browse. Uploading a plugin's .zip, unreviewed code, is a separate operator permission, `plugins:upload`. Without it, Install is disabled with "Ask someone with permission to install plugins" and an **Ask to install** request. Agents can't install directly: with Ask first, an install becomes a proposal like any other action (7.5).

One dialog, with a Stepper, in up to four steps; steps that don't apply are left out:

1. **Access:** plain sentences for each permission, with the codes underneath; the screens, blocks and agent tools it adds; every outside domain in a warning row. "Allow and continue" is the consent, logged with the person and the list.
2. **Plan** (paid plugins): monthly or yearly, billed on the Whity invoice. A publisher may offer a free trial of a set length; the dialog says when billing starts and that a reminder comes before it (S.8).
3. **Set up** (if the plugin declares settings): Whity form components; "Skip for now" leaves a "Finish setting up" banner on the plugin's screens.
4. **Done:** where each added thing lives, with links. Verified and By Whity plugins are active from here; a Not verified plugin installs turned off, and Done offers **Turn on** (S.12).

Every agent tool a plugin adds starts on **Ask first**; read-only tools may start Allowed. Admins change modes afterwards in MCP tools (S.5).


## Installed plugins

The Installed tab lists every plugin with its state, version and billing. States: Active, Trial, Update waiting, Failed, Turned off. The row menu holds Open, Settings, Details, Agent tools, Turn off or on, and Uninstall.

**Operator tools (S.11).** People with `plugins:upload` get a ⋯ menu on the Installed tab with Upload plugin and Reload plugins. Store sources and their access tokens live in Settings › Advanced, not on the browsing page. A failed plugin's Details drawer explains the error in plain words, with the stack trace under Technical details, collapsed.

- **Updates (S.6):** a Verified declarative plugin's updates install automatically and say so in the Version column; a code plugin only updates by hand. An update that asks for new permissions or new outside domains waits for approval: a warning banner names what's new, "Review access" opens the Access step with only the changes, and the old version keeps running meanwhile. Every update is in the audit log.
- **Failed:** the plugin didn't load; its blocks show the plugin-off placeholder (E.20); the row menu offers Try again and Roll back.
- **Uninstalling (S.7)** keeps the plugin's data for 30 days under "Recently removed", with Reinstall, which restores everything. Its document blocks show the placeholder meanwhile; after 30 days the data is deleted.


## Still to draw

The publisher's page, search results with filters, the request-to-install flow for people without permission, the public marketplace site, and Arabic versions of the store cards.
