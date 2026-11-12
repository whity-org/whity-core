# Work and operations

Sixteen areas of everyday work, designed for the web and the phone in full (X.1): documents, flows, agents, agent chats, meetings, inventory, tasks, boards, organisational units, roles and delegations, approvals, approval gating, forms, applications, money and expenses, subscriptions and tiers, appointments. Cards in "Templates · Work" (web) and "Mobile" (phone). Decisions X, T, MT, AP, IN, MO, SU, AA, CH, AQ, AG, FL, OR and DC.

## Sidebar

A **Work** group follows the top items: Tasks, Boards, Meetings, Appointments, Applications, Flows. **Operations** holds Inventory, Money and expenses, and Subscriptions. **Chat** sits among the top items, for people and agents together (CH.1). On the phone they're under More › My work, and anything waiting for a person also shows on Home and in the Inbox.

## Tasks, on Taskaat

Tasks integrate with **Taskaat** (taskaat.dev), which is the engine (T.3): projects, sections, tasks, checks, evidence and decisions live there, and Whity's pages read and write them with Whity's people, agents, permissions and look. "Open in Taskaat" opens the same task.

- **Agents finish their own tasks** within their scope (T.1); "Ask first" actions still become proposals in Approvals.
- **Done when** (T.4): an agent's task must say what done means before work starts; people may add one. The agent submits **evidence** item by item, and the task waits at the **gate** ("Awaiting your review") for a person to **Accept as done** or **Send back**. Closed without passing evidence, a task stays **Done, unverified**.
- **Decisions** record the reasons behind choices, for people and later agent sessions.
- Cards: `TplTasks`, `TmTasks`.

## Boards

A board is **a view of tasks** (T.2): a Taskaat project whose columns are statuses. Default columns To do, Doing, **Awaiting review** (the gate, amber), Done. Dragging lifts a card onto the plane; it can't skip the gate without being marked unverified. On the phone, one column at a time with a row of column pills, and **Move to** from a long press. Board settings work on the phone too. Cards: `TplBoard`, `TmBoards`.

## Meetings

Everyday meetings and **formal meeting bodies** (X.3): committees with a chair, secretary, members, schedule and quorum, and numbered meetings. During a formal meeting the phone shows the current agenda item with a timer, the recording state (everyone told), and tiles to add a note, a resolution with its vote, or an action item. Afterwards the **Minutes assistant drafts the minutes** from the notes and recording (MT.1); the secretary edits or approves, the chair signs, and the minutes are filed in Documents. Resolutions are numbered per meeting (14/1) with votes; action items become Taskaat tasks on approval. Cards: `TplMeetings`, `TplMeeting`, `TmMeetingsMobile`.

## Appointments

**Customers and staff** both book (AP.1). Staff see a day or week calendar with people and rooms as columns: booked, checked in, requests needing an OK, and blocked time. Customers book on the tenant's **booking page** or in the customer app: service, day, time, then a WhatsApp confirmation and reminders. Staff set their availability, breaks and services on the web or the phone. Cards: `TplAppointments`, `TplBookingPage`, `TmAppointmentsMobile`.

## Inventory

Stock in every place, plus **recipes and batches** (IN.1). Stock shows each item per place against its reorder level; the **Stock assistant** proposes reorders as purchase orders grouped by supplier, with why. Items you make have a **recipe**: ingredients for one batch, the batch cost and the cost per unit, the selling price and margin. **Making a batch** takes the ingredients out of stock, adds the product with a batch number and expiry date, and prints labels; a shortage says what to do. Batches near their date are flagged and sold first. On the phone: stock by place with a scanner, an item and its batches, making a batch with a stepper, and transfers. Cards: `TplInventory`, `TplRecipe`, `TmInventoryMobile`.

## Money and expenses

Spending, getting paid and **light accounting** (MO.1). The overview shows bank and cash, what's to collect and to pay, the sales tax due, what waits for you, and budgets by team. **Accounts** are a plain-worded chart with codes; journal entries are made for you from claims, invoices and payments, with the **Bookkeeping assistant** proposing the accounts for bank lines and a person posting them. The **sales tax return** for each two-month period is prepared with JoFotara reporting counts, downloaded to file with the Income and Sales Tax Department, then marked filed. Export for your accountant hands over entries and receipts. On the phone: money at a glance, approving claims, adding a card payment's receipt with the camera, and checking the assistant's entries. Cards: `TplMoney`, `TplLedger`, `TmMoneyMobile`.

## Subscriptions and tiers

Two kinds (SU.1). The workspace's own Whity plan stays in Billing. **Plans a tenant sells** (memberships, weekly boxes, service plans) come in tiers with price, period, what's included and subscribers; failed payments retry three times over a week with a WhatsApp and a link to pay, then the plan pauses. Customers choose, skip, pause and change tiers in the customer app. Cards: `TplSubscriptions`, `TmSubscriptionsMobile`.

## Applications

Requests people send (X.4), **reviewed in stages with a scoring panel** (AA.1). Each application type has a public form, stages and criteria. The **Screening assistant** checks documents first and asks applicants for what's missing. Reviewers score each criterion from 1 to 5; **others' scores stay hidden until you've saved yours**. Applicants follow their application's stage and add documents from the phone. Cards: `TplApplications`, `TplApplication`, `TmApplicationsMobile`.

## Chat, with people and agents

