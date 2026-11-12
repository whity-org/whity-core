# Home

The page everyone lands on after signing in: `/dashboard`, the sidebar's Dashboard. It replaces the placeholder welcome page and the Dashboard link to server statistics. Decisions H.1–H.4; cards in the "Templates · Home" group.

## One home, built per person

There is one home (H.1). Each card appears only when the person can see what it shows, so an owner, a member of staff and an operator get different pages from the same design. Nothing is a separate layout. System-tenant operators get the platform cards of the admin dashboard below Waiting for you.

| Part | Who sees it | What it holds |
| --- | --- | --- |
| Greeting | Everyone | "Good morning, Amro", the date and the workspace (and branch, if the person has one) |
| Waiting for you | Everyone | Inbox documents and agent proposals, merged, oldest first, with inline decisions (H.2) |
| Agents today | People with agents | The day's agent activity with a summary: done, waiting, undone |
| Finish setting up | Owners and admins, until done | The steps that block real work, each with why it matters |
| From your apps | Per app permission | One card per installed app (H.4) |
| Platform cards | System-tenant operators | Tenants, agent actions, plugin health, the tenant table |

## Waiting for you

It always comes first (H.2). Each row has an icon tile, a title with the amount in mono, who it's from (a person, or an agent "for you"), why and how long ago, and the decision. Agent proposals are dashed with the robot tile until a person decides, as everywhere. Decisions sit inline as outline buttons, Approve beside a ghost Decline or Reject, or Open when the item needs reading first; the home has no filled main button. Approving shows the Undo toast. At most six rows, then "and N more"; the footer links to Inbox and Approvals with their counts.

When nothing waits, the card collapses to one line ("Nothing is waiting for you."), so the page never opens on an empty box.

## Cards from apps

Each installed app may add one card (H.4), declared in its manifest. The card names its app with its logo, shows one number that matters, a few lines and at most one chart, and links into the app. Its ⋯ menu has Hide from home and Open. Hidden cards are listed at the foot of the page with Show. Cards follow install order and can't be rearranged in this version. A card shows only the person's scope: a branch manager's Stock card counts their branch.

## System

Server statistics leave the Dashboard link. `/admin/stats` becomes **System**, under Platform, for system-tenant operators only (H.3), and its numbers feed the operators' platform cards.
