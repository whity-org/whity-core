# Home

The page everyone lands on after signing in (`/dashboard`, the sidebar's Dashboard). One home, built per person (H.1): each card appears only if the person can see what it shows. This is an owner's.

- **Waiting for you** leads (H.2): Inbox documents and agent proposals in one list, oldest first. Agent proposals are dashed with the robot tile and the agent "for you"; documents show who sent them. Decisions sit inline as outline buttons (Approve, Decline or Reject; Open when the item needs reading), so the page has no filled main button. Approving shows the Undo toast like the Approvals page. The footer links to Inbox and Approvals with their counts. At most 6 rows; then "and N more".
- **Agents today:** the day's agent activity, newest first, with a summary line (done, waiting, undone). Opens Agent activity.
- **Finish setting up** (owners and admins, until done): the steps that block real work, each with why it matters, such as the tax number invoices need. It disappears when complete.
- **From your apps** (H.4): each installed app may add one card, in install order. The card names its app; its ⋯ menu has Hide from home and Open. Hidden cards are listed at the foot of the page with Show. People can't rearrange cards in this version.
- **Operators** (the system tenant) see the platform cards from the admin dashboard below Waiting for you. Server statistics move to Platform › System (H.3).

Built from `AppShell`, `PageHeader`, `Card`, `Badge`, `ActorChip`, `AgentActivity`, `Button`, `Menu`, `BarChart`.
