# Whity brand decision register

The brand is being rebuilt one stage at a time. A stage is locked before the next one starts. The decision board is the "Whity Brand Decisions" artifact. Production files were drawn from these numbers in stage 8, and the final guideline in stage 9. All nine stages are locked; a change now reopens the stage it belongs to and is recorded here first.

## Procedure
1. Logo: locked 28 Oct 2026
2. Colour: locked 28 Oct 2026
3. Type, brand and UI: locked 28 Oct 2026
4. Shape, space, depth: locked 28 Oct 2026
5. Icons, motion, voice: locked 28 Oct 2026
6. Document components: locked 28 Oct 2026
7. UI components: locked 28 Oct 2026
8. Files and templates: locked 28 Oct 2026
9. Final guideline: locked 28 Oct 2026

Later versions: 2.1 P2 components · 2.2 Document editor · 2.3 Plugin store (locked 31 Oct 2026; S.9–S.12 added 2 Nov) · 2.4 Home (locked 2 Nov 2026) · 2.5 Page patterns and People (locked 2 Nov 2026) · 2.6 Forms (locked 2 Nov 2026) · 2.7 Ease of use and Documents and routing (locked 2 Nov 2026) · 2.8 AI and agents (locked 2 Nov 2026) · 2.9 Workspace, billing and settings (locked 2 Nov 2026)

## Stage 1 · Logo (locked 28 Oct 2026)

### The mark
The mark is built from one module, m, which is the width of the blue edge.

| # | Decision | Locked value |
| --- | --- | --- |
| 1.1 | Proportion | Both planes are 8 m square. The sheet is offset 3 m up and to the right (offset ÷ side = 0.375). |
| 1.2 | Corner radius | Outer radius 2 m, inner (window) radius 1 m, concentric. The ring is exactly 1 m at every angle. |
| 1.3 | Edge (the module) | 1 m, which is 2px at 24px and matches the Tabler icon stroke. |
| 1.4 | Corner shape | Circular arcs, the same as CSS border-radius. |
| — | Canvas | 12 m square, 0.5 m padding, live area 11 m. |

Overall ratio: side 8, offset 3, radius 2, edge 1.

### The wordmark
| # | Decision | Locked value |
| --- | --- | --- |
| 1.5a | Weight | Readex Pro 600, outlined from the variable font. |
| 1.5b | Letter spacing | −1% (−10 units per letter). |
| 1.5c | Dot on the i | A rounded square. Its side equals the stem width and its corner radius is ¼ of the side. |
| 1.5d | Letter corners | Outside corners are rounded to ¼ of the stem (0.36 m at cap height 8 m). Inside corners stay sharp. |

### The lockup
| # | Decision | Locked value |
| --- | --- | --- |
| 1.6a | Word size | Cap height 8 m (equal to the plane side), x-height 6 m. The baseline is the platform's bottom edge and the cap line is its top. |
| 1.6b | Gap | 3 m from the sheet's right edge to the leftmost point of the W. |
| 1.6c | Stacked lockup | The mark is centred over the word, 3 m apart, at the same cap height. |

### Small sizes and clear space
| # | Decision | Locked value |
| --- | --- | --- |
| 1.7a | Small sizes | Pixel-fitted cuts below 24px. The 16px cut is side 11, offset 4, edge 2, radius 3 (in px). The 20px cut is side 14, offset 5, edge 2, radius 3.5. The master is used from 24px up, where m = 2px. |
| 1.7b | Clear space | 3 m around the live area. |
| 1.7c | Minimum size | The horizontal lockup is at least 20px tall on screen (word cap height 13.3px) and 6 mm in print. The mark alone can go down to 16px. |

### Colourways and app icon
| # | Decision | Locked value |
| --- | --- | --- |
| 1.8a | On dark | The platform and edge are #59a0f9, the window is white and the word is #f2f3f5. |
| 1.8b | App icon | A Whity Blue tile with the mark in white and the window knocked out. The same tile is used for the PWA icon, the social avatar and large favicons. |
| 1.8c | Mark in the tile | The tile is 18 m and the mark's live area is 61% of it (3.5 m margin). |
| 1.8 | Set | Exactly five colourways: colour, reversed, on brand, one colour ink, one colour white. The one-colour versions knock the window out. |

### Rules
- Every corner is ¼ of what it ends: the planes, the dot and the letter stems.
- Every gap is the step of 3 m: the offset, the lockup gap and the clear space.
- Every height in the lockup is a mark measure.

## Stage 2 · Colour (locked 28 Oct 2026)

### Brand and ground
| # | Decision | Locked value |
| --- | --- | --- |
| 2.1 | Whity Blue | #0961bb, oklch(0.5 0.16 255). White text on it is 6.1:1. |
| 2.2 | Blue on dark | #59a0f9, oklch(0.70 0.15 255). It is 7.4:1 on the dark page. Buttons on dark take a dark label. |
| 2.3 | Greys | Pure grey (chroma 0), so they stay neutral under any tenant colour. |
| 2.4 | Page ground | #eeeeee, oklch(0.95), under white cards. |

### Status colours
| # | Decision | Locked value |
| --- | --- | --- |
| 2.5a | Structure | Each status has three tokens: a fill (with its own label colour), a text colour and a tint. Error and destructive are one token. |
| — | Success | Fill #0a7e3a with a white label. Text #087736. Tint #e0fae4. On dark: #5ac576, tint #14361d. |
| 2.5c | Warning | Fill #f2b036 with a dark label (#1a1206, 9.7:1). Text #9a5505. Tint #fef4df. On dark: #f7b83d, tint #432f07. |
| — | Error | Fill #cc2827 with a white label. Text #c2181d. Tint #fceeec. On dark: #f66d67, tint #4f1a18. |
| 2.5b | Info | Neutral: fill and text #555555, tint #f0f0f0. On dark: #aeaeae, tint #262626. Blue is kept for action and the brand. |
| — | Text contrast | Every status text colour passes 4.5:1 on both white cards and the #eeeeee page. |

### Contrast fixes
| # | Decision | Locked value |
| --- | --- | --- |
| 2.6 | Secondary text | #696969, oklch(0.52). It is 4.7:1 on the page and 5.5:1 on cards. |
| 2.6 | Input border | #929292, oklch(0.66). It is 3.1:1 on cards. Card borders and dividers stay light (#e5e5e5) because they are decorative. |

