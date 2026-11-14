# App map

The screens of whity-core's web app as they exist in `web/app`, grouped as the sidebar groups them, with IDs for design work. **Template** names the template in this system; **Needs** marks server work for the agent features.

## Navigation

Sidebar groups: (top) Dashboard · Inbox · Approvals — People — AI — Documents — Platform — Settings. The tenant switcher sits under the logo; the user and account menu at the foot. The system tenant (id 0) sees Platform; tenants see their own items. Plugin items arrive from `GET /api/navigation`.

**Settings (owner decision D1, 31 Oct 2026).** Settings is one item at the foot of the sidebar. It opens a settings area with its own grouped sub-nav: Workspace (General, Branding) · Sign-in and security (Security, SSO, Sign-up, Public forms) · Email (Email, Email domains) · Data (Storage, Tags, Languages, Translations) · Advanced (Feature flags, Error tracking). Every page keeps its route and permission. The personal account (U04) stays in the user menu, not the sidebar. Drawn as the left column of every `TplSettings*` card (WHIT-826, SC); on a phone, `PwSettings`.

## Screens

| ID | Screen | Route | Template | Needs |
| --- | --- | --- | --- | --- |
| **Overview** | | | | |
| A01 | Dashboard (home) | `/dashboard`; `/admin` redirects here | `TplHome`, `TplHomeMember`; operators' platform cards from `TplAdminDashboard`; shell states `AppShellCollapsed`, `AppShellMobile` | section 20; one merged waiting list (Inbox and Approvals), app home cards in the plugin manifest |
| A02 | Inbox (documents awaiting a decision) | `/admin/inbox` | `TplInbox` | section 21; states composed by the app |
| A03 | Approvals (agent proposals) | new: `/admin/approvals` | `TplAgentApprovals`, `TplAgentApprovalsAr`, `ApprovalQueue` | server 10 |
| A03a | Messages (conversations with customers) | new: `/admin/messages`; a customer's own at `/messages`; public chat at `/chat/{workspace}` | `TplMessagesInbox`, `TplChatBox`, `TplSettingsMessages`; phone `PwMessages` (SC.1–SC.3) | ADR 0016; settings at `/admin/settings/messages` |
| A04 | Calendar (day, week, month; new booking; booking sheet with Move and Cancel; events) | `/calendar` | `TplCalendarStaff` (SU.3); phone `PwCalendar` | section 21; ADR 0017; times in the workspace's zone, week from `week_start`, weekend from `weekend_days` |
| A04b | Calendar services · service | `/calendar/services`, `/calendar/services/[id]` | `TplCalendarStaff` (Services tab) | section 21; Overview only (no access list or history in the API yet) |
| A04c | Hours and time off | `/calendar/hours` | `TplCalendarStaff` (Hours and time off) | section 21; own hours on `calendar:write`, anyone's on `calendar:manage` |
| A04d | Calendar settings | `/calendar/settings` | `TplCalendarStaff` (Settings tab) | section 21; `calendar:manage` |
| A04e | Public booking page · the customer's booking (move, cancel) | `/book/[workspace]`, `/booking/[token]` | `TplBookingPage` (booking); `TplBookingManage` (the customer's booking, SU.10); emails `EmailBookings` | built on the `/f/[slug]` frame (`TplFormPublic`); no sign-in; times in the workspace's zone, and the visitor's too when it differs; ADR 0017 section 6 |
| **People** | | | | |
| A10 | Users · user | `/admin/users`, `/admin/users/[id]` | `TplUsers`, `TplUserRecord`, `TplUserRecordAr` | section 21 |
| A11 | User groups | `/admin/user-groups` | `TplUserGroups` | section 21 |
| A12 | Roles · role | `/admin/roles`, `/admin/roles/[id]` | `TplRoles`, `TplRoleRecord` | section 21 |
| A13 | Organisational units | `/admin/ous` | `TplOrgUnits` | section 21 |
| A14 | Delegations | `/admin/delegations` | `TplDelegations` | section 21 |
| A15 | Relations | `/admin/relations` | `TplRelations` | section 21 |
| A16 | Registrations · invitations | `/admin/registrations` | `TplApprovalGating` | section 21; request dates |
| **AI** | | | | |
| A20 | Agents · connected AI apps | `/admin/ai-principals` | `TplAgents`, `TplAgentPresets`, `TplConnectedApps`, `TplConnectWizard` | section 24; agents API exists, pages new; AI principals renamed |
| A21 | Agent · connected app (what it may do) | new: `/admin/ai-principals/[id]` | `TplAgentRecord`, `TplAgentRecordAr`, `TplAgentInstructions`, `TplAgentScope` | section 24 |
| A22 | What AI can do (was MCP tools) | `/admin/mcp-tools` | `TplWhatAICanDo` | section 24; technical columns behind Show technical details |
| A23 | Agent activity | new (from audit logs) | `AgentActivity` (filter by agent), `Timeline`, `UndoBar` | |
| **Documents** | | | | |
| A30 | Document library · document | `/admin/document-library`, `/[id]` | `TplLibrary`, `TplDocRecord`; notes and wiki: `TplNotesWiki` | section 23; record tabs Document, Routing, History, Verification |
| A31 | Document templates | `/admin/document-templates` | `TplDocTemplates` | section 23 |
| A32 | Route templates · routing | `/admin/document-route-templates`, `/admin/document-routing/[documentId]` | `TplRouteTemplates`, `TplFlowEditor`, `TplDocRouting`, `TplDocRoutingAr` | section 23; circulation is the record's Routing tab |
| A33 | Document designer | `/admin/documents` (editor) | `TplDocDesigner`; the Document editor cards (section 18) | the editor, built from `handoff/editor/` |
| A34 | Forms · form builder · public form · my submissions | `/admin/x/forms-builder`, `/admin/x/forms-catalog`, `/forms/[id]`, `/f/[slug]`, `/admin/x/my-form-submissions` | `TplFormsManage`, `TplFormBuilder`, `TplFormBuilderAr`, `TplFormResponses`, `TplFormsCatalog`, `TplFormFill`, `TplFormPublic`, `TplFormPublicStates`, `TplMySubmissions` | section 22; server-side drafts; translatable form text |
| **Platform** | | | | |
| A40 | Tenants · tenant | `/admin/tenants`, `/[id]` | `TplTenants` | section 25; server-side search; "workspace" in the interface |
| A41 | Plugins: Store and Installed tabs (one sidebar item) | `/admin/plugins`, `/admin/plugins/store`, `/admin/plugins/store/<plugin>` | `TplPluginStore`, `TplPluginDetail`, `TplPluginInstall`, `TplPluginsInstalled`; `PluginCard` | section 19 |
| A42 | Billing · plans | `/admin/billing`, `/billing` | `TplBilling`, `TplBillingAr`, `TplPlans` | section 25; money in normal units |
| A43 | Promotions | `/admin/promotions` | `TplPromotions` | section 25; JOD default |
| A44 | Audit logs · errors · System | `/admin/audit-logs`, `/admin/errors`, `/admin/stats` (System, operators only, H.3) | `TplAuditLog`, `TplErrors`, `TplSystem` | section 25 |
| A45 | Languages · translations | `/admin/languages`, `/admin/translations` | `TplSettingsTranslations`; languages in `TplPlatformSettings` | section 25 |
| A46 | Tags · tag groups | `/admin/tags`, `/admin/tag-groups` | `TplSettingsTags` | section 25 |
| A47 | Notifications | `/admin/notifications` | `TplNotificationDelivery` | section 25 |
| A48 | Approval gating | `/admin/approval-gating/*` | `TplApprovalGating` | section 21; tier-3 confirmation |
| **Settings** | | | | |
| A50 | Branding | `/admin/settings/branding` | `TplSettingsGeneral`, `TplAdminBranding` | section 25; settings sub-nav |
| A51 | Email · email domains | `/admin/settings/email`, `/email-domains` | `TplSettingsEmailDomains`; email in `TplPlatformSettings` | section 25 |
| A52 | Security · SSO · sign-up | `/admin/settings/security`, `/sso`, `/signup` | `TplSettingsSecurity`, `TplSettingsSSO` | section 25 |
| A52b | Public forms | `/admin/settings/public-forms` | `TplSettingsPublicForms` (SC.13) | section 25 |
| A52c | Sign-up for a workspace's customers | `/admin/settings/customer-signup` | `TplSettingsSignup` (SC.11) | section 25; the operator's platform sign-up stays `/admin/settings/signup` |
| A53 | Storage · feature flags · error tracking | `/admin/settings/storage`, `/feature-flags`, `/error-tracking` | `TplPlatformSettings` | section 25 |
| **Account** | | | | |
| U01 | Sign in · register · verify email | `/login`, `/register`, `/verify-email` | `TplAuthSignIn` | |
| U01b | Two-step code · turn on · recovery codes | `/login` (step 2), `/settings` | `TplAuthTwoStep` | |
| U02 | Forgot · reset password · recovery | `/forgot-password`, `/reset-password`, `/account-recovery` | `TplAuthReset` | |
| U03 | Accept invitation · onboarding | `/accept-invitation`, `/onboarding` | `TplAuthInvite` | |
| U03b | Join a workspace as a customer | `/join/[workspace]`, `/join/confirm` | `TplJoinWorkspace` (SC.12); emails `EmailJoining` | built on `TplAuthInvite` and the `/f/[slug]` frame |
| U04 | Settings · licensing | `/settings`, `/licensing` | `TplAccountPrefs`, `TplAccountSecurity`, `TplLicensing` | section 25; text size preference |
| U05 | Status | `/status` | `TplSystemStates` | |
| U06 | 404 · 403 · 500 · offline · maintenance · rate limit | `not-found`, `error`, middleware | `TplSystemStates`; Lottie moments in `TplMoments` | LT.4 |
| **Partner apps (built on Whity)** | | | | |
| P01 | Front Office: invoices | plugin route | `TplPartnerInvoices`, `DataTableSelect` | Front Office app, JoFotara integration |
| **Documents (Whity Render)** | | | | |
| R01 | Quote | Whity Render | `TplDocQuote` | online acceptance |
| R02 | Tax invoice (English or Arabic leads, continuation) | Whity Render | `TplDocInvoice`, `TplDocInvoiceAr`, `TplDocInvoiceLong` | JoFotara QR and reference |
| R03 | Credit note | Whity Render | `TplDocCreditNote` | JoFotara |
| R04 | Receipt (A5) | Whity Render | `TplDocReceipt` | |
| R05 | Statement of account | Whity Render | `TplDocStatement` | |
| R06 | Payment reminder | Whity Render | `TplDocReminder` | agent sending with approval |
| R07 | Delivery note | Whity Render | `TplDocDeliveryNote` | |
| R08 | Payslip | Whity Render | `TplDocPayslip` | HR app |
| R09 | Employment letter | Whity Render | `TplDocEmploymentLetter` | HR app, verification page |
| R10 | Leave approval | Whity Render | `TplDocLeaveApproval` | HR app, audit log |
| R11 | Report | Whity Render | `TplDocReport` | |
| R12 | Letter | Whity Render | `TplDocLetter` | |
| R13 | Purchase order | Whity Render | `TplDocPurchaseOrder` | |

