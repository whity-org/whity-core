# App map (v2.9)

The screens of whity-core's web app as they exist in `web/app`, grouped as the sidebar groups them, with IDs for design work. **Template** names the template in this system; **Needs** marks server work for the agent features.

## Navigation

Sidebar groups: (top) Dashboard · Inbox · Approvals — People — AI — Documents — Platform — Settings. The tenant switcher sits under the logo; the user and account menu at the foot. The system tenant (id 0) sees Platform; tenants see their own items. Plugin items arrive from `GET /api/navigation`.

**Settings (owner decision D1, 31 Oct 2026).** Settings is one item at the foot of the sidebar. It opens a settings area with its own grouped sub-nav: Workspace (General, Branding) · Sign-in and security (Security, SSO, Sign-up, Public forms) · Email (Email, Email domains) · Data (Storage, Tags, Languages, Translations) · Advanced (Feature flags, Error tracking). Every page keeps its route and permission. The personal account (U04) stays in the user menu, not the sidebar. The area has no card yet (design ask WHIT-826, `DESIGN-ASKS.md`).

## Screens

| ID | Screen | Route | Template | Needs |
| --- | --- | --- | --- | --- |
| **Overview** | | | | |
| A01 | Dashboard (home) | `/dashboard`; `/admin` redirects here | `TplHome`, `TplHomeMember`; operators' platform cards from `TplAdminDashboard`; shell states `AppShellCollapsed`, `AppShellMobile` | section 20; one merged waiting list (Inbox and Approvals), app home cards in the plugin manifest |
| A02 | Inbox (documents awaiting a decision) | `/admin/inbox` | `TplInbox` | section 21; states composed by the app |
| A03 | Approvals (agent proposals) | new: `/admin/approvals` | `TplAgentApprovals`, `TplAgentApprovalsAr`, `ApprovalQueue` | server 10 |
| A03a | Messages (conversations with customers) | new: `/admin/messages`; a customer's own at `/messages`; public chat at `/chat/{workspace}` | none yet: `DESIGN-ASKS.md` WHIT-939 | ADR 0016; settings at `/admin/settings/messages` |
| A04 | Calendar (day, week, month; new booking; booking sheet with Move and Cancel; events) | `/calendar` | none yet (ask WHIT-946) | section 21; ADR 0017; times in the workspace's zone, week from `week_start`, weekend from `weekend_days` |
| A04b | Calendar services · service | `/calendar/services`, `/calendar/services/[id]` | none yet (ask WHIT-946) | section 21; Overview only (no access list or history in the API yet) |
| A04c | Hours and time off | `/calendar/hours` | none yet (ask WHIT-946) | section 21; own hours on `calendar:write`, anyone's on `calendar:manage` |
| A04d | Calendar settings | `/calendar/settings` | none yet (ask WHIT-946) | section 21; `calendar:manage` |
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
| A52b | Public forms | `/admin/settings/public-forms` | none yet (ask WHIT-906) | section 25 |
| A52c | Sign-up for a workspace's customers | `/admin/settings/customer-signup` | none yet (ask WHIT-915) | section 25; the operator's platform sign-up stays `/admin/settings/signup` |
| A53 | Storage · feature flags · error tracking | `/admin/settings/storage`, `/feature-flags`, `/error-tracking` | `TplPlatformSettings` | section 25 |
| **Account** | | | | |
| U01 | Sign in · register · verify email | `/login`, `/register`, `/verify-email` | `TplAuthSignIn` | |
| U01b | Two-step code · turn on · recovery codes | `/login` (step 2), `/settings` | `TplAuthTwoStep` | |
| U02 | Forgot · reset password · recovery | `/forgot-password`, `/reset-password`, `/account-recovery` | `TplAuthReset` | |
| U03 | Accept invitation · onboarding | `/accept-invitation`, `/onboarding` | `TplAuthInvite` | |
| U03b | Join a workspace as a customer | `/join/[workspace]`, `/join/confirm` | none yet (ask WHIT-915) | built on `TplAuthInvite` and the `/f/[slug]` frame |
| U04 | Settings · licensing | `/settings`, `/licensing` | `TplAccountPrefs`, `TplAccountSecurity`, `TplLicensing` | section 25; text size preference |
| U05 | Status | `/status` | `TplSystemStates` | |
| U06 | 404 · 403 · 500 · offline · maintenance · rate limit | `not-found`, `error`, middleware | `TplSystemStates` | |
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

Every screen in the built app (inventory of 1 November 2026) now has a design. Still to draw: agent notifications (email, push, WhatsApp), the mobile approval screen, the 80 mm thermal receipt, the publisher's page and the public marketplace.