**One chat for everyone** (CH.1): agents are teammates who join direct chats, groups and project chats. They're always marked: the robot face, an **Agent** tag, who they work for in that chat, and a blue edge or tint on what they write. Adding an agent (CH.2) says what it can read (from now on by default), who it works for there, and what it may do, action by action, never more than that person; everyone sees it join. Proposals appear as dashed cards with who must decide; hand-offs between agents and "is drafting" are visible lines. Beside each chat: the people, the agents, and what each agent may do here. On the phone the fourth tab is **Chat** (CH.3). Cards: `TplChat`, `TplChatAddAgent`, `TmChat`.

## Agents

The 2.8 agent pages (section 24) stay; agents can now also be **set up on the phone** in four steps: start from a preset, instructions and who it works for, what it may do (Off, Ask first, Allowed), and where it works (channels, chats, boards). Cards: `TmAgentSetup`, with `TmAgentsManage` for managing them.

## Approvals

**One at a time** stays the default; a **List** view lets ordinary **tier-1** items be approved together (AQ.1), with the count and total in the bulk bar and an Undo for each. Kinds are named in plain words: Ordinary, Check first, Needs verifying. Tier 2 and 3 always open on their own. Cards: `TplApprovalsList`, `TmApprovalsGating`, with `TplAgentApprovals` and the phone's decision cards.

## Approval gating

Sign-ups, password resets and two-step resets (AG.1): every approval asks for **Face ID or a passkey**, and **clearing someone's two-step needs a second admin**. A request shows when and where it came from, the account, the identity checks done and by whom, the two approvals, and that there's no Undo. Cards: `TplGatingTwoStep`, `TmApprovalsGating`, with `TplApprovalGating`.

## Flows

**Workflows across everything** (FL.1): a trigger (created, changed, late, below a level, a schedule), steps where **people decide** and **agents act**, conditions with two branches, waits, and done. A document's route is one kind of flow, so Route templates now live under Flows. Each flow reads as **a sentence** with its changeable words highlighted. The builder draws it top to bottom with a **+** on every connector; a step's settings start with its sentence, then who decides, what they can do, and what happens if no one answers or while they're away. **Try it** runs on a sample; new versions apply to new runs. On the phone, flows (and forms) are **a list of steps in sentences** (FL.2). Cards: `TplFlows`, `TplFlowBuilder`, `TmFlowsMobile`, `TmFormBuilderMobile`.

## Organisational units, roles and delegations

The 2.5 pages (section 21) stay, with names instead of numbers. **I'm away** (OR.1) hands your approvals, agents' proposals and, if you choose, documents to sign to someone for set dates; anything above what they may approve waits; flows route to them and their decisions are recorded as on your behalf. On the phone: the organisation **level by level**, changing a role (described in plain words, powerful roles ask for Face ID), I'm away and the delegations in force. Cards: `TplAway`, `TmOrgMobile`, with `TplOrgUnits`, `TplRoles`, `TplRoleRecord`, `TplDelegations`.

## Documents

The library, a document's page, its routing and the editor (sections 18 and 23) stay. On the phone (DC.1): read and sign, **comment** on highlighted text, **suggest** changes (red struck, green added, the owner accepts), tick checklists, and ask an agent to help with a paragraph; its changes arrive as suggestions. Cards: `TmDocumentsMobile`, `TmDocEditMobile`.

## Forms

The builder, filling in, the public form and responses (section 22) stay. On the phone the **builder** is a list of questions with **+** between them, large tiles for question kinds, each question's settings (required, show only if, Arabic wording), and publishing with a QR code and link. Cards: `TmFormBuilderMobile`, `TmFormsMobile`.

## Coverage

| Area | Web | Phone |
| --- | --- | --- |
| Documents | `TplLibrary`, `TplDocRecord`, `TplDocRouting`, editor cards | `TmDocumentsMobile`, `TmDocEditMobile` |
| Flows | `TplFlows`, `TplFlowBuilder`, `TplFlowEditor` | `TmFlowsMobile` |
| Agents | `TplAgents`, `TplAgentPresets`, `TplAgentRecord`, `TplAgentInstructions`, `TplAgentScope` | `TmAgentsManage`, `TmAgentSetup` |
| Agent chats (Chat) | `TplChat`, `TplChatAddAgent` | `TmChat`, `TmCreateAgents` |
| Meetings | `TplMeetings`, `TplMeeting` | `TmMeetingsMobile` |
| Inventory | `TplInventory`, `TplRecipe` | `TmInventoryMobile`, stock count in `TmFormsMobile` |
| Tasks | `TplTasks` | `TmTasks` |
| Boards | `TplBoard` | `TmBoards` |
| Units, roles, delegations | `TplOrgUnits`, `TplRoles`, `TplRoleRecord`, `TplDelegations`, `TplAway` | `TmOrgMobile`, `TmPeople` |
| Approvals | `TplAgentApprovals`, `TplApprovalsList` | `TmHome`, `TmDecisionVariants`, `TmApprovalsGating` |
| Approval gating | `TplApprovalGating`, `TplGatingTwoStep` | `TmApprovalsGating` |
| Forms | `TplFormsManage`, `TplFormBuilder`, `TplFormFill`, `TplFormResponses` | `TmFormsMobile`, `TmFormBuilderMobile` |
| Applications | `TplApplications`, `TplApplication` | `TmApplicationsMobile` |
| Money and expenses | `TplMoney`, `TplLedger` | `TmMoneyMobile`, receipts in `TmCreateFlows` |
| Subscriptions and tiers | `TplSubscriptions`, `TplBilling`, `TplPlans` | `TmSubscriptionsMobile`, `TmWorkspaceMobile` |
| Appointments | `TplAppointments`, `TplBookingPage` | `TmAppointmentsMobile` |