**Mobile (Whity app, 3.0):** screens MB01–MB62 are listed in section 26 with their cards; the mobile approval screen is `TmHome` and `TmDecisionVariants`.

**The web app on a phone (3.4, PW.1–PW.10, WHIT-1006):** under 640px the web app takes the tab bar and the phone app's parts. Navigation, More, Create and the keyboard `PwNav`; lists (sort, select, bulk, filters, nothing found) `PwLists`; document library `PwLibrary`; editor toolbar and modes `PwEditor`; calendar week, month and sub-pages `PwCalendar`; settings menu and deep pages `PwSettings`; read-only builders `PwBuilders`; the desktop sidebar on short screens and the account menu (WHIT-827) `PwShortSidebar`; installing it and notifications (PW.11) `PwInstall`.

Every screen in the built app (inventory of 1 November 2026) now has a design. Agent notifications by email and WhatsApp (`TplAgentNotify`) and the 80 mm receipt (`TplReceipt80`) are drawn in 3.2. Still to draw: the publisher's page and the public marketplace.

## Work and operations (3.1)

New areas; routes are for the Whity team to settle. Section 27 has the full coverage table.

| ID | Screen | Template | Phone |
| --- | --- | --- | --- |
| W01 | Tasks (on Taskaat) | `TplTasks` | `TmTasks` |
| W02 | Boards | `TplBoard` | `TmBoards` |
| W03 | Meetings · a meeting · meeting bodies | `TplMeetings`, `TplMeeting` | `TmMeetingsMobile` |
| W04 | Appointments · public booking page | `TplAppointments`, `TplBookingPage` | `TmAppointmentsMobile` |
| W05 | Applications · an application | `TplApplications`, `TplApplication` | `TmApplicationsMobile` |
| W06 | Flows · flow builder (route templates move here) | `TplFlows`, `TplFlowBuilder` | `TmFlowsMobile` |
| W07 | Chat, with people and agents | `TplChat`, `TplChatAddAgent` | `TmChat` |
| O01 | Inventory · a recipe | `TplInventory`, `TplRecipe` | `TmInventoryMobile` |
| O02 | Money · accounts and sales tax | `TplMoney`, `TplLedger` | `TmMoneyMobile` |
| O03 | Subscriptions a tenant sells | `TplSubscriptions` | `TmSubscriptionsMobile` |