### Charts
| # | Decision | Locked value |
| --- | --- | --- |
| 2.7 | Categorical series, light | Main colour #0961bb, amber #f7a224, sky #91dbf2, plum #9d2398, green #35aa76, graphite #3a3a3a. |
| 2.7 | Categorical series, dark | #59a0f9, #f7a224, #91dbf2, #d25bcb, #53c48e, #858585. Graphite changed from #b7b7b7 on 28 Oct 2026 (version 2.1): beside green it was ΔE 5.3 under deuteranopia, below the 8 floor; #858585 gives 12.9 and stays above 3:1 on the dark card. |
| 2.7 | Contrast between series | The smallest OKLab difference is 0.08 under deuteranopia and protanopia. |
| 2.7 | Rules | Series 1 is always the tenant's main colour. Ordered (sequential) data uses lightness steps of the main colour. |

### Tenant colour rules
| # | Decision | Locked value |
| --- | --- | --- |
| 2.8a | Contrast | Pick a white or near-black label, whichever passes 4.5:1. Lower the lightness only if neither passes. The admin sees what changed. |
| 2.8b | Derivation | One colour goes in. The rest is derived from its hue: on dark at L 0.70 (chroma up to 0.15), the plane at L 0.30 (chroma up to 0.10), the tint at L 0.96 (chroma up to 0.03). Each can be overridden. |
| 2.8c | Demo tenant | Burnt orange #b74c05, oklch(0.55 0.154 45), replaces teal in all partner and demo templates. Its derived set is #e97c47 on dark, #4f1c00 plane and #feeee7 tint. The user disliked the teal. |

## Stage 3 · Type, brand and UI (locked 28 Oct 2026)

### Faces
| # | Decision | Locked value |
| --- | --- | --- |
| 3.1 | Brand headlines | Readex Pro 600 for the site, slides, document titles and social posts. Body text stays in Noto Sans. The product's own screens never use Readex Pro. Arabic document titles use its Arabic design (E.35). |
| 3.2 | Product, English | Noto Sans. |
| 3.3 | Product, Arabic | Noto Sans Arabic. |
| 3.4 | Codes, IDs, keys and code | Geist Mono. |

### Sizes
| # | Decision | Locked value |
| --- | --- | --- |
| 3.5a | Product scale | Caption 12, text 14 (default, = shadcn text-sm), large 16, card title 18, section 20, page title 24 px. 11px is the absolute floor and is used only for counters and badges. |
| 3.5b | Brand headline scale | Steps of 1.25 from a 16px body: 16 · 20 · 25 · 31 · 39 · 49 px. |

### Weights and line heights
| # | Decision | Locked value |
| --- | --- | --- |
| 3.6 | Body and component text | 400, line height 1.5 (Arabic 1.7). |
| 3.6 | Labels, buttons, tabs, table headers | 500, line height 1.25 (Arabic 1.5). |
| 3.6 | Product titles | 600, line height 1.25 (Arabic 1.5). |
| 3.6 | Key numbers | 600, tabular figures, line height 1.2. |
| 3.6 | Brand headlines | Readex Pro 600, line height 1.1 for large and 1.25 for small. |
| 3.6 | Mono | Geist Mono 400 or 500, line height 1.5. |
| 3.6 | Other rules | No 700 in the product. Letter spacing is 0 except brand headlines (−1% to −2%) and uppercase labels (+6%). |

### Arabic rules
| # | Decision | Locked value |
| --- | --- | --- |
| 3.7a | Size | Arabic is set at the same size as Latin (100%). The user chose this over the recommended 110%. |
| 3.7b | Digits | Western digits 0–9 in every language. |

## Stage 4 · Shape, space, depth (locked 28 Oct 2026)

### Round 1 (locked 28 Oct 2026)
| # | Decision | Locked value |
| --- | --- | --- |
| 4.1 | Density | Buttons and inputs are 36px tall (small buttons 28px for toolbars and tables). Table rows are 44px. Gaps between groups are 16px. Spacing stays on a 4px grid. |
| 4.2 | Corners | The ¼ rule: a control's radius is ¼ of its height (9px at 36, 7px at 28). Cards are 12px and dialogs 16px. |
| 4.3 | The plane | Used in three places: the main button, approved agent work and a hovered clickable card. It is a hard 3px offset down-left that lifts to 5px on hover and never mirrors in RTL. Waiting proposals stay dashed and flat. |
| 4.4 | Floating layers | Menus, popovers, toasts and dialogs use a soft two-layer shadow. The plane is never used for floating layers. |