Additions to existing screens: A03 Approvals list view (`TplApprovalsList`); A48 two-step resets with two admins (`TplGatingTwoStep`); A14 Delegations with I'm away (`TplAway`); on the phone, agent setup (`TmAgentSetup`), the organisation (`TmOrgMobile`), document editing (`TmDocEditMobile`) and the form builder (`TmFormBuilderMobile`).

## Near term (3.2)

The roadmap's near-term pages (section 28, N1–N9). Routes are for the Whity team to settle.

| ID | Screen | Template | Phone |
| --- | --- | --- | --- |
| N1 | Contacts · a contact | `TplContacts` | `TmOpsMobile` |
| N2 | Custom fields (Settings › Fields) | `TplCustomFields` | `TmFinanceMobile` |
| N3 | Import from a spreadsheet | `TplImport` | `TmFinanceMobile` |
| N4 | Receiving goods · supplier bill | `TplReceiving` | `TmOpsMobile` |
| N5 | Waste and stock adjustments | `TplAdjustments` | `TmOpsMobile` |
| N6 | Bank reconciliation | `TplBankRec` | `TmFinanceMobile` |
| N7 | Reports | `TplReports` | `TmFinanceMobile` |
| N8 | 80 mm receipt (Whity Render) | `TplReceipt80` | |
| N9 | Agent notifications by email and WhatsApp | `TplAgentNotify` | |

## Websites (3.2)

Public sites, outside the app (section 29). Build-ready pages are in `sites/`.

| Site | Pages | Card |
| --- | --- | --- |
| whity.dev | Home (companies first, a door for builders) | `SiteHome`, `SiteHomeAr` |
| whity.dev | Module pages: money, stock, approvals, documents | `SiteModMoney`, `SiteModMoneyAr`, `SiteModStock`, `SiteModApprovals`, `SiteModDocuments` |
| brand.whity.dev | Brand guide | `SiteBrand` |
| health.whity.dev | Status | `SiteStatus` |
| id.whity.dev | Sign in · approve on the phone · account | `SiteId` |
| docs.whity.dev | Docs home · an article | `SiteDocs` |
| whity.dev | Security (WS.13, WS.18) | `SiteSecurity` |
| whity.dev | Partners (WS.15) | `SitePartners`, `SitePartnersAr` |
| whity.dev | About (WS.16) | `SiteAbout`, `SiteAboutAr` |
| whity.dev | Contact (WS.19) | `SiteContact`, `SiteContactAr` |
| whity.dev | Install · privacy · licence · 404 (WS.20–WS.23) | `SiteInstall`, `SitePrivacy`, `SiteLicence`, `Site404` |

## Motion in the app (LT.4)