### Round 2 (locked 28 Oct 2026)
| # | Decision | Locked value |
| --- | --- | --- |
| 4.5 | App shell | Sidebar 240px, collapsing to 56px. Reading pages (forms, settings) are capped at 1280px and centred. Tables and boards may use the full width. |
| 4.5 | Gutters and grid | Page gutters are 24px on desktop and 16px on phones. Cards have 16–20px of inside padding. 12 columns with 24px gaps. |
| 4.5 | Breakpoints | Tailwind's: 640, 768, 1024, 1280, 1536. The sidebar collapses below 1024 and becomes a drawer below 768. |
| 4.5 | Reading width | Forms at most 720px; text at most 68 characters per line. |
| 4.6a | Cards | 1px #e5e5e5 border, no shadow. |
| 4.6b | Focus | A 2px Whity Blue ring with a 2px offset. |
| 4.6 | Tables | Horizontal lines only (#ececec). No zebra stripes and no vertical lines. Numbers are right-aligned in tabular figures. The header row is #f7f7f7. |
| 4.6 | Dividers | 1px #ececec inside cards; 1px #e5e5e5 between page regions. |

## Stage 5 · Icons, motion, voice (locked 28 Oct 2026)

### Icons and motion
| # | Decision | Locked value |
| --- | --- | --- |
| 5.1 | Icon set | Tabler outline. The agent icon becomes Tabler's robot face; today it is a plug. |
| 5.2 | Icon weight | Rendered stroke of 1.5px at 16px (stroke-width 2.25 on 16px icons) and 2 at 20 and 24px. 16px is the default beside 14px text; 20px in touch navigation; 24px in headers and empty states. |
| 5.3 | Motion timing | Fast 120 ms (hover, press), base 180 ms (state changes), slow 250 ms (things entering), on one ease-out curve, cubic-bezier(0.2, 0, 0, 1). Reduced motion shows the end state only. |
| 5.4 | Approve animation | Set down: the dashed edge turns solid blue and the plane slides in underneath, 200 ms. Undo plays it backwards. |

### Voice
| # | Decision | Locked value |
| --- | --- | --- |
| 5.5a | English | Plain and direct. Address the reader as "you", use active verbs, and make buttons say what happens. No exclamation marks. "We" only on the website and in docs. |
| 5.5b | Arabic interface | Simple Modern Standard Arabic in short sentences, reviewed by a native speaker. |
| 5.6a | Agent messages | Describe the action: agent named by role, then who it acts for, then what it did or proposes. No "I", no feelings. |
| 5.6b | Agent names | By role (Billing assistant, HR assistant). No human first names and no "Whity AI". |
| 5.7 | Errors | Say what happened and what to do. No apology, no blame, one next step. Technical detail and a copyable reference go behind "Details". |

### Words
| Use | Avoid |
| --- | --- |
| agent, assistant (named by role) | AI employee, copilot, bot, magic |
| approve, ask first, allowed, scope | autonomous, hands-free, set and forget |
| platform, workspace, app | suite, solution, ecosystem, synergy |
| Buttons that name the action, such as "Issue invoice" | Submit, OK, Confirm, Proceed |
| Built on Whity | Powered by AI, AI-first |

Other rules:
- Sentence case everywhere.
- Western digits, JOD with 3 decimals, dates as 27 Oct 2026 (27/10/2026 where space is short), 24-hour times.

## Stage 6 · Document components (locked 28 Oct 2026)
These are the building blocks for everything Whity Render prints. They were shown on an A4 tax invoice for the demo tenant, Example Bakery (burnt orange).

### The page
| # | Decision | Locked value |
| --- | --- | --- |
| 6.1 | Margins | 16 mm on all sides of an A4 page, leaving 178 mm of content width. US Letter and A5 follow the same rules. |
| 6.2 | Letterhead | Tenant logo, address and tax number at the start side; title, number and date at the end side. |
| 6.3 | Tenant colour | Used on the document title only. Everything else is ink, so documents print and fax cleanly. A theme may extend it to headings (E.45, 31 Oct 2026). |
| 6.4 | Languages | Either language can lead, chosen per customer: Arabic with English labels (layout mirrors) or English with Arabic labels. Latin values inside Arabic are isolated with bdi. |

### Inside the document
| # | Decision | Locked value |
| --- | --- | --- |
| 6.5 | Type | Body Noto Sans 9.5 pt, labels 8.5 pt, title Readex Pro 600 at 22 pt, codes in Geist Mono. |
| 6.6 | Line-item table | A strong rule under the header and hairlines between rows. No zebra stripes, no grid. |
| 6.6 | Totals | End-aligned block 46% wide. Only the grand total is bold, with a 1.5 pt rule above. The amount is written in words under it in both languages (dinars and fils). |
| 6.6 | Numbers | Tabular figures, end-aligned, JOD to 3 decimals. |
| 6.7 | Footer | The JoFotara stamp, reference and "Page x of y" at the start side; the QR code ("Scan to verify") at the end side. |
| 6.7 | Continuation pages | A small running header (logo, number, "continued"), the table header repeated, "Carried forward" at the foot, and totals on the last page only. |
| — | Legal fields | Every legal field comes from tenant settings. Confirm the required invoice fields with an accountant and the JoFotara specification before shipping. |
| 6.8 | Document set | All four groups are in scope for stage 8. Sales: quote, invoice, receipt, credit note (the Tax invoice and the fiscal credit note, through JoFotara, are what Front Office adds; owner, 2 Nov 2026). Accounts: statement, payment reminder, delivery note. People: payslip, employment letter, leave approval. General: report, letter, purchase order. 80 mm thermal receipts come later. |

## Stage 7 · UI components (locked 28 Oct 2026)

### Inventory
- 56 components in 8 groups: Foundations, Actions, Inputs, Navigation, Data display, Feedback and layers, Agents, Platform.
- 41 are P1: what the Front Office demo, approvals and account flows need.
- Each component is marked as already in the design system, already in packages/ui (restyle), or new.
- The full list is on round 1 of stage 7 on the decision board. It is the stage 8 build order.

### Shared looks
| # | Decision | Locked value |
| --- | --- | --- |
| 7.1 | Secondary buttons | Outline: white with the 3:1 #929292 border. The main button is the only filled one and the only one with the plane. Ghost and destructive buttons are also available. |
| 7.2 | Inputs | Outlined, 1px #929292 on white, 36px tall, corner radius 9px. |
| 7.3 | Selected state | Tint: the derived tint (L 0.96) as the fill, with text in the main colour. Tabs are underlined in the main colour. No solid fill for the selected state. |
| 7.4 | Status badges | Tinted pills: the status tint as the background, with the status text colour. |

### Agent components and the data table
| # | Decision | Locked value |
| --- | --- | --- |
| 7.5a | Proposal | Summary first: one line saying what will happen and how much, two examples, then "Show all" for the details. It always shows the permissions used and the undo window. Approve, Edit and Decline. |
| 7.5b | Approvals inbox | One at a time: a queue of proposal cards ("1 of 4 waiting"). The user chose this over the recommended list with bulk approve. |
| 7.5c | Undo | A 10-second toast with Undo, then Undo stays on the activity item for the tool's undo window (for example 30 minutes for email). |
| 7.6 | Table selection | A checkbox column. When rows are selected, a bar rises at the foot showing the count, the money total and the actions. |
| — | Actor chip | Shown on everything an agent did or proposes: the role name, the robot face and the person it acts "for". People get initials; the system gets the mark. |
| — | Proposal states | Waiting: dashed and flat. Approved: solid, on the plane. Declined: grey with the title struck through. Expired: grey with "ask again". Undone: dashed again with "undone by …". |
| — | Scope grant | Off, Ask first or Allowed for each permission. An agent never exceeds the person who granted it, and changes are logged. |
| — | Agent run status | Running ("Reading 48 invoices"), Waiting for approval, Done, Failed (with what to do next). |
| — | Data table | 44px rows, sticky header, sort on click. A row opens a side drawer; the row menu sits at the end. Empty, loading (skeleton rows) and error states. A totals row for money columns. |

## Stage 8 · Files and templates (locked 28 Oct 2026)

### Built 28 Oct 2026
- Design system v2.0 draft published: logo files, tokens and components rebuilt from stages 1–7.
- `Doc` component (Whity Render): A4, A5 and US Letter pages to the stage 6 rules; either language leads; continuation pages (14 rows on page 1, 20 after, totals on the last page only, "Brought forward" and "Carried forward"); amount in words for JOD in English and Arabic; `--doc-accent`, the tenant's print colour, the same in light and dark mode.
- Sales documents: quote, tax invoice (English leads, Arabic leads, and a two-page version), credit note and A5 receipt. They tell one story: QUO-0318 → INV-1042 (JOD 301.600) → CN-0012 (JOD 98.600) → RCT-0207 (JOD 203.000).
- Accounts documents: statement of account (running balance, ageing), payment reminder (short letter, overdue invoices only, sent by the Billing assistant with approval) and delivery note (no prices, signed on receipt).
- People documents: payslip (earnings and deductions in separate columns, net pay in words, leave balance beside the totals), employment letter (Arabic leads, salary in figures and words, QR to verify) and leave approval (balance before and after, approval trail from the audit log).
- General documents: a two-page sales report (summary, key numbers, chart, tables, notes), a business letter and a purchase order.
- New parts: `DocPages` (letters and reports), `DocTable`, `DocKpis`, `DocBars`, and the `aside` slot beside the totals.
- The P1 components from the stage 7 inventory, built or upgraded: tenant logo slot, tooltip (on every icon button), text fields with counter and read-only, combobox, radio group and mixed checkbox, date and date range, money input, one-time code, search field, tenant switcher, language and theme switch, data table (selection, bulk bar with money total, sort, row menu, totals row, loading, empty and error), avatar, description list, timeline, toast with Undo, confirm with typed name, drawer, menu and popover, approvals queue, undo bar and document preview. The action proposal now shows two changes then "Show all", and the undo window.
- 10 icons added (87): sort arrows, chevron up, minus, zoom in and out, light, dark and system. A menu icon followed in stage 9 (88).

### Choices made while building (follow the locked rules; not separately decided)
- The JoFotara stamp is in ink, because 6.3 keeps the tenant colour on the title only.
- The stamp and QR appear on page 1 only; continuation pages carry the reference and page number.
- The quote has no stamp (not a tax document); its QR opens the quote online to accept it. The receipt has no stamp; its QR checks the receipt.
- Continuation pages open with a "Brought forward" row.
- Space between blocks is 5 mm on A4 and 3.5 mm on A5.
- Charts on paper are grey with the one bar that matters in ink, values on the bars and no legend, because 6.3 keeps the tenant colour on the title only. On screen, charts use the chart palette.
- Documents an agent made or sent say so in the footer, with the person it acted for (payment reminder, report, leave approval).
- HR documents (payslip, employment letter, leave approval) carry a QR to verify instead of a wet signature where the approval happened in Whity. The employment letter keeps a signature and stamp row because institutions expect one.
- Working days in examples run Sunday to Thursday.
- Calendars start on Saturday and grey Friday and Saturday as the weekend (the Jordan convention); both can be changed per tenant.
- Inputs accept Arabic-Indic digits and the Arabic decimal mark and store Western digits (3.7b).
- Tooltips appear after 500 ms on hover and at once on keyboard focus; they name things and never hold information needed to finish a task.
- Toasts sit at the bottom end corner. Errors and toasts that need action stay until dismissed.
- Agents never get a human face or photo: their avatar is the robot face on the tint. The system's avatar is the mark.
- Typing the name to confirm is kept for the biggest destructive actions (deleting a workspace, revoking every agent); anything that can be undone uses Undo instead of a confirm.
- The drawer is 480 px wide and covers the screen under 768 px.
- 700 weight removed from the tenant logo styles (stage 3: no 700 in the product).

### Still open in the inventory
- None for P1: the agent activity filter and the collapsed and mobile app shell were built in stage 9. P2 and P3 items stay planned (see section 17).

### To check before shipping
- The Arabic amount in words, with an Arabic-speaking accountant.
- The required invoice and credit note fields, against the JoFotara specification.

## Stage 9 · Final guideline (locked 28 Oct 2026)

### Published
- Design system v2.0 final: this register, the README and sections 01–17 (16 Documents and 17 Components new), every section rewritten from this register; 78 component, screen and document cards plus the cover; tokens, handoff files, logo files, social images and the motion clip.
- Brand guidelines PDF 2.0: 65 landscape A4 pages, built from the same sections, with the logo, colour, type, shape and document figures and real renders of the screens and documents.
- The cover: the mark's two planes at cover scale on the 24 grid (platform with the blueprint plus-grid, the sheet blank), a plane-deep block and an ink slab; the name in Readex Pro 600; "A blank sheet, on the platform beneath it."
- Social images redrawn with the v2 mark: the link preview (a sheet on the grey ground, Readex Pro headline, lockup bottom-start) and the avatar (the app icon tile, mark inside the inner 61%).
- The set-down motion clip re-recorded from the v2 components (proposal with its undo window).

### Fixes found while checking v1 content against the register
- Dark-mode control border (`input`) raised to oklch(0.52 0 0), 3.3:1 on the card, so every control border passes 3:1.
- Logo size tokens follow stage 1: horizontal lockup at least 20 px tall, the mark switches cut at 24 px, clear space 25% of the mark height (Flutter constants too).
- The Tailwind theme (`handoff/web/theme.css`) carries the stage 2–4 tokens: tint, status text and tint colours, divider, table header, chart 6, plane shadows, `shadow-float`, control radii, the display face, the plane lift and the blueprint grid.
- No 700 weight left in the component styles.
- Action proposal: the expired state (grey, "Ask again") and the undone state (dashed again, "Undone by …") added beside waiting, approved and declined.
- App shell: the 56 px rail with tooltips from 768 to 1023 px, and a drawer with a menu button under 768 px.
- Agent activity: filter by agent.
- Plan names and prices in cards replaced with placeholders, as stage 5 words require.
- The agent icon is the robot face everywhere (old wording removed from the Icon card and README).
- From the independent check against this register: the label on the main colour is pure white (#ffffff, 6.1:1, decision 2.1), not #fafafa; selected text on the dark tint is the main colour on dark (#59a0f9, 5.1:1; the partner's #e97c47, 4.9:1), decision 7.3; the 20px brand headline tracks −1%; the 700 font files and Geist Mono 600 are no longer declared; empty-state icons are 24px (5.2); menu items have 8px corners (¼ of 32); `Money` shows JOD with 3 decimals; the branding screen derives the whole tenant set from one colour (`Whity.tenantTheme`, decision 2.8); the stale v1 wording in the Button, Mark, Lockup, Icon, Actor chip, Badge, Tabs, Menu, Branding, Money and Page header READMEs was corrected.

### Values set while building (follow the locked rules)
- Large controls are 40px, for a single call to action; corners 10px (¼ rule).
- The stacked lockup's minimum height is 48px (the horizontal stays at 20px, 1.7c).
- Menu and popover items are 32px tall with 8px corners.
- "Bold" in documents (the grand total, 6.6) means weight 600, since 3.6 allows no 700.

### Rule for later changes
- Change this register first, then the files, then republish the guideline and bump its version (2.1, 2.2 …).

## Version 2.1 · P2 components (28 Oct 2026)
Stage 7's P2 items, built to the locked stage 7 rules. Asked and decided on 28 Oct 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| 2.1a | Dark chart graphite | #858585 (see 2.7). |
| 2.1b | Charts in Arabic | Time and values run left to right in both languages, like the numbers on them. Titles, legends and the table view follow the page direction. |
| 2.1c | Command palette | ⌘K (Ctrl K) searches records, pages and actions; the last row always asks an agent, dashed like every agent sheet, and the agent comes back with a proposal to approve. |
| 2.1d | Plan gate | A feature outside the plan shows greyed behind a card naming the plan. Owners get Upgrade and Compare plans; everyone else gets "Ask an owner". Never hidden. Plan names and prices stay placeholders. |

### Built
- Actions: button group, split button (one plane under the main pair), toggle group (pressed = the tint, 7.3).
- Inputs: phone input (+962 default, regional list, grouped as read, landlines 8 digits and mobiles 9 in Jordan, stored as E.164), file upload (dashed drop area, progress, done, error with Try again).
- Navigation: command palette, stepper (3–6 steps; "Step n of total" under 600px).
- Agents: agent run status (running with progress and Stop, waiting dashed with Review, done, failed with the next step and Try again; a compact pill).
- Platform: plan gate.
- Data: bar, line and donut charts on the 2.7 palette, series 1 the tenant's main colour; bars ≤24px with a 4px rounded end, 2px lines, 8px end dots with a 2px ring, one value labelled, a tooltip on every mark, "Show as table" on every chart, one axis only, a donut folds past six slices into "Other".
- Still planned: tag input and signature (P3).

## Document editor (version 2.2, locked 29 Oct 2026)
A Notion-, Gutenberg- and Google Docs–inspired editor for whity-core's Document designer (A33, `/admin/documents`). The design system designs it and defines its components (cards in every state, specs, the TypeScript contract for the document model and props, a TipTap/ProseMirror handoff); the Whity team builds the working editor. Decided one part at a time, starting 29 Oct 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| E.1 | Deliverable | Design-system definition: components, states, rules and contract. Implementation by the Whity team. |
| E.2 | Surface | Blocks (Notion/Gutenberg) on a continuous sheet, with an A4 page view (Google Docs) for what Whity Render prints. |
| E.3 | First version | Merge fields, comments and suggestions, rich blocks. Live collaboration (cursors, presence, version history) comes later. |
| E.4 | Agent edits | Agents write as suggestions: dashed, attributed ("Billing assistant for Rana"), nothing lands until a person accepts. |
| E.5 | Formatting tools | A fixed Google Docs–style toolbar, plus the selection bubble and the / menu. |
| E.6 | Comments and suggestions | Cards in a margin rail beside the text they belong to; a drawer list under 1024px. |
| E.7 | Default view | By document type: templates and anything Whity Render prints open in the page view; notes and wiki pages open continuous. Either can switch. |
| E.8 | Block chrome | Hovering any block shows + and a drag handle in the margin; selecting a table, image, callout or embed shows its own toolbar above it. |
| E.9 | Publishing | Templates have a draft and a published version: edits stay a draft until Publish (the one main action), and Whity Render and agents use the published version. Notes and wiki pages save live, with no Publish. |
| E.10 | Mode without edit permission | Suggesting: they propose changes and comment, and someone who can edit accepts, the same as agents. |
| E.11 | Dark mode | Papers (the A4 page view) stay white, like what prints; notes and wiki (the continuous sheet) follow the theme. |
| E.12 | Preview with a record | Fields show the record's values; comment highlights and suggestions are hidden so the pages look as they will print; the rail says so; read-only until Show fields. |
| E.13 | Block catalogue (v1) | Text, Heading 1–3, Quote, Code · Bulleted, Numbered, Checklist (3 levels) · Callout · Toggle, Columns (2), Divider, Page break · Table, Image (half, wide, full; caption), File · Record and Chart (live), Signature and stamp. |
| E.14 | Callouts | Two kinds: Note (grey) and Important (warning tint), so the success and error colours keep meaning state. |
| E.15 | Tables | Document tables: rows, columns, a header row, end-aligned numbers. Data stays in records and comes in as a Record or Chart block; no databases inside documents. |
| E.16 | Live values | Record and Chart blocks are live in the editor; each PDF freezes the values at the moment Whity Render made it. |
| E.17 | Plugin blocks: rendering | Plugins can add their own blocks. A Verified plugin is a signed declaration, drawn with the Whity SDK components by Whity's block renderer; publishers' own JavaScript never runs in the app. Other screens run in a sandboxed frame, sized by the editor. Security: `VERIFIED-PLUGINS-SECURITY.md`. |
| E.18 | Plugin blocks: printing | A plugin block needs a print view for Whity Render to be used in templates or anything that prints; without one it's available in notes and wiki pages only. |
| E.19 | Plugin blocks: plugin off | The block shows a placeholder ("Needs the Delivery Tracker plugin", with Enable for admins), its data stays in the document, and a template that contains it can't be published. |
| E.20 | Plugin blocks: inserting | A group per plugin in the / menu and + inserter, with its name and logo, after Whity's own blocks; searchable like the rest. |
| E.21 | Saved blocks: sync | People can select several blocks and save them as one block. When saving they choose Synced (editing it once updates every document that uses it) or Copy (inserts a copy people change freely). A synced block can be detached into a copy. |
| E.22 | Saved blocks: sharing | The creator picks Only me or Everyone in the workspace; owners and admins manage the workspace library. |
| E.23 | Saved blocks: per-document parts | In a synced block, the creator can mark text or fields as filled per document (a signer's name, a date); the rest stays synced. Merge fields work inside. |
| E.24 | Saved blocks: packaging | Workspace-only in v1; exporting a saved block as a plugin block comes with the plugin SDK work. |
| E.25 | Highlight | One highlight colour (the sky series at 40%), so colour never carries meaning in a document that may print in black and white. |
| E.26 | Merge field chips | The field's plain name on the tint ("Customer name"); the path (`customer.name`) on hover; one character for the caret. |
| E.27 | Missing values | Preview flags a missing value on the error tint. Whity Render leaves an optional field blank and won't issue a document with a required field missing; fiscal fields are required. |
| E.28 | Markdown shortcuts | On for everyone; ⌘Z straight after turns the shortcut back into what was typed. |
| E.29 | Accepting suggestions | Anyone who can edit the document accepts or rejects suggestions, including an agent's; each decision is logged with their name. |
| E.30 | @mentioning an agent | The agent replies in the thread; anything it changes in the document arrives as a suggestion. |
| E.31 | Agent context | When asked inside a document, an agent reads the document and the records its fields and blocks point to, within its own and the person's permissions. |
| E.32 | Unprompted agents | Agents suggest changes only when asked, or by a routine an owner sets up (for example, checking templates against new tax rules each month); always as suggestions. |
| E.33 | Body size on screen | 16px in the continuous sheet (a step up from the app's 14px); the page view uses stage 6's 9.5pt. Headings Noto Sans 600 at 24, 20 and 18px on screen and 14, 12 and 10.5pt on paper; the title alone is Readex Pro. |
| E.34 | Full width | A per-document Full width switch for wiki pages with wide tables; the 760px sheet (616px of text) is the default. |
| E.35 | Arabic document titles | Readex Pro 600 in its Arabic design, so 3.1 (document titles in Readex Pro) holds in both languages; the brand ships the Arabic subset (`readex-pro-arabic-600.woff2`). The Arabic invoice and every Arabic title follow. Product screens still never use Readex Pro. Asked and decided 29 Oct 2026. |
| E.36 | ⌘K in the editor | With text selected, ⌘K adds a link; with no selection it opens the command palette, as everywhere else (2.1c). Asked and decided 29 Oct 2026. |
| E.37 | Publish checks | Publishing a template is blocked by: open suggestions, a block whose plugin is off (E.19), a block without a print view (E.18), or a field the record type doesn't have. The check lists each with a way to fix it. |
| E.38 | Synced blocks in templates | Editing a synced saved block updates drafts, notes and wiki pages at once; a published template picks up the change at its next publish, so what prints never changes without a person publishing it. |
| E.39 | Comment highlight | Comments mark their text with the warning tint and a warning underline, on screen only; it never prints and isn't the highlight colour (E.25). |
| E.40 | Barcode block | A Whity block: Code 128, EAN-13 or Code 39, with the value typed or a merge field and the text under it optional. Ink only, sized in mm in the page view. Group "Whity" in the / menu. Added when the editor replaced the Document designer's canvas mode. Asked and decided 29 Oct 2026. |
| E.41 | QR code block | A Whity block: a value or a merge field as a QR, with an optional caption. Distinct from the document's verification QR in the footer (6.7), which stays automatic. Asked and decided 29 Oct 2026. |
| E.42 | Maths block | A block (and an inline `math` node) for formulas in TeX, rendered with KaTeX on screen and in print. Group "Basic". Asked and decided 29 Oct 2026. |
| E.43 | Label sheets | A document kind `label`: a sheet (A4, Letter) divided into a fixed grid of labels, each one the same blocks with fields; Print fills one label per record or per row of an uploaded CSV. It opens in the page view and never in the continuous view. Replaces the canvas mode's labels and certificates; a certificate is a one-label sheet. Asked and decided 29 Oct 2026. |
| E.44 | Document themes | A document's look comes from a theme: Whity's standard theme, a plugin's, or the workspace's own, which a document can override for the print colour and the letterhead only. No personal themes. A theme is a closed set of values (print colour, letterhead logo and lines, and the options in E.45-E.47), never CSS; everything else follows stage 6 and E.33. Inheritance: Whity standard, then a plugin theme, then the workspace theme, then the document. The print colour defaults to the workspace main colour (2.8). A published template keeps the theme it was published with until it's published again (as E.38). See `DOCUMENT-THEMES.md`. Asked and decided 31 Oct 2026. |
| E.45 | Accent on headings | A theme option, off by default: Heading 1-3 in the print colour as well as the title. This relaxes 6.3 ("title only") for themes that choose it; the owner's decision, against the recommendation to keep 6.3. Asked and decided 31 Oct 2026. |
| E.46 | Title face | A theme option: the document title in Readex Pro 600 (default, 3.1 and E.35) or in Noto Sans 600 (Noto Sans Arabic 600 in Arabic). Product screens still never use Readex Pro. Asked and decided 31 Oct 2026. |
| E.47 | Large print | A theme option: an 11pt body in print with headings scaled by the same steps as 6.5 and E.33; Arabic stays at 100% of the Latin size (3.7a). Asked and decided 31 Oct 2026. |

Parts: 1 shell (locked 29 Oct, cards EditorShell) · 2 canvas (locked 29 Oct, cards EditorShell, EditorPageView) · 3 blocks (locked 29 Oct, cards EditorBlocks, EditorBlockChrome) · 3b plugin and saved blocks (locked 29 Oct, card EditorCustomBlocks) · 4 inline (locked 29 Oct, card EditorInline) · 5 inserting (locked 29 Oct, card EditorInsert) · 6 collaboration (locked 29 Oct, card EditorCollab) · 7 agents (locked 29 Oct, card EditorAgents) · 8 typography (locked 29 Oct, card EditorType). All parts locked 29 Oct 2026; compiled into version 2.2. E.40–E.43 added on 29 Oct 2026 with the engineering survey (M0), when the editor was chosen to replace both modes of the existing Document designer; they have no cards yet.

## Plugin store (version 2.3, locked 31 Oct 2026)
The pages where a workspace finds, installs and manages plugins (A41). Guideline section 19; cards in "Templates · Plugin store".

| # | Decision | Locked value |
| --- | --- | --- |
| S.1 | Where | In the app first (A41, `/admin/plugins/store`). A workspace installs declarative plugins into that workspace. A code plugin is install-wide, for the whole deployment, and only an operator installs it. A public marketplace site reuses the same pages later. |
| S.2 | Billing | Paid plugins are billed through Whity: one invoice from Whity, which takes the marketplace cut and pays publishers. Prices show as `[price]` until decided. |
| S.3 | Who installs | Declarative plugins: anyone in the workspace whose role has `plugins:install` (S.9). Code plugins: operators only, from the system tenant. Agents can't install directly; with "Ask first" it becomes a proposal like any other action (7.5). |
| S.4 | Trust | Verified and By Whity badges, the permissions a plugin asks for, "used by N workspaces" and its last update. Everything else is labelled Not verified, with a plain warning that its code runs on the server once it's on. A code plugin whose exact version the Whity store reviewed is labelled Verified too, with "Reviewed on app.whity.dev on [date]; its code runs on your server" (8 Nov 2026, VERIFIED-PLUGINS-SECURITY.md 4.1). No ratings or reviews until there are enough installs for them to mean something. |
| S.5 | Agent tools on install | Every tool a plugin adds starts on Ask first; read-only tools may start Allowed. An admin changes modes afterwards in MCP tools, like any other tool. |
| S.6 | Updates | A Verified declarative plugin's update installs automatically; one that asks for new permissions or new outside domains waits for an admin's approval, and the old version keeps running meanwhile. A code plugin only ever updates by hand. Every update is in the audit log. |
| S.7 | Uninstall | The plugin's data is kept 30 days; reinstalling in that time restores everything, then it is deleted. Its blocks show the plugin-off placeholder (E.20) meanwhile. |
| S.8 | Trials | A paid plugin may offer a free trial; the publisher sets the length. Billing starts when it ends, with a reminder before it does. |
| S.9 | Install and upload | Two permissions: `plugins:install` installs from the store (declarative plugins in a workspace; code plugins only in the system tenant, S.3); `plugins:upload` (operators) uploads a .zip of unreviewed code. `plugins:read` sees the pages. Decided 2 Nov 2026 against the built app; narrowed 3 Nov. |
| S.10 | Sidebar | One sidebar item, Plugins. Its page has two tabs, Store and Installed, which keep their own routes (`/admin/plugins/store`, `/admin/plugins`). |
| S.11 | Operator tools | Upload plugin and Reload plugins sit in a ⋯ menu on the Installed tab, for `plugins:upload`. Store sources and their access tokens move to Settings › Advanced. A failed plugin's error and stack trace are in its details drawer, collapsed. |
| S.12 | State after install | Verified declarative and By Whity plugins are active once installed (the Access step is the consent). Anything Not verified installs turned off, with Turn on on the Done step. |

Values set while drawing, open to change:
- Third-party plugins are named for their job, "[Job] for Whity" or a plain name. Names starting with "Whity" are kept for first-party products.
- Trust marks: By Whity in the primary colour, Verified in the success colour, Sandboxed in muted grey with a lock. "Adds" chips on every tile: Screens, Document blocks, Agent tools (primary tint), Fields, Connects out.
- Install is one dialog with a Stepper: Access, Plan (paid only), Set up (only if the plugin declares settings), Done. Outside domains always show in a warning row.
- Installed states: Active, Trial, Update waiting, Failed, Turned off; uninstalled plugins sit under "Recently removed" for the 30 days.

## Home (version 2.4, locked 2 Nov 2026)
The page people land on after signing in, replacing the `/dashboard` placeholder and the Dashboard link to server statistics. Decided 2 Nov 2026, from the Whity team's inventory of the built app:

| # | Decision | Locked value |
| --- | --- | --- |
| H.1 | One home | Everyone lands on `/dashboard`, the sidebar's Dashboard. It is built per person: it shows only the cards for what that person can see and do. System-tenant operators also get the platform cards of the admin dashboard. |
| H.2 | What leads | **Waiting for you**: one list merging Inbox documents and agent Approvals, oldest first, with the decision buttons inline. |
| H.3 | Server statistics | `/admin/stats` becomes **System**, under Platform, for system-tenant operators only. Its numbers also feed the operators' cards on the home. |
| H.4 | Cards | Installed apps can each add one card to the home. People can hide cards but not rearrange them (v1). |

Values set while drawing, open to change:
- The title greets the person by first name with the time of day; the description gives the date, the workspace and their branch.
- Waiting for you shows at most six rows, then "and N more". Decisions are inline outline buttons (Approve beside a ghost Decline or Reject; Open when the item needs reading), so the home has no filled main button. When nothing waits, it collapses to one line.
- Owners and admins see "Finish setting up" until it's done: logo and colour, team, an agent, the tax number (invoices need it) and a plan.
- App cards: the app's logo and name, one number, a few lines, at most one chart; ⋯ has Hide from home and Open; hidden cards are listed at the foot with Show. A card shows only the person's scope.

## Finishing the app (versions 2.5–2.9, planned 2 Nov 2026)
Every screen in the Whity team's web app inventory (develop at `01db4bcd`, 1 Nov 2026) gets a design by 2.9.

| # | Decision | Locked value |
| --- | --- | --- |
| V.1 | Order | 2.5 page patterns and People · 2.6 Forms · 2.7 Documents and routing · 2.8 AI and agents · 2.9 Workspace, billing and settings. The inventory's problems (raw ids, missing confirmations, untranslated strings, kit violations) are fixed in the version that draws each screen. |
| V.2 | Depth | Each screen is drawn in its main state. Loading, empty, error, read-only and access denied are drawn once as patterns (2.5), and each screen's README names its own versions of them. |
| V.3 | Arabic | One Arabic card per version, the busiest screen mirrored; every README lists the Arabic labels. |
| V.4 | Delivery | Register, cards and sections are published every version; the guidelines PDF and the handoff zip are rebuilt once, at 2.9. |

## 2.5 Page patterns and People (locked 2 Nov 2026)
Patterns every later version reuses, then the People screens. Decided 2 Nov 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| P.1 | Row click | Things with history and settings (people, roles, workspaces) open a full record page with its own address. Small things (a group, a delegation, a tag, a unit) open a side drawer. |
| P.2 | Record page | A header with the name, status and key facts, then tabs: Overview, Access, History. |
| P.3 | Editing | Fields are edited where they are. A sticky bar with Discard and Save changes appears as soon as something changes, and leaving asks first. |
| P.4 | Confirming | By how undoable it is. Undoable: it happens at once with Undo. Hard to undo: a dialog that names the consequence. Security-sensitive or irreversible: the dialog plus typing the name. |

Values set while drawing, open to change:
- The sidebar follows the built app's groups in sentence case; only the current group is open, the others fold to their label (`AppShell` nav groups take `collapsed`).
- People are always names with avatars, never ids; units, roles and dates are words. History is written in sentences, never event codes.
- A changed field gets the primary ring and a "Was …" note; the save bar names the changes.
- Filter buttons tint and name their value when set. Status lists use a segmented switch.
- Inbox states are words the app composes and translates: Your decision, To review, For your information, You approved, You rejected, Returned to you.
- A role's Access tab is a grid of areas by See, Create, Change, Delete, Approve; built-in roles are read-only with Duplicate to change.

## 2.6 Forms (locked 2 Nov 2026)
Form Builder, the forms catalogue, filling in, the public form and My submissions (A34). Decided 2 Nov 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| F.1 | Builder | The form as it will look, with questions edited in place on a canvas; the selected question's settings (type, required, help, logic, who sees answers) in a side panel. |
| F.2 | Filling | Short forms on one page. A form with sections shows them as steps with a Stepper, saving a draft as people go. |
| F.3 | Public form | The workspace's brand leads (logo and colour); a small "Built on Whity" in the footer. |
| F.4 | Responses | Each form has Questions, Responses and Settings tabs. Responses shows a summary per question, then a table; a response opens as a record with its routing. |

Values set while drawing, open to change:
- Sections carry a bar in the main colour; a selected question gets the primary ring and a small toolbar (move, duplicate, delete); conditional questions show an "Only if …" pill.
- The settings panel includes "Save the answer to", mapping an answer to a field on a record.
- Prefilled answers show as a summary from the person's profile, not as fields to retype. Drafts save on the server.
- The Forms page shows cards, drafts first under "Continue where you left off". My submissions shows where each submission is.
- Public form states: sent (with a reference), closed (with a way to reach the workspace), not available. Buttons name the action ("Send application").

## Ease of use (version 2.7, locked 2 Nov 2026)
Company infrastructure and management screens are used mostly by people over 50. These override earlier values where they differ.

| # | Decision | Locked value |
| --- | --- | --- |
| A.1 | Size | Product text 15px (was 14px, 3.5a); nothing below 13px (was 11px); controls 40px (small 32, large 44; was 36, 28, 40); table rows 48px (was 44); control corners stay ¼ of height (10px). Printed documents and the editor sheet are unchanged. |
| A.2 | Actions | Common actions are visible, labelled buttons. Icon-only buttons always have a text label on hover and focus. ⋯ holds only rare or destructive actions. |
| A.3 | Plain words | Management screens speak in plain words. Permission codes, ids and stack traces appear only when the person turns on **Show technical details** (a personal setting, also on the page where codes matter). |
| A.4 | Applies to | Everything designed so far is updated (home, store, 2.5, 2.6, components) and every screen from 2.7 on follows it. |

## 2.7 Documents and routing (locked 2 Nov 2026)
The library and document record, templates, notes and wiki, route templates and the flow editor, and circulation (A30–A32). Decided 2 Nov 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| D.1 | Areas | Library, Templates, Route templates and Notes and wiki stay separate sidebar items, each its own page. |
| D.2 | Flow editor | A top-down flow: stages stacked with arrows; branches (approved, rejected) side by side under their stage; the selected stage's settings in an inspector beside it. |
| D.3 | Circulation | A tab on the document record. Record tabs: Document, Routing, History, Verification. `/admin/document-routing/[id]` redirects to the Routing tab. |
| D.4 | Library | A rail of saved-search folders (Created by me, Awaiting me, Starred, collections and the rest) beside a list or grid, as built. |

Values set while drawing, open to change:
- Route stages: done green with a check, current in the main colour with a ring, next grey; each shows the people reached, the rule and the time.
- Your decision: an optional note seen by everyone on the route, then Approve (main), Reject, Return to the sender, Forward to someone else, and one sentence on what happens next.
- Routes are described in one sentence in the route list. Flow editor branches are labelled on their arrows; the inspector shows how many people a stage reaches today.
- A document's SHA-256 checksum appears only with Show technical details (A.3).

## 2.8 AI and agents (locked 2 Nov 2026)
Whity's own agents, outside AI apps connected to Whity, and what AI can do (A20–A23). Decided 2 Nov 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| AI.1 | Naming | **Agents** are Whity's own agents. **Connected AI apps** (was AI principals) are outside apps (Claude, Cursor, ChatGPT and others) signed in to Whity. The sidebar's AI group: Agents, Connected AI apps, What AI can do. |
| AI.2 | New agent | Start from a preset: a gallery of ready agents (Billing assistant, Stock assistant and others); pick one, adjust it, done. Blank is the last card. |
| AI.3 | Agent page | A record page (P.2) with tabs: Overview (what it does and for whom), Instructions, What it may do (Off, Ask first, Allowed), Activity. |
| AI.4 | What AI can do | The MCP tools page becomes **What AI can do**: actions in plain words, grouped by area, with how agents may use each. Tool names, toolsets and token counts appear only with Show technical details (A.3). |

Values set while drawing, open to change:
- The agents list shows "On its own" as counts of Allowed and Ask first actions. Tabs: My agents, Everyone's agents (admins).
- Presets show what each is for, three things it does, and what it needs (an app, or "Works with Whity alone").
- Instructions are a plain text box written as to a new colleague; "What it may read" lists pages and templates; tone and language are choices.
- Connect an AI app: three steps (app, what it may do, set up) with the app's own steps, Copy address, and a dashed waiting line; no filled button on the last step.
- What AI can do columns: Action, Area, Agents (can do it, proposes, suggests only, never), If it goes wrong.

## 2.9 Workspace, billing and settings (locked 2 Nov 2026)
The Settings area, a person's account, billing, the audit log, and the operators' Platform pages (A40–A53, U04). Decided 2 Nov 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| W.1 | Settings | One Settings entry in the sidebar; each settings page has a grouped sub-nav beside it (Workspace, Sign-in and security, Email, Data, Advanced) and keeps its own address, as built. |
| W.2 | Operators | Install-wide pages (Tenants, Plans and pricing, Promotions, System, Errors, Notification delivery, and the install's email, storage, feature flags and languages) live in the sidebar's **Platform** group, shown only to the system tenant. Workspace admins never see them. |
| W.3 | Account | A person's own settings in tabs: Profile, Sign-in and security, Sessions and devices, Preferences (language, theme, **text size** Standard or Larger, Show technical details). |
| W.4 | Audit log | Sentences grouped by day ("Rana Haddad changed Omar Khalil's unit to Abdoun"), with filters for person, agent, area and date; raw details behind Show technical details. |

Values set while drawing, open to change:
- The settings sub-nav adds a Devices group (Licensed devices), which had no link in the built app.
- Tags and tag groups share one page. Translations shows coverage tiles and "Only missing Arabic" by default.
- Text size Larger is 17px, every size one step up.
- Prices and discounts are typed with MoneyInput in normal units with the currency's decimals; Promotions default to JOD.
- System refreshes every minute and says so. Errors lead with plain words; technical details on request.
- Platform settings: install-wide switches as sentences; Email, File storage, Languages and Error tracking as tabs.

## Carried forward, to be re-confirmed in their own stage
- The name is "Whity", with a capital W, in Latin letters only. There is no Arabic name, logo or brand copy.