| Moments | Web | Phone |
| --- | --- | --- |
| Empty states, done, first run, waiting, errors, and the Animations setting | `TplMoments` | `TmMoments` |

## Emails (EM.1)

Build-ready in `handoff/emails/` (English and Arabic, with placeholders).

| Group | Emails | Card |
| --- | --- | --- |
| Account | Invitation, verify email, reset password, new sign-in, two-step turned off | `EmailAccount` |
| Money (to a workspace's customers) | Invoice, quote, payment reminder, receipt | `EmailMoney` |
| Agents | Needs your approval, daily summary | `EmailAgents` (with `TplAgentNotify`) |
| Workspace and billing | Workspace ready, plan limit, payment failed, Whity receipt | `EmailWorkspace` |
| Bookings (3.4) | Confirmed (with `.ics`), reminder, moved, cancelled | `EmailBookings` |
| Forms, signing, messages (3.4) | Form received, form decision (with the printout PDF), sign request, message reply | `EmailForms` |
| Joining (3.4) | Join approved, join declined | `EmailJoining` |
| Arabic and dark mode | | `EmailArabic`, `EmailDark` |

## Screens with no card, drawn in 3.4 (SC.1–SC.18)

| Screen | Route | Card |
| --- | --- | --- |
| Messages inbox | `/admin/messages` | `TplMessagesInbox`, `PwMessages` |
| Customer chat box | `/chat/[workspace]`, a customer's `/messages` | `TplChatBox` |
| Settings › Messages | `/admin/settings/messages` | `TplSettingsMessages` |
| Approvals › Decided, Edit before approving | `/admin/approvals` | `TplApprovalsDecided` |
| Proposal with an impact plan | Approvals | `TplImpactPlan` |
| Issuing an agent credential | Agents › an agent | `TplAgentCredential` |
| whity-agent desktop app | whity-org/whity-agent | `TplWhityAgent` |
| Saved blocks library | `/admin/document-sources/saved-blocks` | `TplSavedBlocks` |
| Welcome, create your workspace, need an invitation | `/welcome` | `TplWelcomeWorkspace` |
| Settings › Sign-up and team invites | `/admin/settings/signup`, `/admin/settings/customer-signup` | `TplSettingsSignup` |
| Join a workspace | `/join/[workspace]`, `/join/confirm` | `TplJoinWorkspace` |
| Settings › Public forms | `/admin/settings/public-forms` | `TplSettingsPublicForms` |
| Passing a plan limit | Billing, and a banner across the app | `TplPlanLimit` |
| Document themes and the theme picker | Documents › Document themes; a document's settings | `TplDocThemes` |
| Issuing a document from a template | a record › Issue a document | `TplIssueDocument` |
| Two-column sign-in | `/login` | `TplSignInTwoColumn` |
| Whity ID, more screens | id.whity.dev | `TplWhityIdMore` |

## Form printouts (3.4, FP.1–FP.12)

| Surface | Where | Card |
| --- | --- | --- |
| A form's printed record and approval certificate | a form response › Print or PDF; issued with the decision | `DocFormPrintout`, `DocFormPrintoutMore`, `DocFormLooks` |
| Form printout style (paid plans and trials; free = standard) | Documents › Document themes › a theme › Form printouts | `TplFormPrintStyle` |
| Every print block with its spec | Whity Render, the editor's page view | `DocFormBlocks` |

**Every surface (3.4, SU.1–SU.10):** `verify/[token]` → `TplVerifyDocument`; `billing/return` → `TplPaymentReturn`; `calendar` on a computer → `TplCalendarStaff` (on a phone `PwCalendar`); `agents/[id]/persona` → `TplAgentPersona`; WhatsApp and SMS to customers → `MsgCustomerMessages`; link previews → `SiteShareCards`; `install.sh` output → `SiteInstallerTerminal`; `booking/[token]` → `TplBookingManage`; booking, form, signing, joining and reply emails → `EmailBookings`, `EmailForms`, `EmailJoining`.
